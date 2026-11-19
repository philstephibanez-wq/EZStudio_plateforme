<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

/**
 * Read-only V1 history. Discovery inspects file metadata; only recent candidates
 * are decoded. Never reads execution.log or calls the queue transport.
 */
final class LegacyJobHistoryReader
{
    public function __construct(private readonly string $root) {}

    public function recent(int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        $capacity = $limit * 10;
        $recent = new \SplMinHeap();

        foreach (['dev', 'prod', 'lab'] as $target) {
            $directory = rtrim($this->root, '/\\') . '/runtime/jobs/' . $target;
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new \DirectoryIterator($directory) as $job) {
                if ($job->isDot() || !$job->isDir() || $job->isLink()) {
                    continue;
                }
                foreach (new \DirectoryIterator($job->getPathname()) as $run) {
                    if ($run->isDot() || !$run->isDir() || $run->isLink()) {
                        continue;
                    }
                    $file = $run->getPathname() . '/result.json';
                    if (!is_file($file) || is_link($file)) {
                        continue;
                    }
                    $size = @filesize($file);
                    $mtime = @filemtime($file);
                    if ($size === false || $size > 1048576 || $mtime === false) {
                        continue;
                    }
                    $candidate = [(int) $mtime, $target, $job->getFilename(), $run->getFilename(), $file];
                    if ($recent->count() < $capacity) {
                        $recent->insert($candidate);
                    } elseif ($candidate > $recent->top()) {
                        $recent->extract();
                        $recent->insert($candidate);
                    }
                }
            }
        }

        $selected = [];
        foreach ($recent as $candidate) {
            $selected[] = $candidate;
        }
        usort($selected, static fn (array $a, array $b): int => $b <=> $a);

        $rows = [];
        foreach ($selected as [$mtime, $target, $jobName, $runName, $file]) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || array_is_list($data)) {
                continue;
            }
            $id = filter_var($data['job_id'] ?? $jobName, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                continue;
            }
            $state = strtolower((string) ($data['state'] ?? $data['status'] ?? ''));
            $aliases = [
                'success' => 'completed',
                'succeeded' => 'completed',
                'done' => 'completed',
                'complete' => 'completed',
                'error' => 'failed',
                'failure' => 'failed',
            ];
            $state = $aliases[$state] ?? $state;
            if (!in_array($state, ['queued', 'running', 'completed', 'failed', 'finalize_error'], true)) {
                continue;
            }
            $rc = $data['analysis_returncode'] ?? $data['returncode'] ?? $data['return_code'] ?? null;
            if ($rc !== null && !is_int($rc)) {
                $rc = null;
            }
            $final = (string) ($data['finalize_status'] ?? '');
            $rows[] = [
                'job_id' => (int) $id,
                'target' => $target,
                'kind' => substr((string) ($data['kind'] ?? '-'), 0, 80),
                'song_id' => is_int($data['song_id'] ?? null) ? $data['song_id'] : null,
                'song_title' => substr((string) ($data['song_title'] ?? $data['title'] ?? ''), 0, 100),
                'state' => $state,
                'analysis_returncode' => $rc,
                'finalize_status' => substr($final, 0, 40),
                'error' => in_array($state, ['failed', 'finalize_error'], true) ? substr((string) ($data['error'] ?? ''), 0, 240) : null,
                'run' => substr($runName, 0, 100),
                'timestamp' => gmdate('c', $mtime),
            ];
            if (count($rows) === $limit) {
                break;
            }
        }

        return $rows;
    }
}
