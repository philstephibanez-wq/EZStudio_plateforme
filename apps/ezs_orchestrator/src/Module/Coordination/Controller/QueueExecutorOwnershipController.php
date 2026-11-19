<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Controller;

use Ezs\Web\Module\Coordination\ExecutorOwnershipRegistry;
use Ezs\Web\Module\Coordination\GlobalQueueLocation;
use Ezs\Web\Module\Coordination\QueueExecutorAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Exact-claim immutable identity register. Does not start or stop processes. */
final class QueueExecutorOwnershipController
{
    #[Route('/api/v2/queue/executor/ownership', name: 'ezs_queue_executor_ownership', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        if (!QueueExecutorAccess::enabled()) {
            return $this->reply(['error' => 'executor_api_disabled'], 404);
        }
        if (!in_array((string) $request->server->get('REMOTE_ADDR', ''), ['127.0.0.1', '::1'], true)) {
            return $this->reply(['error' => 'local_access_only'], 403);
        }
        if (!QueueExecutorAccess::authorized($request->headers->get('Authorization'))) {
            return $this->reply(['error' => 'unauthorized'], 401);
        }
        try {
            $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
            if (preg_match('~^application/json(?:\s*;|\s*$)~', $contentType) !== 1
                || strlen($request->getContent()) > 2048) {
                throw new \InvalidArgumentException('invalid_ownership_request');
            }
            $decoded = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new \InvalidArgumentException('invalid_ownership_object');
            }
            $keys = array_keys($decoded);
            $allowed = ['job_id', 'claim_generation', 'lease_token', 'node', 'pid', 'created_at', 'instance_id'];
            sort($keys);
            sort($allowed);
            if ($keys !== $allowed || !is_int($decoded['job_id']) || !is_int($decoded['claim_generation'])
                || !is_int($decoded['pid']) || !is_string($decoded['lease_token'])
                || !is_string($decoded['node']) || !is_string($decoded['created_at'])
                || !is_string($decoded['instance_id'])) {
                throw new \InvalidArgumentException('invalid_ownership_fields');
            }
        } catch (\InvalidArgumentException|\JsonException) {
            return $this->reply(['error' => 'invalid_ownership_envelope'], 422);
        }
        try {
            $registry = new ExecutorOwnershipRegistry(GlobalQueueLocation::path());
            $registered = $registry->register(
                $decoded['job_id'], $decoded['claim_generation'], $decoded['lease_token'],
                $decoded['node'], $decoded['pid'], $decoded['created_at'], $decoded['instance_id'],
            );
            return $this->reply([
                'schema' => 'ezs.queue.executor.ownership.v1',
                'job_id' => (int) $registered['id'],
                'claim_generation' => (int) $registered['claim_generation'],
                'registered' => true,
            ]);
        } catch (\DomainException|\InvalidArgumentException) {
            return $this->reply(['error' => 'ownership_not_current_or_collision'], 409);
        } catch (\Throwable) {
            return $this->reply(['error' => 'ownership_unavailable'], 503);
        }
    }

    private function reply(array $body, int $code = 200): JsonResponse
    {
        return new JsonResponse($body, $code, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
