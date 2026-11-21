<?php
declare(strict_types=1);

namespace Shared\Hardware;

/**
 * Strict, non-persistent technical-agent credential resolver for hardware REST.
 * Shared by the Symfony authenticator and the LAB/Assistant clients.
 * Never exposes credentials to browsers, application logs or the inventory JSON.
 * Its fallback is restricted to the root private .env.local, not arbitrary paths.
 */
final class AgentCredentials
{
    private const KEYS = [
        'lab' => 'EZS_QUEUE_LAB_TOKEN',
        'assistant' => 'EZS_QUEUE_ASSISTANT_TOKEN',
        'ezscore' => 'EZS_QUEUE_EZSCORE_TOKEN',
    ];
    private const FORMAT = '~\A[A-Za-z0-9._\~+/=\-]{32,256}\z~D';

    public function __construct(private readonly ?string $privateFile = null) {}

    public function forAgent(string $agent): string
    {
        $key = self::KEYS[$agent] ?? null;
        if ($key === null) return '';
        foreach ([$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)] as $candidate) {
            // A present-but-invalid secret must fail closed; never silently try a fallback.
            if ($candidate !== null && $candidate !== false && $candidate !== '') {
                return is_string($candidate) && self::valid($candidate) ? $candidate : '';
            }
        }
        return $this->fromFile($key);
    }

    public function identify(?string $header): ?string
    {
        if (!is_string($header)
            || preg_match('~\ABearer ([A-Za-z0-9._\~+/=\-]{32,256})\z~D', $header, $match) !== 1) {
            return null;
        }
        $identity = null;
        foreach (array_keys(self::KEYS) as $agent) {
            $credential = $this->forAgent($agent);
            if ($credential !== '' && hash_equals($credential, $match[1])) {
                if ($identity !== null) return null; // credential reused by multiple agents
                $identity = $agent;
            }
        }
        return $identity;
    }

    private static function valid(string $value): bool
    {
        return strlen($value) >= 32 && strlen($value) <= 256
            && preg_match(self::FORMAT, $value) === 1;
    }

    private function fromFile(string $key): string
    {
        $file = $this->privateFile ?? dirname(__DIR__, 3).'/.env.local';
        if (!is_file($file) || is_link($file)) return '';
        $size = @filesize($file);
        if (!is_int($size) || $size > 131072) return '';
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) return '';
        $found = null;
        foreach ($lines as $line) {
            if (!is_string($line) || preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.*?)\s*$/D', $line, $matches) !== 1) continue;
            if ($found !== null) return ''; // duplicates are ambiguous, including empty entries
            $found = trim($matches[1]);
            if (strlen($found) >= 2 && ($found[0] === '"' || $found[0] === "'")) {
                if ($found[strlen($found)-1] !== $found[0]) return '';
                $found = substr($found, 1, -1);
            }
            if (!self::valid($found)) return '';
        }
        return is_string($found) ? $found : '';
    }
}
