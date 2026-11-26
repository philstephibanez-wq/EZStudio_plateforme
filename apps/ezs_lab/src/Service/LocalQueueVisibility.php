<?php

declare(strict_types=1);

namespace EZSLab\Service;

use PDO;

/** Read-only catalogue projection of LAB-owned PROFILE jobs. */
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
  AND j.kind = 'profile'
ORDER BY j.created_at ASC,j.id ASC
SQL);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{active:array} */
    public function snapshot(array $songs): array
    {
        $pdo = $this->db->pdo();
        $active = [];
        foreach (self::activeRows($pdo) as $position => $row) {
            $item = [
                'job_id' => (int)$row['id'],
                'kind' => (string)$row['kind'],
                'type' => 'PROFILE',
                'status' => (string)$row['status'],
                'progress' => max(0, min(100, (int)$row['progress'])),
                'run_id' => (int)$row['scientific_run_id'],
                'song_id' => (int)$row['song_id'],
                'song_title' => (string)($row['title'] ?? ''),
                'song_artist' => (string)($row['artist'] ?? ''),
                'position' => $position + 1,
            ];
            $active[] = $item;
        }
        return ['active' => $active];
    }
}
