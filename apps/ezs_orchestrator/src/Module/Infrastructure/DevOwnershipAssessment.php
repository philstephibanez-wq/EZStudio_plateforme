<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

/**
 * Pure DEV process identity comparison. Evidence must come from a trusted
 * local probe; untrusted JSON is never sufficient to authorize lifecycle.
 */
final class DevOwnershipAssessment
{
    public const SCHEMA = 'ezs.dev.ownership.assessment.v1';

    public function assess(array $state, array $php, array $caddy): array
    {
        $phpPid = $state['php_pid'] ?? null;
        $caddyPid = $state['caddy_pid'] ?? null;
        $stateValid = is_int($phpPid) && $phpPid > 0
            && is_int($caddyPid) && $caddyPid > 0
            && $phpPid !== $caddyPid;

        $phpMatches = $stateValid && $this->matches($php, $phpPid, 8791, 'php-cgi.exe');
        $caddyMatches = $stateValid && $this->matches($caddy, $caddyPid, 8790, 'caddy.exe');

        return [
            'schema' => self::SCHEMA,
            'mode' => 'READ_ONLY',
            'state_valid' => $stateValid,
            'php_identity_consistent' => $phpMatches,
            'caddy_identity_consistent' => $caddyMatches,
            'all_snapshots_consistent' => $phpMatches && $caddyMatches,
            'command_line_attested' => false,
            'atomic_process_ownership_attested' => false,
            'lifecycle_authorized' => false,
            'safe_to_cutover' => false,
        ];
    }

    private function matches(array $snapshot, int $pid, int $port, string $basename): bool
    {
        if (($snapshot['schema'] ?? null) !== 'ezs.windows.attestation.v1'
            || ($snapshot['verified'] ?? null) !== true
            || ($snapshot['pid'] ?? null) !== $pid
            || ($snapshot['port'] ?? null) !== $port
            || ($snapshot['command_line_attested'] ?? null) !== false
            || ($snapshot['lifecycle_authorized'] ?? null) !== false) {
            return false;
        }
        $path = $snapshot['image_path'] ?? null;
        $created = $snapshot['creation_filetime'] ?? null;
        if (!is_string($path) || preg_match('~^[a-zA-Z]:[\\\\/]~', $path) !== 1
            || !is_string($created) || preg_match('/^[1-9][0-9]*$/D', $created) !== 1) {
            return false;
        }
        return strtolower(basename(str_replace('\\', '/', $path))) === $basename;
    }
}
