<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Contrôles conservateurs pour un arrêt déjà effectif ou un état sans processus. */
final class EcosystemLifecycleGuard
{
    public const STATES = [
        'runtime/v2-dev/processes.json' => ['php_pid', 'caddy_pid'],
        'runtime/v2-dev/app-backends.json' => ['dev_pid', 'preview_pid'],
        'runtime/v2-dev/lab-assistant.json' => ['lab_pid', 'assistant_pid'],
        'runtime/v2-dev/assistant-ai.json' => ['pid'],
        'runtime/v2-prod/processes.json' => ['php_pid', 'internal_pid', 'gateway_pid', 'edge_pid'],
    ];
    public const PORTS = [8790,8791,8502,8852,8602,8862,8701,8702,8712,8713,8720,8501,8510,8511,8512];
    public const PROD_PORTS = [8501,8510,8511,8512];

    /** @param array<string,array<string,mixed>> $states @param list<int> $livePids @param list<int> $openPorts */
    public static function canClean(array $states, array $livePids, array $openPorts): bool
    {
        if ($openPorts !== [] || $livePids !== []) return false;
        foreach ($states as $name => $state) {
            if (!isset(self::STATES[$name]) || !is_array($state)) return false;
            foreach (self::STATES[$name] as $key) {
                if (isset($state[$key]) && (!is_numeric($state[$key]) || (int)$state[$key] < 0)) return false;
            }
        }
        return true;
    }

    /** @param list<int> $livePids @param list<int> $openPorts */
    public static function prodFullyStopped(array $livePids, array $openPorts): bool
    {
        return $livePids === [] && array_intersect(self::PROD_PORTS, $openPorts) === [];
    }

    /**
     * Attestation d'un FastCGI Orchestrator issu de l'état persistant.
     * L'heure de démarrage de la session (started_utc) n'est pas l'heure de
     * création du processus. Cette dernière est obligatoire avant tout arrêt.
     * Aucune terminaison n'est réalisée par cette fonction.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $process Ligne normalisée de WindowsProcessInventory
     */
    public static function attestRootFastcgi(array $state, array $process): string
    {
        $pid = (int) ($state['php_pid'] ?? 0);
        if ($pid <= 0 || $pid !== (int) ($process['pid'] ?? 0)) {
            return 'pid_mismatch';
        }
        $exe = str_replace('/', '\\', (string) ($process['executable'] ?? ''));
        if (strcasecmp($exe, 'H:\\PHP\\php-8.5-x64\\php-cgi.exe') !== 0) {
            return 'executable_mismatch';
        }
        $command = str_replace('/', '\\', (string) ($process['command'] ?? ''));
        if (!preg_match('/(?:^|\\s)-b\\s+127\\.0\\.0\\.1:8791(?=\\s|$)/i', $command)) {
            return 'command_mismatch';
        }
        // Ne pas confondre l'heure de la session et CreationDate WMI.
        $created = (string) ($state['php_created_wmi'] ?? '');
        if ($created === '' || $created !== (string) ($process['created'] ?? '')) {
            return 'creation_unattested';
        }
        return 'identity_attested';
    }

    /** Portée strictement DEV : aucune lecture ni écriture de runtime/v2-prod. */
    public const DEV_STATES = [
        'runtime/v2-dev/processes.json' => ['php_pid', 'caddy_pid'],
        'runtime/v2-dev/app-backends.json' => ['dev_pid', 'preview_pid'],
        'runtime/v2-dev/lab-assistant.json' => ['lab_pid', 'assistant_pid'],
        'runtime/v2-dev/assistant-ai.json' => ['pid'],
    ];
    public const DEV_PORTS = [8790,8791,8502,8852,8602,8862,8701,8702,8712,8713,8720];

    /**
     * Supprime uniquement des états DEV prouvés obsolètes, lorsque tous les
     * processus et ports DEV concernés sont absents. Ne répare pas un DEV actif.
     * @param (callable(int):bool)|null $isOpen Fournisseur déterministe pour tests.
     */
    public function cleanupDevStale(string $root, WindowsProcessInventory $inventory, ?callable $isOpen = null): int
    {
        $root = rtrim($root, "\\/");
        $lockFile = $root . '/runtime/v2-dev/lifecycle.lock';
        if (!is_dir(dirname($lockFile))) {
            throw new \RuntimeException('EZS_DEV_RUNTIME_MISSING');
        }
        $lock = @fopen($lockFile, 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new \RuntimeException('EZS_DEV_LIFECYCLE_BUSY');
        }
        try {
            $states = [];
            foreach (self::DEV_STATES as $relative => $fields) {
                $file = $root . '/' . $relative;
                if (is_link($file)) throw new \RuntimeException('EZS_DEV_STATE_SYMLINK');
                if (!is_file($file)) continue;
                $state = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($state)) throw new \RuntimeException('EZS_DEV_STATE_INVALID');
                foreach ($fields as $field) {
                    if (!array_key_exists($field, $state)) continue;
                    $pid = filter_var($state[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                    if ($pid === false) throw new \RuntimeException('EZS_DEV_STATE_INVALID_PID');
                }
                $states[$relative] = $state;
            }
            $probe = $isOpen ?? static function (int $port): bool {
                $sock = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $message, 0.2);
                if (!is_resource($sock)) return false;
                fclose($sock);
                return true;
            };
            // Deux observations indépendantes avant toute suppression.
            for ($pass = 0; $pass < 2; ++$pass) {
                $observed = $inventory->collect();
                $byPid = [];
                foreach ($observed as $process) {
                    $pid = $process['pid'];
                    if ($pid === getmypid()) continue;
                    $byPid[$pid] = true;
                    $candidate = $process['classification'] ?? null;
                    if (($candidate['environment'] ?? null) === 'DEV') {
                        throw new \RuntimeException('EZS_DEV_PROCESS_STILL_ACTIVE:' . $pid);
                    }
                    $cmd = strtolower(str_replace('/', '\\', $process['command']));
                    if (str_contains($cmd, 'h:\\ezs_orchestrator\\') || str_contains($cmd, 'h:\\ezscore_dev\\')
                        || str_contains($cmd, 'h:\\ezstudio_lab\\') || str_contains($cmd, 'h:\\ezstudiolab_assistant\\')
                        || str_contains($cmd, 'caddyfile.dev')) {
                        throw new \RuntimeException('EZS_DEV_UNVERIFIED_PROCESS:' . $pid);
                    }
                }
                foreach ($states as $relative => $state) {
                    foreach (self::DEV_STATES[$relative] as $field) {
                        $pid = (int) ($state[$field] ?? 0);
                        if ($pid > 0 && isset($byPid[$pid])) {
                            throw new \RuntimeException('EZS_DEV_RECORDED_PID_ACTIVE:' . $pid);
                        }
                    }
                }
                foreach (self::DEV_PORTS as $port) {
                    if ($probe($port)) throw new \RuntimeException('EZS_DEV_PORT_ACTIVE:' . $port);
                }
            }
            foreach (array_keys($states) as $relative) {
                if (!@unlink($root . '/' . $relative)) {
                    throw new \RuntimeException('EZS_DEV_STALE_DELETE_FAILED');
                }
            }
            return count($states);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
