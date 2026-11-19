<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Controller;

use Ezs\Web\Module\Coordination\GlobalQueueLocation;
use Ezs\Web\Module\Coordination\GlobalQueueStore;
use Ezs\Web\Module\Coordination\QueueAdmission;
use Ezs\Web\Module\Coordination\QueueRestAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Admission only: never claims, dispatches, starts a worker or migrates LAB jobs. */
final class QueueAdmissionController
{
    #[Route('/api/v2/queue/jobs', name: 'ezs_queue_jobs_admit', methods: ['POST'])]
    public function admit(Request $request): JsonResponse
    {
        // Use the direct TCP peer, not forwarded headers controlled by clients.
        $peer = (string)$request->server->get('REMOTE_ADDR', '');
        if (!in_array($peer, ['127.0.0.1', '::1'], true)) {
            return self::reply(['error' => 'local_access_only'], 403);
        }

        $producer = QueueRestAccess::producerFor($request->headers->get('Authorization'));
        if ($producer !== 'lab') {
            return self::reply(['error' => 'unauthorized'], 401);
        }
        $contentType = strtolower((string)$request->headers->get('Content-Type', ''));
        if (!preg_match('~^application/json(?:\s*;|\s*$)~', $contentType)) {
            return self::reply(['error' => 'json_required'], 415);
        }
        $raw = $request->getContent();
        if (strlen($raw) > 8192) {
            return self::reply(['error' => 'envelope_too_large'], 413);
        }
        try {
            $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || array_is_list($payload)) {
                throw new \InvalidArgumentException('admission_object_required');
            }
            $data = QueueAdmission::parse($payload);
        } catch (\JsonException|\InvalidArgumentException $e) {
            return self::reply(['error' => 'invalid_admission_envelope'], 422);
        }

        try {
            $store = new GlobalQueueStore(GlobalQueueLocation::path());
            $row = $store->enqueue(
                $producer,
                $data['external_id'],
                $data['idempotency_key'],
                $data['kind'],
                $data['resource_class'],
                $data['priority'],
            );
        } catch (\DomainException|\InvalidArgumentException) {
            return self::reply(['error' => 'queue_identity_collision'], 409);
        } catch (\Throwable) {
            return self::reply(['error' => 'queue_unavailable'], 503);
        }
        return self::reply([
            'schema' => 'ezs.queue.admission.v1',
            'job' => QueueAdmission::publicRow($row),
        ], 200);
    }

    private static function reply(array $value, int $status): JsonResponse
    {
        return new JsonResponse($value, $status, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
