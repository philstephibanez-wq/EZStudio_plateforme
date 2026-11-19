<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

/** A cutover boundary for the canonical queue, never a mutation of legacy jobs. */
final class GlobalQueueActiveWindow
{
    public static function floor(string $queuePath): int
    {
        $canonical = realpath(GlobalQueueLocation::path());
        $tested = realpath($queuePath);
        // Test SQLite files never inherit MAIN cutover settings.
        if ($canonical === false || $tested === false || strcasecmp($canonical, $tested) !== 0) {
            return 1;
        }
        $name = 'EZS_QUEUE_ACTIVE_FROM_ID';
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? '';
        }
        if ($value === '') {
            $file = dirname(__DIR__, 3).'/.env.local';
            if (is_file($file) && !is_link($file)) {
                $found = [];
                foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (preg_match('/^\s*EZS_QUEUE_ACTIVE_FROM_ID\s*=\s*(.*?)\s*$/D', $line, $m)) {
                        $found[] = trim($m[1], " \t\r\n\"'");
                    }
                }
                if (count($found) > 1) throw new \RuntimeException('queue_cutover_ambiguous');
                $value = $found[0] ?? '';
            }
        }
        if ($value === '') return 1;
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,8}$/D', $value) !== 1) {
            throw new \RuntimeException('queue_cutover_invalid');
        }
        return (int)$value;
    }
}
