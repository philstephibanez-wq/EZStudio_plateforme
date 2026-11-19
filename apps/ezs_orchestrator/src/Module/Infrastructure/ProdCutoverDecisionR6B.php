<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Pure policy, independent of Windows effects. Only maintenance may be auto-started. */
final class ProdCutoverDecisionR6B
{
    public static function eligible(string $desired, string $currentMode, array $soft, string $identity, array $ports): bool
    {
        if ($desired !== 'RUNNING' || $currentMode !== 'NORMAL') return false;
        if (($soft['state'] ?? '') !== 'FATAL' || ($soft['code'] ?? '') !== 'SOFT_HTTP_404') return false;
        // A 404 without evidence of a known local runtime must not authorize a destructive stop.
        $owned = $identity === 'ATTESTED';
        $absent = $identity === 'ABSENT' && count(array_filter($ports, static fn ($v) => $v !== 'CLOSED')) === 0;
        return $owned || $absent;
    }
}
