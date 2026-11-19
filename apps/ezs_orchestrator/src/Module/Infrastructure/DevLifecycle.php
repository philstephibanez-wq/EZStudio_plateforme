<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

/**
 * DEV lifecycle migration boundary. Read-only until native Windows identity,
 * process creation with no console, and rollback are verified on MAIN.
 * Never starts, terminates, or repairs any process.
 */
final class DevLifecycle
{
    public const SCHEMA = 'ezs.dev.lifecycle.v2';
    public const PHP_PORT = 8791;
    public const CADDY_PORT = 8790;
    public const CADDY_FACADES = [8502, 8852];
    public const OTHER_DEV_PORTS = [8602, 8701, 8702, 8712, 8862];

    public function __construct(private readonly string $root)
    {
    }

    /** Inspect persisted identity; never infer ownership from a PID alone. */
    public function status(): array
    {
        $file = rtrim($this->root, '/\\').'/runtime/v2-dev/processes.json';
        $data = is_file($file) ? @file_get_contents($file) : false;
        $state = is_string($data) ? json_decode($data, true) : null;
        $phpPid = is_array($state) ? ($state['php_pid'] ?? null) : null;
        $caddyPid = is_array($state) ? ($state['caddy_pid'] ?? null) : null;

        return [
            'schema' => self::SCHEMA,
            'mode' => 'READ_ONLY',
            'state_present' => is_file($file),
            'state_valid' => is_int($phpPid) && $phpPid > 0
                && is_int($caddyPid) && $caddyPid > 0 && $caddyPid !== $phpPid,
            'php_pid_recorded' => is_int($phpPid) && $phpPid > 0 ? $phpPid : null,
            'caddy_pid_recorded' => is_int($caddyPid) && $caddyPid > 0 ? $caddyPid : null,
            'identity_attested' => false,
            'lifecycle_authorized' => false,
            'safe_to_cutover' => false,
        ];
    }

    /**
     * Pure readiness gate for eventual start/stop/repair.
     * Do not accept unverified attestations or a stale PID.
     */
    public function evaluate(string $action, array $evidence): array
    {
        if (!in_array($action, ['start', 'stop', 'repair'], true)) {
            return ['allowed' => false, 'reason' => 'invalid_lifecycle_action'];
        }
        // The current Win32 source probe cannot attest command line or atomic identity.
        // No supplied JSON value may override that engineering limitation.
        return ['allowed' => false, 'reason' => 'native_lifecycle_ownership_not_implemented'];
    }
}
