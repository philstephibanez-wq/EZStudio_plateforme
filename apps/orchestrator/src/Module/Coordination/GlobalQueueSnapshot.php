<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Coordination;

use PDO;

/** Read-only observation of the existing Orchestrator registry, never a second queue. */
final class GlobalQueueSnapshot
{
    public static function read(?string $path = null): array
    {
        $path ??= (string) (getenv('EZS_QUEUE_REGISTRY_PATH') ?: '');
        if ($path === '' || !is_file($path)) {
            return ['available'=>false, 'reason'=>'registry_not_configured_or_missing', 'jobs'=>[]];
        }
        try {
            $pdo = new PDO('sqlite:file:'.str_replace('?', '%3F', str_replace('#','%23', $path)).'?mode=ro',
                null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>2]);
            $pdo->exec('PRAGMA query_only=ON');
            $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='jobs'")->fetchColumn();
            if ($exists !== 'jobs') {
                return ['available'=>false,'reason'=>'registry_schema_missing','jobs'=>[]];
            }
            $rows=$pdo->query("SELECT target, job_id, kind, priority, state, updated_at FROM jobs ORDER BY priority DESC, created_at ASC, target ASC, job_id ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            return ['available'=>true,'reason'=>null,'jobs'=>$rows,'scope'=>'orchestrator_registry_only'];
        } catch (\Throwable) {
            return ['available'=>false,'reason'=>'registry_unavailable_or_invalid','jobs'=>[]];
        }
    }
}
