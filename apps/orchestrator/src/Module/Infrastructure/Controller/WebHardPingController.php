<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Observable web ports and local kernel availability on unified FrankenPHP port 8000. */
final class WebHardPingController
{
    private const SITES = [
        'dev' => ['ports' => [8000], 'app' => 'ezstudio_dev', 'host' => 'dev.ezstudio.local', 'page' => 'http://127.0.0.1:8000/'],
        'lab' => ['ports' => [8000], 'app' => 'ezs_lab', 'host' => 'lab.ezstudio.local', 'page' => 'http://127.0.0.1:8000/'],
        'assistant' => ['ports' => [8000], 'app' => 'ezs_assistant', 'host' => 'assistant.ezstudio.local', 'page' => 'http://127.0.0.1:8000/'],
        'prod' => ['ports' => [8000], 'app' => 'ezstudio', 'host' => 'ezstudio.local', 'page' => 'http://127.0.0.1:8000/'],
    ];

    public static function allowed(string $environment, string $host, string $ip): bool
    {
        return $environment === 'dev'
            && in_array($host, ['127.0.0.1', 'localhost', 'orchestrator.ezstudio.local'], true)
            && in_array($ip, ['127.0.0.1', '::1'], true);
    }

    public static function definitions(): array { return self::SITES; }

    private static function tcp(int $port): bool
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errNo, $errStr, 0.4, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) return false;
        fclose($socket);
        return true;
    }

    private static function probeKernel(string $appId, bool $portOpen): array
    {
        if (!$portOpen) {
            return ['status' => null, 'reachable' => false, 'bytes' => null, 'maintenance_marker' => false];
        }
        $root = dirname(__DIR__, 6);
        $appDir = $root . '/apps/' . $appId;
        $exists = is_file($appDir . '/config/bundles.php');
        if (!$exists) {
            return ['status' => 404, 'reachable' => true, 'bytes' => 0, 'maintenance_marker' => false];
        }
        $maintFile = is_file($appDir . '/var/maintenance.flag') || is_file($root . '/var/ezstudio_state/' . $appId . '/maintenance.flag');
        return [
            'status' => $maintFile ? 503 : 200,
            'reachable' => true,
            'bytes' => 1,
            'maintenance_marker' => $maintFile,
        ];
    }

    public static function classify(array $ports, array $http, bool $maintenance): string
    {
        if (!in_array(true, array_map(static fn($v): bool => $v === false, $ports), true)
            && isset($http['status']) && $http['status'] >= 200 && $http['status'] < 400) return 'OK';
        if ($maintenance && (($ports['8000'] ?? false) === true) && ($http['status'] ?? null) === 503) return 'MAINTENANCE';
        return 'ECHEC';
    }

    #[Route('/api/v2/infrastructure/web-hard-ping', name: 'ezs_web_hard_ping', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        if (!self::allowed((string)($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: ''), $request->getHost(), (string)$request->getClientIp()))
            return new JsonResponse(['error' => 'forbidden'], 403);
        $results = [];
        foreach (self::SITES as $site => $definition) {
            $ports = [];
            foreach ($definition['ports'] as $p) $ports[(string)$p] = self::tcp($p);
            $http = self::probeKernel($definition['app'], $ports[(string)$definition['ports'][0]] ?? false);
            $maint = ($site === 'prod' && $http['status'] === 503)
                || ($site === 'dev' && $http['status'] === 503 && $http['maintenance_marker'] === true);
            $results[$site] = ['ports' => $ports, 'http' => $http, 'state' => self::classify($ports, $http, $maint)];
        }
        return new JsonResponse(['schema' => 'ezs.web_hard_ping.v1', 'captured_utc' => gmdate('c'), 'sites' => $results], 200, ['Cache-Control' => 'no-store']);
    }
}