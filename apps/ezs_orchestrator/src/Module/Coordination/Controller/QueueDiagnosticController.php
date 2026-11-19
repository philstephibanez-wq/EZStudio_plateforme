<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Controller;

use Ezs\Web\Module\Coordination\GlobalQueueLocation;
use PDO;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** DEV only. Canonical SQLite snapshot; no lease, process, token or private payload. */
final class QueueDiagnosticController
{
    #[Route('/api/v2/queue/dev-diagnostic', name: 'ezs_queue_dev_diagnostic', methods: ['GET'])]
    public function diagnostic(Request $request): JsonResponse
    {
        $environment = (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '');
        if ($environment !== 'dev' || !in_array($request->getHost(), ['127.0.0.1', 'localhost'], true)
            || !in_array((string) $request->server->get('REMOTE_ADDR', ''), ['127.0.0.1', '::1'], true)) {
            return $this->reply(['error'=>'dev_loopback_only'], 403);
        }
        $path = GlobalQueueLocation::path();
        if (!is_file($path)) return $this->reply(['schema'=>'ezs.queue.diagnostic.v1','available'=>false,'reason'=>'queue_database_missing'], 200);
        try {
            $uri = 'file:'.str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], str_replace('\\', '/', $path)).'?mode=ro';
            $db = new PDO('sqlite:'.$uri, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>2]);
            $db->exec('PRAGMA query_only=ON');
            $rows = $db->query("SELECT id,producer,external_id,kind,state,claim_generation,created_at,updated_at FROM global_queue_jobs ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            $counts = ['queued'=>0,'running'=>0,'completed'=>0,'failed'=>0,'cancelled'=>0];
            foreach ($db->query('SELECT state,COUNT(*) AS quantity FROM global_queue_jobs GROUP BY state')->fetchAll(PDO::FETCH_ASSOC) as $item) {
                if (isset($counts[$item['state']])) $counts[$item['state']] = (int) $item['quantity'];
            }
            $events = $db->query('SELECT event_id,job_id,event_type,progress_percent,phase,created_at FROM global_queue_events ORDER BY event_id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
            return $this->reply([
                'schema'=>'ezs.queue.diagnostic.v1','source'=>'orchestrator_global_sqlite',
                'observed_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'available'=>true,
                'counts'=>$counts,'jobs'=>$rows,'events'=>$events,
                'limits'=>['jobs'=>100,'events'=>100,'event_messages_excluded'=>true,'executor_identity_excluded'=>true],
            ], 200);
        } catch (\Throwable) {
            return $this->reply(['schema'=>'ezs.queue.diagnostic.v1','available'=>false,'reason'=>'read_failed'], 200);
        }
    }
    private function reply(array $body, int $status): JsonResponse
    {
        return new JsonResponse($body, $status, [
            'Cache-Control'=>'private, no-store', 'X-Content-Type-Options'=>'nosniff',
            'X-Robots-Tag'=>'noindex, nofollow',
        ]);
    }
}
