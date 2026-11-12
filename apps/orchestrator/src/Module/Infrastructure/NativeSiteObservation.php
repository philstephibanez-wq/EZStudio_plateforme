<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Conservative interpretation of attested native status, with bounded diagnostics. */
final class NativeSiteObservation
{
    private const PORTS = ['dev' => 2, 'lab' => 2, 'assistant' => 3];

    public function __construct(private readonly NativeSiteLifecycle $lifecycle = new NativeSiteLifecycle()) {}

    /** @return array{site:string,intent:string,owned:int,listeners:int,expected_listeners:int} */
    private static function evidence(string $site, string $text): array
    {
        if (!isset(self::PORTS[$site])) throw new \InvalidArgumentException('EZS_NATIVE_OBSERVATION_SITE_REFUSED');
        $lines = preg_split('/\r?\n/', $text) ?: [];
        $matches = null;
        foreach ($lines as $line) {
            if (!str_starts_with($line, 'SITE=')) continue;
            if ($matches !== null || !preg_match('/^SITE=(dev|lab|assistant) INTENT=(RUNNING|STOPPED) OWNED=(\d+) LISTENERS=(\d+)$/D', $line, $m)) {
                throw new \RuntimeException('EZS_NATIVE_OBSERVATION_UNVERIFIED');
            }
            $matches = $m;
        }
        if ($matches === null || $matches[1] !== $site) throw new \RuntimeException('EZS_NATIVE_OBSERVATION_MISSING');
        return [
            'site' => $site,
            'intent' => $matches[2],
            'owned' => (int) $matches[3],
            'listeners' => (int) $matches[4],
            'expected_listeners' => self::PORTS[$site],
        ];
    }

    public static function classify(string $site, string $text): array
    {
        $e = self::evidence($site, $text);
        $state = $e['owned'] === 0 && $e['listeners'] === 0 ? 'STOPPED'
            : ($e['owned'] === 1 && $e['listeners'] === $e['expected_listeners'] ? 'RUNNING' : 'UNVERIFIED');
        return [
            'state' => $state,
            'start' => $state === 'STOPPED',
            'stop' => $state === 'RUNNING',
            'restart' => $state === 'RUNNING',
            'desired' => $e['intent'],
        ];
    }

    /** Safe diagnostic for the DEV-local panel. No process identifiers or commands. */
    public static function diagnose(string $site, string $text): array
    {
        $e = self::evidence($site, $text);
        $permission = self::classify($site, $text);
        $reason = match (true) {
            $permission['state'] === 'STOPPED' => 'NATIVE_ABSENCE_ATTESTED',
            $permission['state'] === 'RUNNING' => 'NATIVE_PRESENCE_ATTESTED',
            $e['owned'] > 1 => 'MULTIPLE_NATIVE_PROCESSES',
            $e['owned'] === 0 && $e['listeners'] > 0 => 'LISTENERS_WITHOUT_NATIVE_PROCESS',
            $e['owned'] === 1 && $e['listeners'] === 0 => 'NATIVE_PROCESS_WITHOUT_LISTENERS',
            $e['owned'] === 1 => 'NATIVE_LISTENER_COUNT_MISMATCH',
            default => 'NATIVE_STATE_UNVERIFIED',
        };
        return [
            'state' => $permission['state'],
            'reason_code' => $reason,
            'desired' => $permission['desired'],
            'observed_native_process_count' => $e['owned'],
            'observed_listener_count' => $e['listeners'],
            'expected_listener_count' => $e['expected_listeners'],
            'can_start' => $permission['start'],
            'can_stop' => $permission['stop'],
        ];
    }

    public function observe(string $site): array
    {
        return self::classify($site, $this->lifecycle->request($site, 'Status'));
    }

    public function observeDiagnostic(string $site): array
    {
        return self::diagnose($site, $this->lifecycle->request($site, 'Status'));
    }
}
