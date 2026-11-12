<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Read-only application status probes. Never invokes process lifecycle operations. */
final class WebSoftPingController
{
    /** The desired service-status endpoint is a contract to implement in each target. */
    private const TARGETS = [
        'dev' => 'http://127.0.0.1:8602/api/v1/service-status',
        'lab' => 'http://127.0.0.1:8701/api/v1/service-status',
        'assistant' => 'http://127.0.0.1:8713/api/v1/service-status',
        'prod' => 'http://127.0.0.1:8511/api/v1/service-status',
    ];

    public static function definitions(): array { return self::TARGETS; }

    public static function allowed(string $env, string $host, string $ip, int $port): bool
    {
        return $env === 'dev' && $port === 8790
            && in_array($host, ['127.0.0.1','localhost'], true)
            && in_array($ip, ['127.0.0.1','::1'], true);
    }

    /** Pure classification. Unsupported endpoint != failed application. */
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

    /** @return array{http:?int,payload:?array,error:?string,elapsed_ms:int} */
    private static function request(string $url): array
    {
        $start = microtime(true);
        $context = stream_context_create(['http'=>[
            'method'=>'GET', 'timeout'=>1.5,'ignore_errors'=>true,'follow_location'=>0,
            'header'=>"Accept: application/json\r\nConnection: close\r\n",
        ]]);
        $content = @file_get_contents($url, false, $context, 0, 65537);
        $headers = $http_response_header ?? [];
        $http = preg_match('~^HTTP/\\S+ (\\d{3})~',(string)($headers[0]??''),$m)?(int)$m[1]:null;
        $error = $http === null ? 'connection_or_timeout' : null;
        $payload = null;
        if (is_string($content) && strlen($content) <= 65536 && $http === 200) {
            $decoded=json_decode($content,true);
            if (is_array($decoded)) $payload=$decoded;
        }
        return ['http'=>$http,'payload'=>$payload,'error'=>$error,
            'elapsed_ms'=>(int)round((microtime(true)-$start)*1000)];
    }

    #[Route('/api/v2/infrastructure/web-soft-ping',name:'ezs_web_soft_ping',methods:['GET'])]
    public function index(Request $request): JsonResponse
    {
        $environment = (string)($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: '');
        if (!self::allowed($environment,$request->getHost(),(string)$request->getClientIp(),$request->getPort())) {
            return new JsonResponse(['error'=>'forbidden'],403,['Cache-Control'=>'no-store']);
        }
        $sites=[];
        foreach(self::TARGETS as $site=>$url) {
            $observation=self::request($url);
            $classified=self::classify($observation['http'],$observation['payload'],$observation['error']);
            $sites[$site]=[
                'state'=>$classified['state'], 'reason'=>$classified['reason'],
                'http'=>$observation['http'],'elapsed_ms'=>$observation['elapsed_ms'],
                'target_port'=>(int)(parse_url($url,PHP_URL_PORT)?:0),
                'schema'=>$observation['payload']['schema']??null,
            ];
        }
        return new JsonResponse(['schema'=>'ezs.web_soft_ping.v1','captured_utc'=>gmdate('c'),
            'readonly'=>true,'sites'=>$sites],200,['Cache-Control'=>'no-store']);
    }
}
