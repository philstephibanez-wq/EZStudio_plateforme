<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

/**
 * Explicit, one-shot, CPU-only queue integration exercise.
 * The supplied callback is synthetic and executes in the caller process.
 * This is NOT a Windows process launcher or an autonomous consumer.
 */
final class SyntheticCpuQueueRunner
{
    public function __construct(private readonly GlobalQueueStore $store)
    {
    }

    /**
     * Run at most one previously enqueued CPU job. Never inspect or claim GPU.
     * A callback failure is persisted as a failed job and reported to caller.
     *
     * @param \Closure(array,\Closure):void $execute synthetic callback; progress callback accepts percent (0..99)
     * @return array{claimed:bool,job_id?:int,state?:string}
     */
    public function runOne(\Closure $execute): array
    {
        $job = $this->store->claim('cpu');
        if ($job === null) {
            return ['claimed' => false];
        }
        $id = (int) $job['id'];
        $token = (string) $job['lease_token'];
        $progress = function (int $percent) use ($id, $token): void {
            $this->store->heartbeat($id, $token, 120, $percent, 'synthetic_cpu');
        };
        try {
            $execute([
                'id' => $id,
                'producer' => $job['producer'],
                'external_id' => $job['external_id'],
                'kind' => $job['kind'],
                'claim_generation' => (int) $job['claim_generation'],
            ], $progress);
        } catch (\Throwable $error) {
            // Never mask the original exception, and never fabricate a successful completion.
            try {
                $this->store->finish($id, $token, false);
            } catch (\Throwable $completionError) {
                throw new \RuntimeException('synthetic_cpu_failure_and_completion_rejected', 0, $error);
            }
            throw $error;
        }
        $finished = $this->store->finish($id, $token, true);
        return ['claimed' => true, 'job_id' => $id, 'state' => $finished['state']];
    }
}
