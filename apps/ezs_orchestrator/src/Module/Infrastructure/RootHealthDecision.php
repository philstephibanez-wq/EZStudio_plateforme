<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Pure watchdog decision: HTTP health must be proven, never inferred from a TCP listener. */
final class RootHealthDecision
{
    public static function decide(?int $httpStatus, bool $rootDesiredRunning): string
    {
        if (!$rootDesiredRunning) return 'ROOT_STOPPED_BY_OPERATOR';
        return $httpStatus === 200 ? 'ROOT_HEALTHY' : 'ROOT_RECOVERY_REQUIRED';
    }
}
