<?php

declare(strict_types=1);

namespace EZSLab\Service;

use PDO;

/** Read-only catalogue projection of LAB-owned PROFILE and expertise jobs. */
final class LocalQueueVisibility
{
    public function __construct(private readonly Database $db) {}

    /** The same ordering and status ownership as the existing local FIFO. */
    public static function activeRows(PDO $pdo): array
    {
        $statement = $pdo->query(<<<'SQL'
SELECT j.id, j.kind, j.status, j.progress, j.scientific_run_id, j.song_id,
       j.created_at, j.updated_at, s.title, s.artist
FROM analysis_jobs j
LEFT JOIN songs s ON s.id=j.song_id
WHERE j.dispatch_owner='lab'
  AND j.status IN ('queued','running','cancelling')
  AND j.kind IN ('profile','assistant.profile.review','expertise.profile')
ORDER BY j.created_at ASC,j.id ASC
SQL);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{active:array,expertise_by_song:array} */
    public function snapshot(array $songs): array
    {
        $pdo = $this->db->pdo();
        $active = [];
        $activeBySong = [];
        foreach (self::activeRows($pdo) as $position => $row) {
            $item = [
                'job_id' => (int)$row['id'],
                'kind' => (string)$row['kind'],
                'type' => (string)$row['kind'] === 'profile' ? 'PROFILE' : 'EXPERTISE IA',
                'status' => (string)$row['status'],
                'progress' => max(0, min(100, (int)$row['progress'])),
                'run_id' => (int)$row['scientific_run_id'],
                'song_id' => (int)$row['song_id'],
                'song_title' => (string)($row['title'] ?? ''),
                'song_artist' => (string)($row['artist'] ?? ''),
                'position' => $position + 1,
            ];
            $active[] = $item;
            if ($item['type'] === 'EXPERTISE IA') {
                $activeBySong[$item['song_id']][] = $item;
            }
        }

        // Include the last expertise attempt even when terminal, so an actual
        // requested job never disappears into the ambiguous "review pending" label.
        $latest = [];
        $statement = $pdo->query(<<<'SQL'
SELECT id,kind,status,progress,scientific_run_id,song_id,updated_at
FROM analysis_jobs
WHERE dispatch_owner='lab'
  AND kind IN ('assistant.profile.review','expertise.profile')
ORDER BY id DESC
LIMIT 500
SQL);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['song_id'];
            if (isset($latest[$id])) continue;
            $latest[$id] = [
                'job_id' => (int)$row['id'],
                'run_id' => (int)$row['scientific_run_id'],
                'status' => (string)$row['status'],
                'progress' => max(0, min(100, (int)$row['progress'])),
                'updated_at' => (string)$row['updated_at'],
            ];
        }
        $bySong = [];
        foreach ($songs as $song) {
            $groupId = (int)$song['id'];
            $ids = array_map('intval', $song['song_ids'] ?? [$groupId]);
            $waiting = [];
            $last = null;
            foreach ($ids as $id) {
                foreach ($activeBySong[$id] ?? [] as $row) $waiting[] = $row;
                if (isset($latest[$id]) && ($last === null || $latest[$id]['job_id'] > $last['job_id'])) {
                    $last = $latest[$id];
                }
            }
            usort($waiting, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);
            $bySong[$groupId] = ['active' => $waiting, 'latest' => $last];
        }
        return ['active' => $active, 'expertise_by_song' => $bySong];
    }
}
