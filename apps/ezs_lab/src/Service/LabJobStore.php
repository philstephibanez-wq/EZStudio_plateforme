<?php

namespace EZSLab\Service;

final class LabJobStore
{
    private string $root;

    public function __construct()
    {
        $this->root = (string)(
            $_ENV['EZSTUDIO_JOB_ROOT']
            ?? $_SERVER['EZSTUDIO_JOB_ROOT']
            ?? dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'jobs'
        );
        $this->ensureDir($this->root);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function reserveId(): int
    {
        $lockPath = $this->root.DIRECTORY_SEPARATOR.'sequence.lock';
        $sequencePath = $this->root.DIRECTORY_SEPARATOR.'sequence.txt';
        $fh = fopen($lockPath, 'c+');
        if ($fh === false) {
            throw new \RuntimeException('Impossible d’ouvrir le verrou de séquence.');
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new \RuntimeException('Impossible de verrouiller la séquence.');
            }

            $current = is_file($sequencePath)
                ? (int)trim((string)file_get_contents($sequencePath))
                : 0;
            $next = max(0, $current) + 1;
            $this->atomicWrite($sequencePath, (string)$next."\n");
            flock($fh, LOCK_UN);

            return $next;
        } finally {
            fclose($fh);
        }
    }

    public function createStemsJob(
        int $jobId,
        string $source,
        string $audioHash,
        string $title,
        string $artist,
        bool $force = false,
        ?int $songId = null,
        string $engineProfile = 'canonical_roformer',
        ?int $parentJobId = null,
        ?int $scientificRunId = null,
    ): array {
        $storageRoot = (string)(getenv('EZSTUDIO_STEMS_CACHE_ROOT') ?: dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'stems')
            .DIRECTORY_SEPARATOR.$audioHash;
        $progressFile = $storageRoot.DIRECTORY_SEPARATOR.'progress.json';

        $job = [
            'protocol' => 'ezscore.analysis-job.v2',
            'job_id' => $jobId,
            'target' => 'lab',
            'kind' => 'stems',
            'song_id' => $songId,
            'resource_class' => 'gpu',
            'priority' => 50,
            'paths' => [
                'source' => $source,
                'storage_root' => $storageRoot,
                'progress_file' => $progressFile,
            ],
            'request' => [
                'audio_hash' => $audioHash,
                'force' => $force,
                'output_contract' => 'ezstudio.stems.v1',
                'engine_profile' => $engineProfile,
                'parent_job_id' => $parentJobId,
                'scientific_run_id' => $scientificRunId,
                'lineage' => [
                    'source_audio_hash' => $audioHash,
                    'source_song_id' => $songId,
                    'parent_stems_job_id' => $parentJobId,
                    'scientific_run_id' => $scientificRunId,
                    'engine_profile' => $engineProfile,
                    'force_reanalysis' => $force,
                ],
            ],
            'song' => [
                'title' => $title,
                'artist' => $artist,
            ],
            'state' => 'queued',
            'progress' => 0,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
            'error' => null,
            'result' => null,
        ];

        $this->writeJob($job);
        return $job;
    }

    public function all(): array
    {
        $rows = [];
        foreach (glob($this->root.DIRECTORY_SEPARATOR.'job-*.json') ?: [] as $file) {
            $data = $this->readJson($file);
            if (is_array($data)) {
                $rows[] = $data;
            }
        }

        usort(
            $rows,
            static fn(array $a, array $b): int =>
                ((int)$b['job_id']) <=> ((int)$a['job_id'])
        );

        return $rows;
    }

    public function jobsForAudioHash(string $audioHash): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(array $job): bool =>
                (string)($job['kind'] ?? '') === 'stems'
                && hash_equals(
                    $audioHash,
                    (string)($job['request']['audio_hash'] ?? '')
                )
        ));
    }

    public function get(int $jobId): ?array
    {
        $path = $this->jobPath($jobId);
        if (!is_file($path)) {
            return null;
        }

        $data = $this->readJson($path);
        return is_array($data) ? $data : null;
    }

    public function queue(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(array $job): bool =>
                ($job['state'] ?? null) === 'queued'
        ));
    }

    public function claim(): ?array
    {
        return $this->withQueueLock(function (): ?array {
            $queued = $this->queue();
            if (!$queued) {
                return null;
            }

            usort($queued, static function (array $a, array $b): int {
                $priority = ((int)($b['priority'] ?? 50))
                    <=> ((int)($a['priority'] ?? 50));

                return $priority !== 0
                    ? $priority
                    : ((int)$a['job_id']) <=> ((int)$b['job_id']);
            });

            $job = $queued[0];
            $job['state'] = 'running';
            $job['started_at'] = gmdate('c');
            $job['updated_at'] = gmdate('c');
            $this->writeJob($job);

            return $this->envelope($job);
        });
    }

    public function progress(int $jobId, int $percent): void
    {
        $this->update($jobId, function (array $job) use ($percent): array {
            $job['progress'] = max(0, min(100, $percent));
            if (($job['state'] ?? '') === 'queued') {
                $job['state'] = 'running';
            }

            return $job;
        });
    }

    public function complete(int $jobId): void
    {
        $this->update($jobId, function (array $job): array {
            $root = (string)($job['paths']['storage_root'] ?? '');
            $result = null;

            if ($root !== '') {
                $pointer = $root.DIRECTORY_SEPARATOR.'current.json';
                if (is_file($pointer)) {
                    $current = $this->readJson($pointer);
                    $run = is_array($current)
                        ? (string)($current['run'] ?? '')
                        : '';

                    $runDir = $run !== ''
                        ? $root.DIRECTORY_SEPARATOR.'runs'.DIRECTORY_SEPARATOR.$run
                        : '';
                    $manifest = $runDir !== ''
                        ? $runDir.DIRECTORY_SEPARATOR.'manifest.json'
                        : '';
                    $diagnostics = $runDir !== ''
                        ? $runDir.DIRECTORY_SEPARATOR.'diagnostics.json'
                        : '';

                    $result = [
                        'run' => $run,
                        'run_dir' => $runDir !== '' ? $runDir : null,
                        'manifest' => $manifest !== '' && is_file($manifest)
                            ? $manifest
                            : null,
                        'diagnostics' => $diagnostics !== '' && is_file($diagnostics)
                            ? $diagnostics
                            : null,
                    ];
                }
            }

            $job['state'] = 'done';
            $job['progress'] = 100;
            $job['finished_at'] = gmdate('c');
            $job['error'] = null;
            $job['result'] = $result;

            return $job;
        });
    }

    public function fail(int $jobId, string $error): void
    {
        $this->update($jobId, function (array $job) use ($error): array {
            $job['state'] = 'error';
            $job['finished_at'] = gmdate('c');
            $job['error'] = substr($error, 0, 1000);

            return $job;
        });
    }

    public function envelope(array $job): array
    {
        return [
            'protocol' => (string)$job['protocol'],
            'job_id' => (int)$job['job_id'],
            'target' => 'lab',
            'kind' => (string)$job['kind'],
            'song_id' => $job['song_id'] ?? null,
            'resource_class' => (string)($job['resource_class'] ?? 'gpu'),
            'priority' => (int)($job['priority'] ?? 50),
            'paths' => (array)($job['paths'] ?? []),
            'request' => (array)($job['request'] ?? []),
            'song' => (array)($job['song'] ?? []),
        ];
    }

    public function purgeByAudioHash(string $audioHash): array
    {
        $jobs = $this->jobsForAudioHash($audioHash);

        foreach ($jobs as $job) {
            if (in_array((string)($job['state'] ?? ''), ['queued','running'], true)) {
                throw new \RuntimeException('stems_job_active');
            }
        }

        $deleted = 0;
        foreach ($jobs as $job) {
            $id = (int)$job['job_id'];
            $path = $this->jobPath($id);
            if (is_file($path) && @unlink($path)) {
                ++$deleted;
            }

            $source = (string)($job['paths']['source'] ?? '');
            if (
                $source !== ''
                && is_file($source)
                && $this->isUnder($source, (string)(getenv('EZSTUDIO_TMP_ROOT') ?: dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'tmp').DIRECTORY_SEPARATOR.'uploads')
            ) {
                @unlink($source);
            }
        }

        $storageRoot = (string)(getenv('EZSTUDIO_STEMS_CACHE_ROOT') ?: dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'stems')
            .DIRECTORY_SEPARATOR.$audioHash;
        if (is_dir($storageRoot)) {
            $this->removeTree($storageRoot);
        }

        return ['jobs' => $deleted];
    }

    private function update(int $jobId, callable $mutator): void
    {
        $this->withQueueLock(function () use ($jobId, $mutator): void {
            $job = $this->get($jobId);
            if ($job === null) {
                throw new \RuntimeException('Job introuvable: '.$jobId);
            }

            $job = $mutator($job);
            $job['updated_at'] = gmdate('c');
            $this->writeJob($job);
        });
    }

    private function withQueueLock(callable $fn): mixed
    {
        $path = $this->root.DIRECTORY_SEPARATOR.'queue.lock';
        $fh = fopen($path, 'c+');
        if ($fh === false) {
            throw new \RuntimeException('Impossible d’ouvrir le verrou de queue.');
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new \RuntimeException('Impossible de verrouiller la queue.');
            }

            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function writeJob(array $job): void
    {
        $this->atomicWrite(
            $this->jobPath((int)$job['job_id']),
            json_encode(
                $job,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )."\n"
        );
    }

    private function jobPath(int $jobId): string
    {
        return $this->root.DIRECTORY_SEPARATOR.sprintf(
            'job-%08d.json',
            $jobId
        );
    }

    private function readJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function atomicWrite(string $path, string $content): void
    {
        $this->ensureDir(dirname($path));
        $tmp = $path.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Écriture impossible: '.$tmp);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(
                'Publication atomique impossible: '.$path
            );
        }
    }

    private function ensureDir(string $path): void
    {
        if (
            !is_dir($path)
            && !mkdir($path, 0777, true)
            && !is_dir($path)
        ) {
            throw new \RuntimeException('Impossible de créer: '.$path);
        }
    }

    private function isUnder(string $path, string $root): bool
    {
        $p = strtolower(str_replace('/', '\\', $path));
        $r = strtolower(rtrim(str_replace('/', '\\', $root), '\\')).'\\';

        return str_starts_with($p.'\\', $r);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
