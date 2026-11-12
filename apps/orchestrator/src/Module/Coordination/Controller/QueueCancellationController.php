<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Controller;

use Ezs\Web\Module\Coordination\GlobalQueueLocation;
use Ezs\Web\Module\Coordination\GlobalQueueStore;
use Ezs\Web\Module\Coordination\QueueRestAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Producer-scoped, loopback-only, idempotent cancellation of unclaimed requests. */
final class QueueCancellationController
{
    #[Route('/api/v2/queue/jobs/{id<\\d+>}/cancel', name: 'ezs_queue_cancel', methods: ['POST'])]
    public function cancel(Request $request, int $id): JsonResponse
    {
        if (!in_array((string) $request->server->get('REMOTE_ADDR', ''), ['127.0.0.1', '::1'], true)) {
            return self::reply(['error' => 'local_access_only'], 403);
        }
        $producer = QueueRestAccess::producerFor($request->headers->get('Authorization'));
        if ($producer === null) return self::reply(['error' => 'unauthorized'], 401);
        if ($request->getContent() !== '' || $id < 1) return self::reply(['error' => 'invalid_cancellation_request'], 422);
        try {
            $store = new GlobalQueueStore(GlobalQueueLocation::path());
            $row = $store->cancelQueued($id, $producer);
            return self::reply(['schema' => 'ezs.queue.cancel.v1', 'job' => [
                'id' => (int) $row['id'], 'producer' => $row['producer'],
                'external_id' => $row['external_id'], 'kind' => $row['kind'],
                'state' => $row['state'], 'claim_generation' => (int) $row['claim_generation'],
            ]]);
        } catch (\DomainException $e) {
            return self::reply(['error' => $e->getMessage()], 409);
        } catch (\Throwable) {
            return self::reply(['error' => 'queue_unavailable'], 503);
        }
    }

    private static function reply(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
