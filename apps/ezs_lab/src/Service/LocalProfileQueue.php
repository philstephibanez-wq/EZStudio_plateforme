<?php

declare(strict_types=1);

namespace EZSLab\Service;

use App\Service\OrchestratorQueueClient;

/** Local admission + REST dispatch to central Orchestrator queue. */
final class LocalProfileQueue
{
    public function __construct(
        private readonly Database $db,
        private readonly WorkbenchCatalog $catalog,
        private readonly AnalysisRunRegistry $runs,
        private readonly OrchestratorQueueClient $orchestratorQueue,
    ) {}

    /** @return array{job_id:int,run_id:int,queue_id:?int,created:bool} */
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
                return [
                    'job_id' => (int)$existing['id'],
                    'run_id' => (int)$existing['scientific_run_id'],
                    'queue_id' => null,
                    'created' => false,
                ];
            }
            $run = $this->runs->createRun((int)$song['id'], 'profile', [
                'name' => 'ezstudio-profile-modules',
                'version' => 'r3b31',
                'model' => 'multi-engine-profile-modules',
            ], [
                'audio_sha256' => (string)$song['audio_sha256'],
                'output_contract' => 'ezstudio.profile.v1',
                'automatic_next_stage' => false,
            ]);
            $runId = (int)$run['id'];
            $hash = (string)$song['audio_sha256'];
            $storage = rtrim((string)(getenv('EZSTUDIO_STORAGE_ROOT') ?: dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'storage'), '\\/');
            $output = $storage.DIRECTORY_SEPARATOR.'profile'.DIRECTORY_SEPARATOR.$hash.DIRECTORY_SEPARATOR.sprintf('run-%06d', $runId);
            $request = [
                'scientific_run_id' => $runId,
                'audio_hash' => $hash,
                'output_dir' => $output,
                'output_path' => $output.DIRECTORY_SEPARATOR.'profile.json',
                'output_contract' => 'ezstudio.profile.v1',
                'automatic_next_stage' => false,
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

            // Dispatch REST vers la file centralisée d'orchestrator
            $dispatched = $this->orchestratorQueue->dispatch('lab', [
                'job' => 'profile',
                'category' => 'Analyse',
                'label' => trim((string)($song['title'] ?? '')),
                'analysis_job_id' => $jobId,
                'scientific_run_id' => $runId,
                'song_id' => (int)$song['id'],
                'source_path' => $source,
                'request' => $request,
            ]);

            return [
                'job_id' => $jobId,
                'run_id' => $runId,
                'queue_id' => (int)($dispatched['queue_id'] ?? 0),
                'created' => true,
            ];
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function cancelInOrchestrator(array $job): void
    {
        $runId = (int)($job['scientific_run_id'] ?? 0);
        $queueId = 0;
        if ($runId > 0) {
            $labels = $this->runs->labels($runId);
            $queueId = (int)($labels['orchestrator_queue_id'] ?? 0);
        }
        if ($queueId <= 0) {
            $analysisJobId = (int)($job['id'] ?? 0);
            $orchDb = dirname(__DIR__, 4) . '/data/orchestrator.sqlite';
            if ($analysisJobId > 0 && is_file($orchDb)) {
                try {
                    $uri = 'file:' . str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], str_replace('\\', '/', $orchDb)) . '?mode=ro';
                    $pdo = new \PDO('sqlite:' . $uri);
                    $stmt = $pdo->prepare("SELECT id FROM job_queue WHERE source = 'lab' AND status IN ('waiting', 'progressing') AND json_extract(payload, '$.analysis_job_id') = :jid ORDER BY id DESC LIMIT 1");
                    $stmt->execute([':jid' => $analysisJobId]);
                    $queueId = (int)$stmt->fetchColumn();
                } catch (\Throwable) {}
            }
        }
        if ($queueId > 0) {
            try {
                $this->orchestratorQueue->cancel($queueId);
            } catch (\Throwable) {}
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
}
