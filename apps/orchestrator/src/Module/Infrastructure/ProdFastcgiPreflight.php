<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

/** Read-only PROD FastCGI candidate preflight. Does not start or stop services. */
final class ProdFastcgiPreflight
{
    public static function inspect(string $repositoryRoot): array
    {
        $root = rtrim($repositoryRoot, '/\\');
        $config = json_decode((string) @file_get_contents($root.'/config/runtime.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($config['schema'] ?? '') !== 'ezs.orchestrator.config.v5') {
            throw new \RuntimeException('RUNTIME_SCHEMA_UNEXPECTED');
        }
        $dev = $config['servers']['dev']['root'] ?? null;
        $prod = $config['servers']['prod']['root'] ?? null;
        if (!is_string($dev) || !is_string($prod) || strcasecmp(str_replace('/', '\\', $dev), str_replace('/', '\\', $prod)) === 0) {
            throw new \RuntimeException('PROD_DEV_ROOT_COLLISION');
        }
        if (strtolower(str_replace('/', '\\', $prod)) !== strtolower('H:\\EZScore')) {
            throw new \RuntimeException('PROD_ROOT_UNEXPECTED');
        }
        if (!is_file($prod.'/public/index.php')) {
            throw new \RuntimeException('PROD_ENTRYPOINT_MISSING');
        }
        $caddy = @file_get_contents($root.'/config/caddy/Caddyfile.prod-internal-candidate');
        if ($caddy === false) throw new \RuntimeException('CADDY_CONFIG_MISSING');
        foreach (['http://127.0.0.1:8511', 'bind 127.0.0.1', 'root * H:/EZScore/public', 'php_fastcgi 127.0.0.1:8512'] as $marker) {
            if (!str_contains($caddy, $marker)) throw new \RuntimeException('PROD_INTERNAL_CADDY_INVALID');
        }
        $maintenanceFile = $prod.'/var/runtime/online-gateway.json';
        $gateway = json_decode((string) @file_get_contents($maintenanceFile), true);
        $maintenance = is_array($gateway) && is_bool($gateway['maintenance'] ?? null)
            ? $gateway['maintenance'] : true;
        return [
            'schema' => 'ezs.prod_fastcgi_preflight.v1',
            'maintenance_requested' => $maintenance,
            'port_8511_occupied' => self::occupied(8511),
            'port_8512_occupied' => self::occupied(8512),
            'candidate_only' => true,
        ];
    }

    private static function occupied(int $port): bool
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 0.4, STREAM_CLIENT_CONNECT);
        if ($socket === false) return false;
        fclose($socket);
        return true;
    }
}
