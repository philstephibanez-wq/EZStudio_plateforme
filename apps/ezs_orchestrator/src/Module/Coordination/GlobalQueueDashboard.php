<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Coordination;

use App\Entity\JobQueue;
use PDO;

/** Agnostic global-queue read model on data/orchestrator.sqlite (waiting / progressing / ended). */
final class GlobalQueueDashboard
{
    public static function read(?string $path = null, array $filters = []): array
    {
        $path ??= dirname(__DIR__, 5) . (\App\Service\QueueBarrierPaths::isTest() ? '/var/orchestrator_test.sqlite' : '/data/databases/EZS_Orchestrator.sqlite');
        $stateFilter = in_array((string) ($filters['state'] ?? 'all'), ['all', 'active', 'waiting', 'progressing', 'cancelling', 'ended_ko'], true) ? (string) $filters['state'] : 'all';
        $actorFilter = in_array((string) ($filters['actor'] ?? 'all'), ['all', 'lab', 'assistant'], true) ? (string) $filters['actor'] : 'all';
        $categoryFilter = in_array((string) ($filters['category'] ?? 'all'), ['all', 'Analyse', 'Expertise'], true) ? (string) $filters['category'] : 'all';
        $itemFilter = in_array((string) ($filters['item'] ?? 'all'), ['all', 'Profile', 'Stems', 'Chords', 'Lyrics'], true) ? (string) $filters['item'] : 'all';
        $limit = in_array((int) ($filters['limit'] ?? 30), [20, 30, 50, 100], true) ? (int) $filters['limit'] : 30;
        $activeFilters = ['state' => $stateFilter, 'actor' => $actorFilter, 'category' => $categoryFilter, 'item' => $itemFilter, 'limit' => $limit];

        if (!is_file($path)) {
            return ['available' => false, 'reason' => 'queue_database_missing', 'jobs' => [], 'filters' => $activeFilters];
        }
        try {
            $uri = 'file:' . str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], str_replace('\\', '/', $path)) . '?mode=ro';
            $db = new PDO('sqlite:' . $uri, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
            $db->exec('PRAGMA query_only=ON');

            $counts = ['waiting' => 0, 'progressing' => 0, 'cancelling' => 0, 'cancelled' => 0, 'ended' => 0, 'ended_ok' => 0, 'ended_ko' => 0];
            foreach ($db->query('SELECT status, COALESCE(has_error, 0) AS has_error, COUNT(*) AS qty FROM job_queue GROUP BY status, COALESCE(has_error, 0)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $st = (string) ($c['status'] ?? '');
                $qty = (int) ($c['qty'] ?? 0);
                $err = ((int) ($c['has_error'] ?? 0)) === 1;
                if ($st === 'waiting') { $counts['waiting'] += $qty; }
                elseif ($st === 'progressing') { $counts['progressing'] += $qty; }
                elseif ($st === 'cancelling') { $counts['cancelling'] += $qty; }
                elseif ($st === 'cancelled') { $counts['cancelled'] += $qty; }
                elseif ($st === 'ended') {
                    $counts['ended'] += $qty;
                    if ($err) { $counts['ended_ko'] += $qty; } else { $counts['ended_ok'] += $qty; }
                }
            }

            // EZS_FIFO_ACTIVE_RETRY_R1B: Orchestrator is an active queue, NOT
            // a historical catalogue. Show one blocker (if any) and live jobs.
            $pausePath = \App\Service\QueueBarrierPaths::pause(dirname(__DIR__, 5));
            $blockedId = 0;
            if (is_file($pausePath)) {
                $pauseRaw = @file_get_contents($pausePath);
                $pause = is_string($pauseRaw) ? json_decode($pauseRaw, true) : null;
                if (!is_array($pause) || ($pause['schema'] ?? null) !== 'ezs.queue.pause_on_ko.v1'
                    || !is_int($pause['global_job_id'] ?? null) || $pause['global_job_id'] <= 0) {
                    return ['available' => false, 'reason' => 'queue_pause_invalid', 'jobs' => [], 'filters' => $activeFilters];
                }
                $blockedId = $pause['global_job_id'];
            }
            $sql = "SELECT id, source, status, COALESCE(progress, 0) AS progress, COALESCE(has_error, 0) AS has_error, error_message, COALESCE(attempts, 0) AS attempts, payload, created_at, updated_at
                    FROM job_queue
                    WHERE status IN ('waiting','progressing','cancelling')
                      OR (id = :blocked_for_terminal AND status = 'ended' AND has_error = 1)
                    ORDER BY CASE WHEN id = :blocked_for_order THEN 0 WHEN status IN ('progressing','cancelling') THEN 1 ELSE 2 END,
                             created_at ASC, id ASC
                    LIMIT 500";
            $query = $db->prepare($sql);
            $query->execute(['blocked_for_terminal' => $blockedId, 'blocked_for_order' => $blockedId]);
            $rawRows = $query->fetchAll(PDO::FETCH_ASSOC);
            // History stays in LAB / Assistant. Hide historical counts.
            $counts['ended'] = 0;
            $counts['ended_ok'] = 0;
            $counts['ended_ko'] = $blockedId > 0 ? 1 : 0;
            $counts['cancelled'] = 0;
            $rows = [];

            foreach ($rawRows as $r) {
                $actor = (string) ($r['source'] ?? 'orchestrator');
                // EZS_QUEUE_FILTER_BLOCKER_R1E: the blocker is globally visible in the alert,
                // but table rows must obey the chosen actor filter without exception.
                if ($actorFilter !== 'all' && $actor !== $actorFilter) { continue; }

                $rawStatus = (string) ($r['status'] ?? 'waiting');
                $state = in_array($rawStatus, ['waiting', 'progressing', 'cancelling', 'cancelled', 'ended'], true) ? $rawStatus : 'waiting';
                $hasError = ((int) ($r['has_error'] ?? 0)) === 1;

                if ($stateFilter === 'active' && !in_array($state, ['waiting', 'progressing', 'cancelling'], true)) { continue; }
                // Only the current blocking KO belongs to the active read model's
                // ended_ko filter; terminal history stays in the source application.
                if ($stateFilter === 'ended_ko' && !($state === 'ended' && $hasError && (int) $r['id'] === $blockedId)) { continue; }
                if (in_array($stateFilter, ['waiting','progressing','cancelling'], true) && $state !== $stateFilter) { continue; }

                $payload = json_decode((string) ($r['payload'] ?? '{}'), true);
                if (!is_array($payload)) { $payload = []; }
                $rawJob = strtolower(trim((string) ($payload['item'] ?? $payload['job'] ?? $payload['phase'] ?? $payload['type'] ?? '')));
                $item = match (true) { str_starts_with($rawJob, 'profile') => 'Profile', str_starts_with($rawJob, 'stems') => 'Stems', str_starts_with($rawJob, 'chords') => 'Chords', str_starts_with($rawJob, 'lyrics') => 'Lyrics', default => '' };
                $category = trim((string) ($payload['category'] ?? ''));
                if ($category === '' && $rawJob !== '') { $category = (str_contains($rawJob, 'review') || str_contains($rawJob, 'direct_research') || $actor === 'assistant') ? 'Expertise' : 'Analyse'; }
                $label = trim((string) ($payload['label'] ?? ''));

                if ($categoryFilter !== 'all' && $category !== $categoryFilter) { continue; }
                if ($itemFilter !== 'all' && $item !== $itemFilter) { continue; }

                $progress = (int) ($r['progress'] ?? 0);
                if ($state === 'ended' && !$hasError) { $progress = 100; }

                $subject = (new JobQueue($actor, $payload))->setStatus($state);
                $rows[] = [
                    'subject' => $subject,
                    'id' => (int) $r['id'],
                    'actor' => $actor,
                    'category' => $category !== '' ? $category : null,
                    'item' => $item !== '' ? $item : null,
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