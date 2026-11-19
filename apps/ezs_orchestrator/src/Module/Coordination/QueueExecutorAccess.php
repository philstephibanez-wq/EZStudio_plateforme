<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

/** Dedicated executor credential, distinct from producer tokens; loopback enforced by controller. */
final class QueueExecutorAccess
{
    public static function enabled(): bool
    {
        return self::configured('EZS_QUEUE_EXECUTOR_API') === '1';
    }

    public static function authorized(?string $authorization): bool
    {
        if (!self::enabled() || !is_string($authorization)
            || preg_match('#^Bearer ([A-Za-z0-9._~+/=\-]{32,256})$#D', $authorization, $matches) !== 1) {
            return false;
        }
        $expected = self::configured('EZS_QUEUE_EXECUTOR_TOKEN');
        if (!preg_match('#^[A-Za-z0-9._~+/=\-]{32,256}$#D', $expected)) {
            return false;
        }
        if (QueueRestAccess::producerFor('Bearer '.$expected) !== null) {
            return false;
        }
        return hash_equals($expected, $matches[1]);
    }

    private static function configured(string $key): string
    {
        foreach ([getenv($key), $_SERVER[$key] ?? null, $_ENV[$key] ?? null] as $value) {
            if (is_string($value) && $value !== '') return $value;
        }
        // Match existing LAB producer-secret resolution when the Symfony runtime
        // is launched without symfony/dotenv. Allowlist exactly these two keys.
        return self::localSetting(dirname(__DIR__, 3).'/.env.local', $key);
    }

    /** Pure fixture-testable parser. Reject symlinks, duplicate assignments and invalid values. */
    public static function localSetting(string $file, string $key): string
    {
        if (!in_array($key, ['EZS_QUEUE_EXECUTOR_API', 'EZS_QUEUE_EXECUTOR_TOKEN', 'EZS_QUEUE_LAB_RESULT_ATTESTATION_KEY'], true)
            || !is_file($file) || is_link($file)) {
            return '';
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) return '';
        $result = null;
        foreach ($lines as $line) {
            if (!is_string($line)
                || preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.*?)\s*$/D', $line, $m) !== 1) {
                continue;
            }
            if ($result !== null) return '';
            $value = trim($m[1]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")) {
                if ($value[strlen($value) - 1] !== $value[0]) return '';
                $value = substr($value, 1, -1);
            }
            if ($key === 'EZS_QUEUE_EXECUTOR_API') {
                if ($value !== '0' && $value !== '1') return '';
            } elseif (preg_match('#^[A-Za-z0-9._~+/=\-]{32,256}$#D', $value) !== 1) {
                return '';
            }
            $result = $value;
        }
        return $result ?? '';
    }
}
