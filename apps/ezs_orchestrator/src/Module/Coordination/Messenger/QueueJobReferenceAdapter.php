<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Messenger;

use Ezs\Web\Module\Coordination\QueueRestReadModel;

/**
 * Inert, read-only bridge to the canonical queue. Not a Messenger handler.
 * No service routing, claim, dispatch, ack, callback or worker activation.
 * The caller supplies an explicit SQLite path (synthetic in tests).
 */
final class QueueJobReferenceAdapter
{
    public function inspect(QueueJobReference $reference, string $sqlitePath): array
    {
        $view = new QueueRestReadModel($sqlitePath);
        $row = $view->job($reference->producer, $reference->jobId);
        if ($row === null) {
            throw new \DomainException('queue_job_not_found');
        }
        if ((int) $row['claim_generation'] !== $reference->claimGeneration) {
            throw new \DomainException('queue_job_generation_mismatch');
        }
        // QueueRestReadModel deliberately does not select lease_token or idempotency_key.
        return $row;
    }
}
