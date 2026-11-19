<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

/** Shared production tick policy; injected effects permit a boot test without touching Windows. */
final class ServiceSupervisorCycle
{
    /**
     * @param array<string, string> $desired
     * @param array<string, array{count:int,next:int}> $retry
     * @param callable(string):array $start Actual lifecycle action or test double
     * @param callable(string,string,string):void $log
     * @param callable(array):void $persistRetry
     * @param callable(string):mixed $emit
     */
    public static function run(
        array $desired,
        array $retry,
        int $now,
        callable $start,
        callable $log,
        callable $persistRetry,
        callable $emit
    ): int {
        $failed = [];
        foreach (ServiceSupervisorCommand::planned($desired) as $group) {
            $next = (int) ($retry[$group]['next'] ?? 0);
            if ($next > $now) {
                $emit('EZS_SUPERVISOR_BACKOFF_'.$group.' until='.$next);
                continue;
            }
            try {
                $started = $start($group);
            } catch (\Throwable $failure) {
                // Only a failure of the lifecycle action is a START_FAILED.
                // An observability or persistence failure must propagate as-is:
                // the process may already be running, so do not schedule recovery.
                $count = (int) ($retry[$group]['count'] ?? 0) + 1;
                $retry[$group] = ['count'=>$count, 'next'=>ServiceSupervisorCommand::nextAttempt($count, $now)];
                $log('ERROR', $group, 'START_FAILED '.$failure->getMessage());
                $emit('EZS_SUPERVISOR_RETRY_'.$group.' after='.$retry[$group]['next']);
                $failed[] = $group;
                $persistRetry($retry);
                continue;
            }
            if ($started !== []) $log('INFO', $group, 'RECOVERED '.implode(',', array_keys($started)));
            unset($retry[$group]);
            $emit('EZS_SUPERVISOR_OK_'.$group.' started='.count($started));
            $persistRetry($retry);
        }
        return $failed === [] ? 0 : 1;
    }
}
