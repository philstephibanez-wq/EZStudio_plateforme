<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Read-only application status probes on unified FrankenPHP port 8000. */
final class WebSoftPingController
{
    private const TARGETS = [
        'dev' => 'ezstudio_dev',
        'lab' => 'ezs_lab',
        'assistant' => 'ezs_assistant',
        'prod' => 'ezstudio',
    ];

    public static function definitions(): array { return self::TARGETS; }

    public static function allowed(string $env, string $host, string $ip, int $port): bool
    {
        return $env === 'dev' && in_array($port, [8790, 8000], true)
            && in_array($host, ['127.0.0.1', 'localhost', 'orchestrator.ezstudio.local'], true)
            && in_array($ip, ['127.0.0.1','::1'], true);
    }

    public static function classify(?int $http, ?array $payload, ?string $transportError): array
    {
        if ($transportError !== null || $http === null) {
            return ['state'=>'INJOIGNABLE','reason'=>'SOFT_TRANSPORT_ERROR'];
        }
        if ($http === 404 || $http === 405 || $http === 501) {
            return ['state'=>'NON_IMPLEMENTE','reason'=>'SOFT_ENDPOINT_UNAVAILABLE'];
        }
        if ($http !== 200) {
            return ['state'=>'ERREUR_HTTP','reason'=>'SOFT_HTTP_'.$http];
        }
        if (!is_array($payload) || ($payload['schema']??null)!=='ezs.service-status.v1'
            || !in_array($payload['state']??null,['OK','KO'],true)
            || !isset($payload['components']) || !is_array($payload['components'])) {
            return ['state'=>'NON_CONFORME','reason'=>'SOFT_SCHEMA_INVALID'];
        }
        return ($payload['state']==='OK')
            ? ['state'=>'OK','reason'=>'SOFT_OK'] : ['state'=>'KO','reason'=>'SOFT_SERVICE_KO'];
    }

    #[Route('/api/v2/infrastructure/web-soft-ping',name:'ezs_web_soft_ping',methods:['GET'])]
    public function index(Request $request): JsonResponse
    {
        $environment = (string)($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: '');
        if (!self::allowed($environment,$request->getHost(),(string)$request->getClientIp(),$request->getPort())) {
            return new JsonResponse(['error'=>'forbidden'],403,['Cache-Control'=>'no-store']);
        }
        $root = dirname(__DIR__, 6);
        $socket = @stream_socket_client('tcp://127.0.0.1:8000', $errno, $errstr, 0.25, STREAM_CLIENT_CONNECT);
        $portOpen = is_resource($socket);
        if ($portOpen) {
            fclose($socket);
        }
        $sites = [];
        foreach (self::TARGETS as $site => $appId) {
            $start = microtime(true);
            $ready = $portOpen && is_file($root . '/apps/' . $appId . '/config/bundles.php');
            $elapsed = (int)max(1, round((microtime(true) - $start) * 1000));
            $sites[$site] = $ready
                ? ['state' => 'OK', 'reason' => 'SOFT_OK', 'http' => 200, 'elapsed_ms' => $elapsed, 'components' => ['kernel' => 'OK']]
                : ['state' => 'INJOIGNABLE', 'reason' => 'SOFT_TRANSPORT_ERROR', 'http' => null, 'elapsed_ms' => $elapsed, 'components' => null];
        }
        return new JsonResponse(['schema'=>'ezs.web_soft_ping.v1','captured_utc'=>gmdate('c'),'sites'=>$sites],
            200,['Cache-Control'=>'no-store']);
    }
}
