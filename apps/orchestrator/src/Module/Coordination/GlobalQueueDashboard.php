<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Coordination;

use PDO;

/** Agnostic global-queue read model on data/orchestrator.sqlite (waiting / progressing / ended). */
final class GlobalQueueDashboard
{
    public static function read(?string $path = null, array $filters = []): array
    {
        $path ??= dirname(__DIR__, 5) . '/data/orchestrator.sqlite';
        $stateFilter = in_array((string) ($filters['state'] ?? 'all'), ['all', 'active', 'waiting', 'progressing', 'ended_ok', 'ended_ko'], true) ? (string) $filters['state'] : 'all';
        $actorFilter = in_array((string) ($filters['actor'] ?? 'all'), ['all', 'lab', 'assistant'], true) ? (string) $filters['actor'] : 'all';
        $categoryFilter = in_array((string) ($filters['category'] ?? 'all'), ['all', 'Analyse', 'Expertise'], true) ? (string) $filters['category'] : 'all';
        $limit = in_array((int) ($filters['limit'] ?? 30), [20, 30, 50, 100], true) ? (int) $filters['limit'] : 30;
        $activeFilters = ['state' => $stateFilter, 'actor' => $actorFilter, 'category' => $categoryFilter, 'limit' => $limit];

        if (!is_file($path)) {
            return ['available' => false, 'reason' => 'queue_database_missing', 'jobs' => [], 'filters' => $activeFilters];
        }
        try {
            $uri = 'file:' . str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], str_replace('\\', '/', $path)) . '?mode=ro';
            $db = new PDO('sqlite:' . $uri, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
            $db->exec('PRAGMA query_only=ON');

            $counts = ['waiting' => 0, 'progressing' => 0, 'ended' => 0, 'ended_ok' => 0, 'ended_ko' => 0];
            foreach ($db->query('SELECT status, COALESCE(has_error, 0) AS has_error, COUNT(*) AS qty FROM job_queue GROUP BY status, COALESCE(has_error, 0)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $st = (string) ($c['status'] ?? '');
                $qty = (int) ($c['qty'] ?? 0);
                $err = ((int) ($c['has_error'] ?? 0)) === 1;
                if ($st === 'waiting') { $counts['waiting'] += $qty; }
                elseif ($st === 'progressing') { $counts['progressing'] += $qty; }
                elseif ($st === 'ended') {
                    $counts['ended'] += $qty;
                    if ($err) { $counts['ended_ko'] += $qty; } else { $counts['ended_ok'] += $qty; }
                }
            }

            $sql = "SELECT id, source, status, COALESCE(progress, 0) AS progress, COALESCE(has_error, 0) AS has_error, error_message, COALESCE(attempts, 0) AS attempts, payload, created_at, updated_at
                    FROM job_queue
                    ORDER BY CASE status WHEN 'progressing' THEN 0 WHEN 'waiting' THEN 1 ELSE 2 END, id DESC
                    LIMIT 500";
            $rawRows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $rows = [];

            foreach ($rawRows as $r) {
                $actor = (string) ($r['source'] ?? 'orchestrator');
                if ($actorFilter !== 'all' && $actor !== $actorFilter) { continue; }

                $rawStatus = (string) ($r['status'] ?? 'waiting');
                $state = in_array($rawStatus, ['waiting', 'progressing', 'ended'], true) ? $rawStatus : 'waiting';
                $hasError = ((int) ($r['has_error'] ?? 0)) === 1;

                if ($stateFilter === 'active' && !in_array($state, ['waiting', 'progressing'], true)) { continue; }
                if ($stateFilter === 'waiting' && $state !== 'waiting') { continue; }
                if ($stateFilter === 'progressing' && $state !== 'progressing') { continue; }
                if ($stateFilter === 'ended_ok' && !($state === 'ended' && !$hasError)) { continue; }
                if ($stateFilter === 'ended_ko' && !($state === 'ended' && $hasError)) { continue; }

                $payload = json_decode((string) ($r['payload'] ?? '{}'), true);
                if (!is_array($payload)) { $payload = []; }
                $category = trim((string) ($payload['category'] ?? ''));
                $label = trim((string) ($payload['label'] ?? ''));

                if ($categoryFilter !== 'all' && $category !== $categoryFilter) { continue; }

                $progress = (int) ($r['progress'] ?? 0);
                if ($state === 'ended' && !$hasError) { $progress = 100; }

                $rows[] = [
                    'id' => (int) $r['id'],
                    'actor' => $actor,
                    'category' => $category !== '' ? $category : null,
                    'label' => $label !== '' ? $label : null,
                    'state' => $state,
                    'progress' => max(0, min(100, $progress)),
                    'has_error' => $hasError,
                    'error_message' => ($r['error_message'] !== null && $r['error_message'] !== '') ? (string) $r['error_message'] : null,
                    'attempts' => (int) ($r['attempts'] ?? 0),
                    'created_at' => (string) ($r['created_at'] ?? ''),
                    'updated_at' => (string) ($r['updated_at'] ?? $r['created_at'] ?? ''),
                ];
                if (count($rows) >= $limit) { break; }
            }

            return ['available' => true, 'reason' => null, 'jobs' => $rows, 'counts' => $counts, 'filters' => $activeFilters, 'observed_at' => time()];
        } catch (\Throwable) {
            return ['available' => false, 'reason' => 'queue_read_unavailable', 'jobs' => [], 'filters' => $activeFilters];
        }
    }
}