<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

final class QueueRestAccess
{
    private const KEYS = [
        'lab' => 'EZS_QUEUE_LAB_TOKEN',
        'assistant' => 'EZS_QUEUE_ASSISTANT_TOKEN',
        'ezscore' => 'EZS_QUEUE_EZSCORE_TOKEN',
    ];

    public static function producerFor(?string $header, ?array $values = null): ?string
    {
        if (!is_string($header) || preg_match('/^Bearer ([A-Za-z0-9._~+\/=-]{32,256})$/D', $header, $matches) !== 1) {
            return null;
        }

        $found = null;
        foreach (self::KEYS as $producer => $key) {
            $expected = $values !== null
                ? (string) ($values[$producer] ?? '')
                : self::configuredToken($producer, $key);
            if (strlen($expected) < 32 || strlen($expected) > 256) {
                continue;
            }
            if (hash_equals($expected, $matches[1])) {
                if ($found !== null) {
                    return null;
                }
                $found = $producer;
            }
        }
        return $found;
    }

    private static function configuredToken(string $producer, string $key): string
    {
        foreach ([getenv($key), $_SERVER[$key] ?? null, $_ENV[$key] ?? null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        if ($producer !== 'lab') {
            return '';
        }
        return self::tokenFromLocalFile(dirname(__DIR__, 3).'/.env.local', $key);
    }

    /** Single allowlisted key. No global dotenv imports, process mutations or secret logging. */
    public static function tokenFromLocalFile(string $path, string $key): string
    {
        if ($key !== 'EZS_QUEUE_LAB_TOKEN' || !is_file($path) || is_link($path)) {
            return '';
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return '';
        }
        $result = null;
        foreach ($lines as $line) {
            if (!is_string($line) || preg_match('/^\s*EZS_QUEUE_LAB_TOKEN\s*=\s*(.*?)\s*$/D', $line, $m) !== 1) {
                continue;
            }
            if ($result !== null) {
                return ''; // ambiguous declaration: fail closed
            }
            $value = trim($m[1]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")) {
                if ($value[strlen($value) - 1] !== $value[0]) {
                    return '';
                }
                $value = substr($value, 1, -1);
            }
            if (preg_match('/^[A-Za-z0-9._~+\/=-]{32,256}$/D', $value) !== 1) {
                return '';
            }
            $result = $value;
        }
        return $result ?? '';
    }
}
