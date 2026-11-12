<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Messenger;

/** Immutable technical reference only; never serialize business payloads or lease secrets. */
final readonly class QueueJobReference
{
    public function __construct(
        public int $jobId,
        public string $producer,
        public int $claimGeneration = 0,
    ) {
        if ($jobId < 1 || $claimGeneration < 0 || !in_array($producer, ['lab', 'assistant', 'ezscore'], true)) {
            throw new \InvalidArgumentException('invalid_queue_job_reference');
        }
    }
}
