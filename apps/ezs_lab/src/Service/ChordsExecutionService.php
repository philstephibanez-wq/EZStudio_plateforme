<?php

namespace EZSLab\Service;

final class ChordsExecutionService
{
    public function __construct(
        private readonly Database $db,
        private readonly AnalysisRunRegistry $runRegistry,
    ) {}

    public function queue(int $scientificRunId): array
    {
        $run = $this->runRegistry->run($scientificRunId);
        if (!$run
            || (string)$run['item'] !== 'chords'
            || !in_array((string)$run['state'], ['configured','error'], true)
        ) {
            throw new \InvalidArgumentException('chords_run_not_configured');
        }

        $requestPath = trim((string)($run['labels']['execution_request'] ?? ''));
        if ($requestPath === '' || !is_file($requestPath)) {
            throw new \RuntimeException('chords_execution_request_missing');
        }

        $stmt = $this->db->pdo()->prepare("SELECT source_path FROM songs WHERE id=?");
        $stmt->execute([(int)$run['song_id']]);
        $source = $stmt->fetchColumn();
        if (!is_string($source) || $source === '' || !is_file($source)) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT input_path FROM benchmark_runs WHERE song_id=? AND input_path<>'' ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([(int)$run['song_id']]);
            $source = $stmt->fetchColumn();
        }
        if (!is_string($source) || $source === '' || !is_file($source)) {
            throw new \RuntimeException('song_master_source_missing');
        }

        $signature = trim((string)($run['config']['signature'] ?? 'Auto')) ?: 'Auto';
        $benchmarkRunId = $this->db->createRun(
            (int)$run['song_id'],
            $signature,
            $source,
        );

        $jobId = $this->db->queueScientificChordsJob(
            $benchmarkRunId,
            $scientificRunId,
            $requestPath,
            $signature,
        );

        $this->runRegistry->setAlias($scientificRunId, 'benchmark_run', (string)$benchmarkRunId);
        $this->runRegistry->setAlias($scientificRunId, 'analysis_job', (string)$jobId);
        $this->runRegistry->setLabel($scientificRunId, 'benchmark_run_id', (string)$benchmarkRunId);
        $this->runRegistry->setLabel($scientificRunId, 'analysis_job_id', (string)$jobId);
        $this->runRegistry->setState($scientificRunId, 'queued');

        return [
            'scientific_run_id' => $scientificRunId,
            'benchmark_run_id' => $benchmarkRunId,
            'job_id' => $jobId,
        ];
    }

    public function progressFromJob(int $jobId, int $percent): void
    {
        $job = $this->db->analysisJob($jobId);
        if (!$job || (string)$job['kind'] !== 'chords_scientific') {
            return;
        }

        $request = json_decode((string)$job['request_json'], true);
        $scientificRunId = is_array($request)
            ? (int)($request['scientific_run_id'] ?? 0)
            : 0;
        if ($scientificRunId <= 0) {
            return;
        }

        $this->runRegistry->setLabel(
            $scientificRunId,
            'progress',
            (string)max(0, min(100, $percent)),
        );
        if ($percent > 0 && $percent < 100) {
            $this->runRegistry->setState($scientificRunId, 'running');
        }
    }

    public function completeFromJob(int $jobId): void
    {
        $job = $this->db->analysisJob($jobId);
        if (!$job || (string)$job['kind'] !== 'chords_scientific') {
            return;
        }

        $request = json_decode((string)$job['request_json'], true);
        if (!is_array($request)) {
            throw new \RuntimeException('scientific_job_request_invalid');
        }

        $scientificRunId = (int)($request['scientific_run_id'] ?? 0);
        if ($scientificRunId <= 0) {
            throw new \RuntimeException('scientific_run_id_missing');
        }

        $benchmarkRun = $this->db->run((int)$job['run_id']);
        if (!$benchmarkRun || (string)$benchmarkRun['status'] !== 'done') {
            throw new \RuntimeException('scientific_benchmark_run_not_done');
        }

        $result = !empty($benchmarkRun['result_json'])
            ? json_decode((string)$benchmarkRun['result_json'], true)
            : [];
        if (!is_array($result)) {
            $result = [];
        }

        $observability = is_array($result['observability'] ?? null)
            ? $result['observability']
            : [];
        $artifactRoot = trim((string)($observability['artifact_root'] ?? ''));

        if ($artifactRoot !== '' && is_dir($artifactRoot)) {
            foreach ([
                'raw/engine_result.json' => 'chords_result',
                'run.json' => 'observability_run',
                'manifest.json' => 'scientific_manifest',
            ] as $relative => $role) {
                $path = $artifactRoot.DIRECTORY_SEPARATOR
                    .str_replace('/', DIRECTORY_SEPARATOR, $relative);
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
                        [
                            'benchmark_run_id' => (int)$job['run_id'],
                            'analysis_job_id' => $jobId,
                            'execution_contract' => 'ezstudio.chords.selection.v1',
                        ],
                        $role,
                    );
                }
            }
        }

        $metrics = [
            'tempo' => $result['tempo'] ?? null,
            'signature' => $result['signature'] ?? null,
            'beat_count' => is_array($result['beat_grid_s'] ?? null)
                ? count($result['beat_grid_s'])
                : 0,
            'segment_count' => is_array($result['segments'] ?? null)
                ? count($result['segments'])
                : 0,
            'benchmark_run_id' => (int)$job['run_id'],
        ];
        $diagnostics = [
            'convergence' => $result['convergence'] ?? [],
            'no_chord_benchmark' => $result['no_chord_benchmark'] ?? [],
        ];
        $environment = [
            'versions' => $result['versions'] ?? [],
            'engine_version' => $result['engine_version'] ?? '',
            'analysis_source' => $result['analysis_source'] ?? '',
        ];

        $this->runRegistry->setLabel($scientificRunId, 'progress', '100');
        $this->runRegistry->setLabel(
            $scientificRunId,
            'canonical_benchmark_run_id',
            (string)$job['run_id'],
        );
        $this->runRegistry->setState(
            $scientificRunId,
            'done',
            $metrics,
            $diagnostics,
            $environment,
            gmdate('c'),
        );
    }

    public function failFromJob(int $jobId, string $error): void
    {
        $job = $this->db->analysisJob($jobId);
        if (!$job || (string)$job['kind'] !== 'chords_scientific') {
            return;
        }

        $request = json_decode((string)$job['request_json'], true);
        $scientificRunId = is_array($request)
            ? (int)($request['scientific_run_id'] ?? 0)
            : 0;
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
}
