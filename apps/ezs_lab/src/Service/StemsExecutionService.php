<?php

namespace EZSLab\Service;

use App\Service\OrchestratorQueueClient;

final class StemsExecutionService
{
    private const STEM_NAMES = [
        'lead_vocals',
        'backing_vocals',
        'drums',
        'bass',
        'guitar',
        'piano',
        'other',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly AnalysisRunRegistry $runRegistry,
        private readonly OrchestratorQueueClient $orchestratorQueue,
    ) {}

    public function queue(
        int $scientificRunId,
        string $source,
        string $audioHash,
        bool $force,
        string $engineProfile,
    ): array {
        $run = $this->runRegistry->run($scientificRunId);
        if (!$run
            || (string)$run['item'] !== 'stems'
            || !in_array((string)$run['state'], ['created','configured','error'], true)
        ) {
            throw new \InvalidArgumentException('stems_run_not_queueable');
        }
        if (!is_file($source)) {
            throw new \RuntimeException('stems_source_missing');
        }

        $previousJobId = null;
        $previous = $this->db->analysisJobsForSongKind((int)$run['song_id'], 'stems');
        if ($previous !== []) {
            $previousJobId = (int)$previous[0]['id'];
        }

        $storageRoot = rtrim(
            (string)(getenv('EZSTUDIO_STORAGE_ROOT') ?: dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'storage'),
            '\\/'
        ).DIRECTORY_SEPARATOR.'stems'.DIRECTORY_SEPARATOR.$audioHash;

        $jobId = $this->db->queueScientificStemsJob(
            $scientificRunId,
            (int)$run['song_id'],
            $source,
            $audioHash,
            $storageRoot,
            $force,
            $engineProfile,
            $previousJobId,
        );

        $this->runRegistry->setAlias($scientificRunId, 'analysis_job', (string)$jobId);
        $this->runRegistry->setAlias($scientificRunId, 'lab_job', (string)$jobId);
        $this->runRegistry->setLabel($scientificRunId, 'audio_sha256', $audioHash);
        $this->runRegistry->setLabel($scientificRunId, 'technical_job_id', (string)$jobId);
        $this->runRegistry->setState($scientificRunId, 'queued');

        $dispatched = $this->orchestratorQueue->dispatch('lab', [
            'job' => 'stems',
            'analysis_job_id' => $jobId,
            'scientific_run_id' => $scientificRunId,
            'song_id' => (int) $run['song_id'],
            'source_path' => $source,
            'audio_hash' => $audioHash,
            'storage_root' => $storageRoot,
            'force' => $force,
            'engine_profile' => $engineProfile,
            'previous_job_id' => $previousJobId,
        ]);

        $dispatched = $this->orchestratorQueue->dispatch('lab', [
            'job' => 'stems',
            'analysis_job_id' => $jobId,
            'scientific_run_id' => $scientificRunId,
            'song_id' => (int) $run['song_id'],
            'source_path' => $source,
            'audio_hash' => $audioHash,
            'storage_root' => $storageRoot,
            'force' => $force,
            'engine_profile' => $engineProfile,
            'previous_job_id' => $previousJobId,
        ]);

        return [
            'scientific_run_id' => $scientificRunId,
            'job_id' => $jobId,
            'queue_id' => (int) ($dispatched['queue_id'] ?? 0),
            'queue_id' => (int) ($dispatched['queue_id'] ?? 0),
        ];
    }

    public function jobsForSong(int $songId): array
    {
        $rows = $this->db->analysisJobsForSongKind($songId, 'stems');
        return array_map(fn(array $row): array => $this->toView($row), $rows);
    }

    public function jobView(int $jobId): ?array
    {
        $row = $this->db->analysisJob($jobId);
        if (!$row || (string)$row['kind'] !== 'stems') {
            return null;
        }
        return $this->toView($row);
    }

    public function progressFromJob(int $jobId, int $percent): void
    {
        $job = $this->db->analysisJob($jobId);
        if (!$job || (string)$job['kind'] !== 'stems') {
            return;
        }
        $scientificRunId = (int)($job['scientific_run_id'] ?? 0);
        if ($scientificRunId <= 0) {
            return;
        }

        $this->runRegistry->setLabel($scientificRunId, 'progress', (string)max(0, min(100, $percent)));
        if ($percent > 0 && $percent < 100) {
            $this->runRegistry->setState($scientificRunId, 'running');
        }
    }

    public function completeFromJob(int $jobId): void
    {
        $job = $this->db->analysisJob($jobId);
        if (!$job || (string)$job['kind'] !== 'stems') {
            return;
        }

        $scientificRunId = (int)($job['scientific_run_id'] ?? 0);
        if ($scientificRunId <= 0) {
            throw new \RuntimeException('stems_scientific_run_missing');
        }

        $request = $this->decodeRequest($job);
        $storageRoot = trim((string)($request['storage_root'] ?? ''));
        if ($storageRoot === '') {
            throw new \RuntimeException('stems_storage_root_missing');
        }

        $current = $this->readJson($storageRoot.DIRECTORY_SEPARATOR.'current.json');
        $runName = trim((string)($current['run'] ?? ''));
        if ($runName === '') {
            throw new \RuntimeException('stems_current_pointer_missing');
        }

        $runDir = $storageRoot.DIRECTORY_SEPARATOR.'runs'.DIRECTORY_SEPARATOR.$runName;
        if (!is_dir($runDir)) {
            throw new \RuntimeException('stems_run_dir_missing');
        }

        $manifestPath = $runDir.DIRECTORY_SEPARATOR.'manifest.json';
        $diagnosticsPath = $runDir.DIRECTORY_SEPARATOR.'diagnostics.json';
        $manifest = $this->readJson($manifestPath);
        $diagnostics = $this->readJson($diagnosticsPath);

        foreach (self::STEM_NAMES as $name) {
            $path = $runDir.DIRECTORY_SEPARATOR.$name.'.wav';
            if (!is_file($path) || filesize($path) <= 0) {
                throw new \RuntimeException('stems_artifact_missing:'.$name);
            }
            $sha = hash_file('sha256', $path);
            if (!is_string($sha) || $sha === '') {
                throw new \RuntimeException('stems_artifact_hash_failed:'.$name);
            }

            $this->runRegistry->registerArtifact(
                $scientificRunId,
                $name,
                $path,
                $sha,
                [
                    'stem' => $name,
                    'bytes' => filesize($path),
                    'source_stems_run' => $runName,
                    'engines' => $manifest['engines'] ?? [],
                    'manifest_schema' => $manifest['schema'] ?? null,
                    'manifest_schema_version' => $manifest['schema_version'] ?? null,
                    'timebase' => $manifest['timebase'] ?? 'original_audio_seconds',
                    'analysis_job_id' => $jobId,
                ],
                $name,
            );
        }

        foreach ([
            $manifestPath => 'stems_manifest',
            $diagnosticsPath => 'stems_diagnostics',
        ] as $path => $role) {
            if (!is_file($path)) {
                continue;
            }
            $sha = hash_file('sha256', $path);
            if (is_string($sha) && $sha !== '') {
                $this->runRegistry->registerArtifact(
                    $scientificRunId,
                    $role,
                    $path,
                    $sha,
                    ['analysis_job_id' => $jobId],
                    $role,
                );
            }
        }

        $this->runRegistry->setLabel($scientificRunId, 'progress', '100');
        $this->runRegistry->setLabel($scientificRunId, 'stems_run_dir', $runDir);
        $this->runRegistry->setState(
            $scientificRunId,
            'done',
            [
                'analysis_elapsed_seconds' => $manifest['analysis_elapsed_seconds'] ?? null,
                'duration_seconds' => $manifest['duration_seconds'] ?? null,
                'device' => $manifest['device'] ?? null,
            ],
            $diagnostics,
            [
                'device' => $manifest['device'] ?? null,
                'engines' => $manifest['engines'] ?? [],
            ],
            gmdate('c'),
        );
    }

    public function failFromJob(int $jobId, string $error): void
    {
        $job = $this->db->analysisJob($jobId);
        if (!$job || (string)$job['kind'] !== 'stems') {
            return;
        }

        $scientificRunId = (int)($job['scientific_run_id'] ?? 0);
        if ($scientificRunId <= 0) {
            return;
        }

        $this->runRegistry->setLabel($scientificRunId, 'last_error', $error);
        $this->runRegistry->setState(
            $scientificRunId,
            'error',
            [],
            ['error' => $error],
            [],
            gmdate('c'),
        );
    }

    private function toView(array $row): array
    {
        $request = $this->decodeRequest($row);
        $status = (string)$row['status'];
        $state = match ($status) {
            'completed' => 'done',
            'failed' => 'error',
            default => $status,
        };

        $tmpRoot = rtrim(
            (string)(getenv('EZSTUDIO_TMP_ROOT') ?: dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'tmp'),
            '\\/'
        );
        $storageRoot = trim((string)($request['storage_root'] ?? ''));
        $progressFile = $tmpRoot
            .DIRECTORY_SEPARATOR.'jobs'
            .DIRECTORY_SEPARATOR.(string)$row['id']
            .DIRECTORY_SEPARATOR.'progress.json';

        $result = null;
        if ($storageRoot !== '') {
            $current = $this->readJson($storageRoot.DIRECTORY_SEPARATOR.'current.json');
            $runName = trim((string)($current['run'] ?? ''));
            if ($runName !== '') {
                $runDir = $storageRoot.DIRECTORY_SEPARATOR.'runs'.DIRECTORY_SEPARATOR.$runName;
                $manifest = $runDir.DIRECTORY_SEPARATOR.'manifest.json';
                $diagnostics = $runDir.DIRECTORY_SEPARATOR.'diagnostics.json';
                $result = [
                    'run' => $runName,
                    'run_dir' => $runDir,
                    'manifest' => is_file($manifest) ? $manifest : null,
                    'diagnostics' => is_file($diagnostics) ? $diagnostics : null,
                ];
            }
        }

        return [
            'job_id' => (int)$row['id'],
            'kind' => 'stems',
            'song_id' => (int)$row['song_id'],
            'state' => $state,
            'progress' => (int)$row['progress'],
            'error' => $row['error'] ?? null,
            'request' => $request,
            'paths' => [
                'source' => (string)$row['source_path'],
                'storage_root' => $storageRoot,
                'progress_file' => $progressFile,
            ],
            'song' => [
                'title' => (string)($row['title'] ?? ''),
                'artist' => (string)($row['artist'] ?? ''),
            ],
            'scientific_run_id' => isset($row['scientific_run_id'])
                ? (int)$row['scientific_run_id']
                : null,
            'result' => $result,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function decodeRequest(array $job): array
    {
        $request = json_decode((string)($job['request_json'] ?? ''), true);
        return is_array($request) ? $request : [];
    }

    private function readJson(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
