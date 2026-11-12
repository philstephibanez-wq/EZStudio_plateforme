<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Coordination;

use PDO;

/** Canonical global-queue read model: never initializes or changes SQLite. */
final class GlobalQueueDashboard
{
    public static function read(?string $path = null): array
    {
        $path ??= GlobalQueueLocation::path();
        if (!is_file($path)) return ['available'=>false,'reason'=>'queue_database_missing','jobs'=>[]];
        try {
            $uri = 'file:'.str_replace(['%','?','#'],['%25','%3F','%23'],str_replace('\\','/',$path)).'?mode=ro';
            $db = new PDO('sqlite:'.$uri, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>2]);
            $db->exec('PRAGMA query_only=ON');
            $sql = "SELECT j.id,j.producer,j.external_id,j.kind,j.priority,j.resource_class,j.state,
                    j.claim_generation,j.lease_until,j.created_at,j.updated_at,
                    (SELECT e.progress_percent FROM global_queue_events e
                     WHERE e.job_id=j.id AND e.progress_percent IS NOT NULL ORDER BY e.event_id DESC LIMIT 1) AS progress_percent,
                    (SELECT e.phase FROM global_queue_events e
                     WHERE e.job_id=j.id ORDER BY e.event_id DESC LIMIT 1) AS phase,
                    (SELECT e.created_at FROM global_queue_events e
                     WHERE e.job_id=j.id ORDER BY e.event_id DESC LIMIT 1) AS last_event_at
                    FROM global_queue_jobs j
                    ORDER BY CASE j.state WHEN 'running' THEN 0 WHEN 'queued' THEN 1 ELSE 2 END,
                    j.priority DESC,j.created_at ASC,j.id ASC LIMIT 100";
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $now = time();
            $counts = ['queued'=>0,'running'=>0,'completed'=>0,'failed'=>0,'cancelled'=>0,'expired'=>0];
            foreach ($rows as &$row) {
                $state = (string)$row['state'];
                if (isset($counts[$state])) ++$counts[$state];
                $row['lease_expired'] = $state === 'running'
                    && $row['lease_until'] !== null && (int)$row['lease_until'] < $now;
                if ($row['lease_expired']) ++$counts['expired'];
            }
            unset($row);
            // Song metadata remains owned by LAB. A failed read never changes the queue.
            $titles = QueueSongLabels::read();
            foreach ($rows as &$row) {
                $row['song_title'] = null;
                if ($row['producer'] !== 'lab'
                    || preg_match('/^analysis_job:([1-9][0-9]*)$/D', (string)$row['external_id'], $match) !== 1) {
                    continue;
                }
                $row['song_title'] = $titles[(int)$match[1]] ?? null;
            }
            unset($row);
            return ['available'=>true,'reason'=>null,'jobs'=>$rows,'counts'=>$counts,'observed_at'=>$now];
        } catch (\Throwable) {
            return ['available'=>false,'reason'=>'queue_read_unavailable','jobs'=>[]];
        }
    }
}
