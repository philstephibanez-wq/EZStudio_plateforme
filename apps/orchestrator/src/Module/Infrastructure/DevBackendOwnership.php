<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

/** Lecture d'identité Windows sans lanceur intermédiaire et sans mutation. */
final class DevBackendOwnership
{
    private const PORT = 8602;
    private const EXECUTABLE = 'H:\\PHP\\php-8.5-x64\\php-cgi.exe';

    public static function classify(int $listenerPid, array $process): string
    {
        if ($listenerPid <= 0 || (int) ($process['ProcessId'] ?? 0) <= 0) {
            return 'unknown';
        }
        if ((int) $process['ProcessId'] !== $listenerPid) {
            return 'foreign';
        }
        $exe = self::normalise((string) ($process['ExecutablePath'] ?? ''));
        if (strcasecmp($exe, self::EXECUTABLE) !== 0) {
            return 'foreign';
        }
        $command = (string) ($process['CommandLine'] ?? '');
        if (!preg_match('/(?:^|\s)-b\s+127\.0\.0\.1:8602(?=\s|$)/i', $command)) {
            return 'foreign';
        }
        return 'owned_signature';
    }

    /** @return array{port:int,tcp_listening:bool,ownership:string,pid:?int} */
    public function snapshot(): array
    {
        $result = ['port' => self::PORT, 'tcp_listening' => false, 'ownership' => 'absent', 'pid' => null];
        $socket = @stream_socket_client('tcp://127.0.0.1:' . self::PORT, $errno, $error, 0.3, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            return $result;
        }
        fclose($socket);
        $result['tcp_listening'] = true;
        $result['ownership'] = 'unknown';
        // WMI sait identifier des processus, mais pas prouver seul le PID du socket.
        // Ne jamais associer un port à un processus par sa seule ligne de commande.
        return $result;
    }

    /** @return array<string,mixed>|null */
    public static function processByPid(int $pid): ?array
    {
        if ($pid <= 0 || PHP_OS_FAMILY !== 'Windows' || !class_exists('COM')) {
            return null;
        }
        try {
            $wmi = new \COM('winmgmts:{impersonationLevel=impersonate}!\\\\.\\root\\cimv2');
            $rows = $wmi->ExecQuery('SELECT ProcessId, ParentProcessId, CreationDate, ExecutablePath, CommandLine FROM Win32_Process WHERE ProcessId = ' . $pid);
            $found = null;
            $count = 0;
            foreach ($rows as $row) {
                ++$count;
                if ($count !== 1) {
                    return null;
                }
                $found = [
                    'ProcessId' => (int) $row->ProcessId,
                    'ParentProcessId' => (int) $row->ParentProcessId,
                    'CreationDate' => (string) $row->CreationDate,
                    'ExecutablePath' => (string) $row->ExecutablePath,
                    'CommandLine' => (string) $row->CommandLine,
                ];
            }
            return $count === 1 && (int) $found['ProcessId'] === $pid ? $found : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function normalise(string $path): string
    {
        return str_replace('/', '\\', trim($path, " \t\r\n\""));
    }
}
