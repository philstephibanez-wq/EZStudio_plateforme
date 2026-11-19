<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Controller;

use Ezs\Web\Module\Coordination\GlobalQueueLocation;
use Ezs\Web\Module\Coordination\GlobalQueueStore;
use Ezs\Web\Module\Coordination\QueueExecutorAccess;
use Ezs\Web\Module\Coordination\QueueExecutorProtocol;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Explicitly gated executor protocol. No scheduler, child process or success callback. */
final class QueueExecutorController
{
    #[Route('/api/v2/queue/executor/claim', name: 'ezs_queue_executor_claim', methods: ['POST'])]
    public function claim(Request $request): JsonResponse
    {
        $auth = $this->authorize($request);
        if ($auth !== null) {
            return $auth;
        }
        try {
            $data = QueueExecutorProtocol::claimInput(self::body($request));
        } catch (\InvalidArgumentException|\JsonException $e) {
            return self::reply(['error' => 'invalid_executor_envelope'], 422);
        }
        try {
            $job = QueueExecutorProtocol::claim(new GlobalQueueStore(GlobalQueueLocation::path()), $data);
            return self::reply(['schema' => 'ezs.queue.executor.v1', 'job' => $job], 200);
        } catch (\Throwable) {
            return self::reply(['error' => 'queue_unavailable'], 503);
        }
    }

    #[Route('/api/v2/queue/executor/heartbeat', name: 'ezs_queue_executor_heartbeat', methods: ['POST'])]
    public function heartbeat(Request $request): JsonResponse
    {
        $auth = $this->authorize($request);
        if ($auth !== null) {
            return $auth;
        }
        try {
            $data = QueueExecutorProtocol::heartbeatInput(self::body($request));
        } catch (\InvalidArgumentException|\JsonException $e) {
            return self::reply(['error' => 'invalid_executor_envelope'], 422);
        }
        try {
            $beat = QueueExecutorProtocol::heartbeat(new GlobalQueueStore(GlobalQueueLocation::path()), $data);
            return self::reply(['schema' => 'ezs.queue.executor.v1', 'heartbeat' => $beat]);
        } catch (\DomainException|\InvalidArgumentException) {
            return self::reply(['error' => 'lease_not_current'], 409);
        } catch (\Throwable) {
            return self::reply(['error' => 'queue_unavailable'], 503);
        }
    }

    private function authorize(Request $request): ?JsonResponse
    {
        // Never touch the canonical database when disabled, on foreign network peers,
        // or when the dedicated executor credential is not provisioned.
        if (!QueueExecutorAccess::enabled()) {
            return self::reply(['error' => 'executor_api_disabled'], 404);
        }
        if (!in_array((string) $request->server->get('REMOTE_ADDR', ''), ['127.0.0.1', '::1'], true)) {
            return self::reply(['error' => 'local_access_only'], 403);
        }
        if (!QueueExecutorAccess::authorized($request->headers->get('Authorization'))) {
            return self::reply(['error' => 'unauthorized'], 401);
        }
        return null;
    }

    private static function body(Request $request): array
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        if (preg_match('~^application/json(?:\s*;|\s*$)~', $contentType) !== 1) {
            throw new \InvalidArgumentException('json_required');
        }
        $raw = $request->getContent();
        if (strlen($raw) > 2048) {
            throw new \InvalidArgumentException('body_too_large');
        }
        $decoded = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \InvalidArgumentException('object_required');
        }
        return $decoded;
    }

    private static function reply(array $result, int $status = 200): JsonResponse
    {
        return new JsonResponse($result, $status, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
