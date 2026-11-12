<?php

declare(strict_types=1);

namespace EZSLab\Service;

use Symfony\Component\Process\Process;

/** Local admission only. One analysis_jobs queue; the scientific executor stays in LAB. */
final class LocalProfileQueue
{
    public function __construct(
        private readonly Database $db,
        private readonly WorkbenchCatalog $catalog,
        private readonly AnalysisRunRegistry $runs,
    ) {}

    /** @return array{job_id:int,run_id:int,created:bool} */
    public function enqueue(int $songId): array
    {
        $song = $this->catalog->selectedSong($songId);
        if (!$song || !in_array($songId, array_map('intval', $song['song_ids'] ?? [(int)$song['id']]), true)) {
            throw new \DomainException('profile_song_missing');
        }
        $source = $this->catalog->sourcePath($song);
        if ($source === null || !is_file($source)) {
            throw new \DomainException('profile_source_missing');
        }
        $pdo = $this->db->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $songIds = array_map('intval', $song['song_ids'] ?? [(int)$song['id']]);
            $marks = implode(',', array_fill(0, count($songIds), '?'));
            $stmt = $pdo->prepare("SELECT id,scientific_run_id FROM analysis_jobs
                WHERE song_id IN ($marks) AND kind='profile' AND dispatch_owner='lab'
                AND status IN ('queued','running','cancelling')
                ORDER BY id DESC LIMIT 1");
            $stmt->execute($songIds);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($existing) {
                $pdo->commit();
                return ['job_id'=>(int)$existing['id'], 'run_id'=>(int)$existing['scientific_run_id'], 'created'=>false];
            }
            $run = $this->runs->createRun((int)$song['id'], 'profile', [
                'name'=>'ezstudio-profile-modules',
                'version'=>'r3b31',
                'model'=>'multi-engine-profile-modules',
            ], [
                'audio_sha256'=>(string)$song['audio_sha256'],
                'output_contract'=>'ezstudio.profile.v1',
                'automatic_next_stage'=>false,
            ]);
            $runId = (int)$run['id'];
            $hash = (string)$song['audio_sha256'];
            $storage = rtrim((string)(getenv('EZSTUDIO_STORAGE_ROOT') ?: dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'storage'), '\\/');
            $output = $storage.DIRECTORY_SEPARATOR.'profile'.DIRECTORY_SEPARATOR.$hash.DIRECTORY_SEPARATOR.sprintf('run-%06d', $runId);
            $request = [
                'scientific_run_id'=>$runId,
                'audio_hash'=>$hash,
                'output_dir'=>$output,
                'output_path'=>$output.DIRECTORY_SEPARATOR.'profile.json',
                'output_contract'=>'ezstudio.profile.v1',
                'automatic_next_stage'=>false,
            ];
            $now = gmdate('c');
            $stmt = $pdo->prepare('INSERT INTO analysis_jobs
                (run_id,scientific_run_id,song_id,source_path,kind,status,progress,request_json,error,created_at,updated_at,dispatch_owner)
                VALUES(NULL,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$runId,(int)$song['id'],$source,'profile','queued',0,
                json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                null,$now,$now,'lab']);
            $jobId = (int)$pdo->lastInsertId();
            $this->runs->setAlias($runId, 'analysis_job', (string)$jobId);
            $this->runs->setLabel($runId, 'analysis_job_id', (string)$jobId);
            $this->runs->setLabel($runId, 'automatic_next_stage', 'false');
            $this->runs->setLabel($runId, 'progress', '0');
            $this->runs->setState($runId, 'queued');
            $pdo->commit();
            return ['job_id'=>$jobId,'run_id'=>$runId,'created'=>true];
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** One durable FIFO ordering across PROFILE and Assistant. Other LAB phases remain untouched. */
    public static function headSql(): string
    {
        return "SELECT id,kind,scientific_run_id FROM analysis_jobs
            WHERE status='queued' AND dispatch_owner='lab'
            AND kind IN ('profile','assistant.profile.review')
            ORDER BY created_at ASC,id ASC LIMIT 1";
    }

    public static function activeSql(): string
    {
        return "SELECT COUNT(*) FROM analysis_jobs WHERE status IN ('running','cancelling')
            AND dispatch_owner='lab'
            AND kind IN ('profile','assistant.profile.review')";
    }

    public function head(): ?array
    {
        $row = $this->db->pdo()->query(self::headSql())->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function hasActive(): bool
    {
        return (int)$this->db->pdo()->query(self::activeSql())->fetchColumn() > 0;
    }

    /** Start exactly one detached drain request. Process-scoped lock enforces one consumer. */
    public function wake(): void
    {
        $root = dirname(__DIR__, 2);
        $dir = $root.'/var/logs/profile-queue';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('profile_queue_log_unavailable');
        }
        $stamp = gmdate('Ymd_His').'_'.bin2hex(random_bytes(4));
        $out = str_replace('\\', '/', $dir.'/'.$stamp.'.out.log');
        $err = str_replace('\\', '/', $dir.'/'.$stamp.'.err.log');
        $script = '$ErrorActionPreference = "Stop"; '
            .'$php = (Get-Command php.exe -ErrorAction Stop).Source; '
            .'$p = Start-Process -FilePath $php '
            .'-ArgumentList "bin/console lab:profile:drain" '
            .'-WorkingDirectory "'.str_replace('\\', '/', $root).'" '
            .'-RedirectStandardOutput "'.$out.'" '
            .'-RedirectStandardError "'.$err.'" '
            .'-PassThru; '
            .'if (!$p -or $p.Id -lt 1) { throw "profile_drain_spawn_failed" }; '
            .'Write-Output ("PROFILE_DRAIN_SPAWNED_PID=" + $p.Id)';
        $process = new Process(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-Command',$script], $root);
        $process->setTimeout(20);
        $process->run();
        if (!$process->isSuccessful() || !str_contains($process->getOutput(), 'PROFILE_DRAIN_SPAWNED_PID=')) {
            throw new \RuntimeException('profile_drain_spawn_failed');
        }
    }
}
