<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

/** Compatibility boundary for explicit, attested recovery; never timer-driven. */
final class ExpiredLeaseSweep
{
    public function __construct(private readonly GlobalQueueStore $store) {}

    /**
     * @param \Closure(array):bool $attestStopped
     * @return array{inspected:int,recovered:int,blocked:int,stale:int,errors:int}
     */
    public function run(\Closure $attestStopped, int $limit = 25): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('invalid_sweep_limit');
        }
        $result = ['inspected'=>0,'recovered'=>0,'blocked'=>0,'stale'=>0,'errors'=>0];
        foreach ($this->store->runningClaims($limit) as $candidate) {
            ++$result['inspected'];
            try {
                $this->store->recoverStopped(
                    (int)$candidate['id'], (int)$candidate['claim_generation'], $attestStopped
                );
                ++$result['recovered'];
            } catch (\DomainException $e) {
                if ($e->getMessage() === 'executor_not_attested_stopped') {
                    ++$result['blocked'];
                } elseif (in_array($e->getMessage(), ['claim_not_running_current', 'recovery_generation_changed'], true)) {
                    ++$result['stale'];
                } else {
                    throw $e;
                }
            }
        }
        return $result;
    }
}
