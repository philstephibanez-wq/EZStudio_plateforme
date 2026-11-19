<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Controller;

use Ezs\Web\Module\Coordination\GlobalQueueLocation;
use Ezs\Web\Module\Coordination\GlobalQueueStore;
use Ezs\Web\Module\Coordination\QueueExecutorAccess;
use Ezs\Web\Module\Coordination\QueueResultReceipt;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Persisted-result acknowledgement with generation and token fencing, no expiration. */
final class QueueExecutorFinishController
{
    #[Route('/api/v2/queue/executor/finish', name: 'ezs_queue_executor_finish', methods: ['POST'])]
    public function finish(Request $request): JsonResponse
    {
        if (!QueueExecutorAccess::enabled()) {
            return self::reply(['error' => 'executor_api_disabled'], 404);
        }
        if (!in_array((string) $request->server->get('REMOTE_ADDR', ''), ['127.0.0.1', '::1'], true)) {
            return self::reply(['error' => 'local_access_only'], 403);
        }
        if (!QueueExecutorAccess::authorized($request->headers->get('Authorization'))) {
            return self::reply(['error' => 'unauthorized'], 401);
        }
        $secret = self::attestationSecret();
        if (strlen($secret) < 32 || strlen($secret) > 256) {
            return self::reply(['error' => 'persistence_attestation_not_configured'], 503);
        }
        try {
            $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
            if (preg_match('~^application/json(?:\s*;|\s*$)~', $contentType) !== 1) {
                throw new \InvalidArgumentException('json_required');
            }
            $raw = $request->getContent();
            if (strlen($raw) > 2048) {
                throw new \InvalidArgumentException('payload_too_large');
            }
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new \InvalidArgumentException('object_required');
            }
            $data = QueueResultReceipt::parse($decoded);
        } catch (\InvalidArgumentException|\JsonException) {
            return self::reply(['error' => 'invalid_result_envelope'], 422);
        }
        try {
            $store = new GlobalQueueStore(GlobalQueueLocation::path());
            $current = $store->byId($data['job_id']);
            if ($current === null || $current['state'] !== 'running'
                || (int) $current['claim_generation'] !== $data['claim_generation']
                || !hash_equals((string) $current['lease_token'], $data['lease_token'])) {
                return self::reply(['error' => 'lease_not_current'], 409);
            }
            if (!QueueResultReceipt::verify($data, $current, $secret)) {
                return self::reply(['error' => 'invalid_persistence_attestation'], 422);
            }
            $store->finish($data['job_id'], $data['lease_token'], $data['receipt']['result'] === 'completed');
            return self::reply([
                'schema' => 'ezs.queue.executor.finish.v1',
                'job_id' => $data['job_id'],
                'state' => $data['receipt']['result'],
            ]);
        } catch (\DomainException) {
            return self::reply(['error' => 'lease_not_current'], 409);
        } catch (\Throwable) {
            return self::reply(['error' => 'queue_unavailable'], 503);
        }
    }

    private static function attestationSecret(): string
    {
        foreach ([getenv('EZS_QUEUE_LAB_RESULT_ATTESTATION_KEY'),
            $_SERVER['EZS_QUEUE_LAB_RESULT_ATTESTATION_KEY'] ?? null,
            $_ENV['EZS_QUEUE_LAB_RESULT_ATTESTATION_KEY'] ?? null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return QueueExecutorAccess::localSetting(dirname(__DIR__, 4).'/.env.local', 'EZS_QUEUE_LAB_RESULT_ATTESTATION_KEY');
    }

    private static function reply(array $payload, int $status = 200): JsonResponse
    {
        return new JsonResponse($payload, $status, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
