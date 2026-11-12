<?php

namespace EZSLab\Service;

final class ProfileExecutionService
{
    public function __construct(
        private readonly Database $db,
        private readonly AnalysisRunRegistry $runRegistry,
        private readonly PresetRegistry $presets,
    ) {}

    /** Direct and synchronous. Creates a scientific job as RUNNING, never as queued. */
    public function beginOneShot(int $scientificRunId, string $sourcePath, string $audioHash): array
    {
        $run = $this->runRegistry->run($scientificRunId);
        if (!$run || (string)$run['item'] !== 'profile' || (string)$run['state'] !== 'created') {
            throw new \InvalidArgumentException('profile_run_not_startable');
        }
        if (!is_file($sourcePath)) throw new \RuntimeException('profile_source_missing');
        $runtimeRoot = rtrim((string)(getenv('EZSTUDIO_STORAGE_ROOT') ?: dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'storage'), '\\/');
        $outputDir = $runtimeRoot.DIRECTORY_SEPARATOR.'profile'.DIRECTORY_SEPARATOR.$audioHash.DIRECTORY_SEPARATOR.sprintf('run-%06d', $scientificRunId);
        $request = [
            'scientific_run_id' => $scientificRunId,
            'audio_hash' => $audioHash,
            'output_dir' => $outputDir,
            'output_path' => $outputDir.DIRECTORY_SEPARATOR.'profile.json',
            'output_contract' => 'ezstudio.profile.v1',
            'automatic_next_stage' => false,
        ];
        $now = gmdate('c');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO analysis_jobs(run_id,scientific_run_id,song_id,source_path,kind,status,progress,request_json,error,created_at,updated_at,dispatch_owner)
             VALUES(NULL,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $scientificRunId, (int)$run['song_id'], $sourcePath, 'profile', 'running', 1,
            json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            null, $now, $now, 'lab',
        ]);
        $jobId = (int)$this->db->pdo()->lastInsertId();
        $this->runRegistry->setAlias($scientificRunId, 'analysis_job', (string)$jobId);
        $this->runRegistry->setLabel($scientificRunId, 'analysis_job_id', (string)$jobId);
        $this->runRegistry->setLabel($scientificRunId, 'automatic_next_stage', 'false');
        $this->runRegistry->setLabel($scientificRunId, 'progress', '1');
        $this->runRegistry->setState($scientificRunId, 'running');
        return ['scientific_run_id'=>$scientificRunId,'job_id'=>$jobId];
    }

    public function jobForRun(int $scientificRunId): ?array
    {
        $stmt = $this->db->pdo()->prepare("SELECT * FROM analysis_jobs WHERE scientific_run_id=? AND kind='profile' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$scientificRunId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function progressFromJob(int $jobId, int $percent): void
    {
        $job = $this->db->analysisJob($jobId);
        if (!$job || (string)$job['kind'] !== 'profile') return;
        $runId = (int)($job['scientific_run_id'] ?? 0);
        if ($runId <= 0) return;
        $this->runRegistry->setLabel($runId, 'progress', (string)max(0, min(100, $percent)));
        if ($percent > 0 && $percent < 100) $this->runRegistry->setState($runId, 'running');
    }

    public function completeFromJob(int $jobId): void
    {
        $job = $this->db->analysisJob($jobId);
        if (!$job || (string)$job['kind'] !== 'profile') return;
        $runId = (int)($job['scientific_run_id'] ?? 0);
        if ($runId <= 0) throw new \RuntimeException('profile_scientific_run_missing');

        $request = json_decode((string)($job['request_json'] ?? ''), true);
        if (!is_array($request)) throw new \RuntimeException('profile_request_invalid');
        $outputPath = trim((string)($request['output_path'] ?? ''));
        if ($outputPath === '' || !is_file($outputPath)) throw new \RuntimeException('profile_output_missing');

        $raw = file_get_contents($outputPath);
        $result = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($result)) throw new \RuntimeException('profile_output_invalid');
        $sha = hash_file('sha256', $outputPath);
        if (!is_string($sha) || $sha === '') throw new \RuntimeException('profile_output_hash_failed');

        $this->runRegistry->registerArtifact($runId, 'profile_json', $outputPath, $sha, [
            'analysis_job_id' => $jobId,
            'schema' => $result['schema'] ?? 'ezstudio.profile.v1',
            'audio_sha256' => $result['audio_sha256'] ?? '',
        ], 'PROFILE JSON');

        $presetManifest = $result['preset'] ?? $result['preset'] ?? null;
        if (!is_array($presetManifest)) {
            throw new \RuntimeException('profile_preset_manifest_missing');
        }
        $phaseRevision = $this->presets->captureRunPreset(
            $runId,
            'profile',
            $presetManifest,
        );

        $metrics = is_array($result['characteristics'] ?? null) ? $result['characteristics'] : [];
        $diagnostics = ['tagging' => $result['tagging'] ?? [], 'profile_view' => $result['profile_view'] ?? [], 'module_registry' => $result['module_registry'] ?? $result['module_registry'] ?? [], 'warnings' => $result['warnings'] ?? []];
        $environment = is_array($result['environment'] ?? null) ? $result['environment'] : [];
        $this->runRegistry->setLabel(
            $runId,
            'preset_phase_revision',
            (string)$phaseRevision['revision_ref']
        );
        $this->runRegistry->setLabel(
            $runId,
            'preset_phase_fingerprint',
            (string)$phaseRevision['fingerprint']
        );
        $this->runRegistry->setLabel($runId, 'progress', '100');
        $this->runRegistry->setLabel($runId, 'automatic_next_stage', 'false');
        $this->runRegistry->setState($runId, 'done', $metrics, $diagnostics, $environment, gmdate('c'));
    }

    public function failFromJob(int $jobId, string $error): void
    {
        $job = $this->db->analysisJob($jobId);
        if (!$job || (string)$job['kind'] !== 'profile') return;
        $runId = (int)($job['scientific_run_id'] ?? 0);
        if ($runId <= 0) return;
        $this->runRegistry->setLabel($runId, 'last_error', $error);
        $this->runRegistry->setState($runId, 'error', [], ['error' => $error], [], gmdate('c'));
    }
}
