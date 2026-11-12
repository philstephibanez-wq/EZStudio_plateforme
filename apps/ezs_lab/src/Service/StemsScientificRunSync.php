<?php

namespace EZSLab\Service;

final class StemsScientificRunSync
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

    public function __construct(private readonly AnalysisRunRegistry $runRegistry) {}

    public function ensureScientificRun(array $job): ?array
    {
        if ((string)($job['kind'] ?? '') !== 'stems') {
            return null;
        }

        $jobId = (int)($job['job_id'] ?? 0);
        if ($jobId <= 0) {
            return null;
        }

        $existing = $this->runRegistry->findByAlias('lab_job', (string)$jobId);
        if ($existing) {
            return $existing;
        }

        $audioHash = trim((string)($job['request']['audio_hash'] ?? ''));
        $songId = isset($job['song_id']) && $job['song_id'] !== null
            ? (int)$job['song_id']
            : $this->runRegistry->songIdByAudioHash($audioHash);

        if (!$songId) {
            return null;
        }

        $profile = trim((string)($job['request']['engine_profile'] ?? 'canonical_roformer'));
        $run = $this->runRegistry->createRun(
            $songId,
            'stems',
            [
                'name' => $profile,
                'version' => (string)($job['request']['engine_version'] ?? ''),
                'model' => (string)($job['request']['model'] ?? ''),
                'checkpoint' => (string)($job['request']['checkpoint'] ?? ''),
            ],
            [
                'audio_hash' => $audioHash,
                'force_reanalysis' => (bool)($job['request']['force'] ?? false),
                'output_contract' => (string)($job['request']['output_contract'] ?? 'ezstudio.stems.v1'),
                'engine_profile' => $profile,
                'technical_job_id' => $jobId,
            ],
        );

        $this->runRegistry->setAlias((int)$run['id'], 'lab_job', (string)$jobId);
        $this->runRegistry->setLabel((int)$run['id'], 'audio_sha256', $audioHash);

        $state = (string)($job['state'] ?? 'created');
        if ($state !== 'created') {
            $this->runRegistry->setState((int)$run['id'], $state);
        }

        return $this->runRegistry->run((int)$run['id']);
    }

    public function sync(array $job): ?array
    {
        $run = $this->ensureScientificRun($job);
        if (!$run) {
            return null;
        }

        $runId = (int)$run['id'];
        $state = (string)($job['state'] ?? 'created');

        if ($state !== 'done') {
            $this->runRegistry->setState($runId, $state);
            return $this->runRegistry->run($runId);
        }

        $result = is_array($job['result'] ?? null) ? $job['result'] : [];
        $runDir = trim((string)($result['run_dir'] ?? ''));
        if ($runDir === '' || !is_dir($runDir)) {
            $this->runRegistry->setState(
                $runId,
                'error',
                [],
                ['dna_sync_error' => 'stems_run_dir_missing'],
                [],
                gmdate('c'),
            );
            return $this->runRegistry->run($runId);
        }

        $manifest = $this->readJson(
            trim((string)($result['manifest'] ?? ''))
        );
        $diagnostics = $this->readJson(
            trim((string)($result['diagnostics'] ?? ''))
        );

        $engine = is_array($manifest['engine'] ?? null)
            ? $manifest['engine']
            : [];

        foreach (self::STEM_NAMES as $name) {
            $path = $runDir.DIRECTORY_SEPARATOR.$name.'.wav';
            if (!is_file($path) || filesize($path) <= 0) {
                continue;
            }

            $sha = hash_file('sha256', $path);
            if (!is_string($sha) || $sha === '') {
                continue;
            }

            $meta = [
                'stem' => $name,
                'bytes' => filesize($path),
                'source_stems_run' => basename($runDir),
                'engine' => $engine,
                'manifest_schema' => $manifest['schema'] ?? null,
                'manifest_schema_version' => $manifest['schema_version'] ?? null,
                'timebase' => $manifest['timebase'] ?? 'original_audio_seconds',
            ];

            $this->runRegistry->registerArtifact(
                $runId,
                $name,
                $path,
                $sha,
                $meta,
                $name,
            );
        }

        $metrics = [
            'analysis_elapsed_seconds' => $manifest['analysis_elapsed_seconds'] ?? null,
            'duration_seconds' => $manifest['duration_seconds'] ?? null,
            'device' => $manifest['device'] ?? null,
        ];

        $environment = is_array($manifest['environment'] ?? null)
            ? $manifest['environment']
            : [
                'device' => $manifest['device'] ?? null,
                'engine' => $engine,
            ];

        $this->runRegistry->setState(
            $runId,
            'done',
            $metrics,
            $diagnostics,
            $environment,
            (string)($job['finished_at'] ?? gmdate('c')),
        );

        return $this->runRegistry->run($runId);
    }

    public function syncMany(array $jobs): array
    {
        $out = [];
        foreach ($jobs as $job) {
            $run = $this->sync($job);
            if ($run) {
                $out[(int)$job['job_id']] = $run;
            }
        }
        return $out;
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
