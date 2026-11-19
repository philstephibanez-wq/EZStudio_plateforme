<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

/**
 * Closed recovery boundary: durable claim ownership AND an independent
 * process observer are mandatory. Nothing schedules or invokes this on MAIN.
 */
final class TrustedLeaseRecovery
{
    /** @param \Closure(array):array $probe Independent process observer, not the producer. */
    public function __construct(
        private readonly ExpiredLeaseSweep $sweep,
        private readonly ExecutorOwnershipRegistry $ownership,
        private readonly \Closure $probe,
    ) {
    }

    /** @return array{inspected:int,recovered:int,blocked:int,stale:int,errors:int} */
    public function run(int $limit = 25): array
    {
        return $this->sweep->run(function (array $candidate): bool {
            $id = (int) ($candidate['id'] ?? 0);
            $generation = (int) ($candidate['claim_generation'] ?? 0);
            if ($id < 1 || $generation < 1) {
                return false;
            }
            $ownership = $this->ownership->forClaim($id, $generation);
            if ($ownership === null) {
                return false;
            }
            return (new ExecutorStopAttestor($this->probe, $ownership))->attest($candidate);
        }, $limit);
    }
}
