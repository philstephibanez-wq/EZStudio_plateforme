<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Observable web ports and HTTP pages. No PID, app REST ping, or process mutations. */
final class WebHardPingController
{
    private const SITES = [
        'dev'=>['ports'=>[8502,8602],'page'=>'http://127.0.0.1:8502/fr/catalog'],
        'lab'=>['ports'=>[8702,8701],'page'=>'http://127.0.0.1:8702/'],
        'assistant'=>['ports'=>[8712,8713],'page'=>'http://127.0.0.1:8712/'],
        'prod'=>['ports'=>[8501,8510,8511],'page'=>'http://127.0.0.1:8501/fr/catalog'],
    ];

    public static function allowed(string $environment, string $host, string $ip): bool
    {
        return $environment === 'dev'
            && in_array($host,['127.0.0.1','localhost'],true)
            && in_array($ip,['127.0.0.1','::1'],true);
    }

    public static function definitions(): array { return self::SITES; }

    private static function tcp(int $port): bool
    {
        $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errNo,$errStr,0.4,STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) return false;
        fclose($socket);
        return true;
    }

    /** HTTP status on the public WEB page, not /health or any REST service. */
    private static function page(string $url): array
    {
        $ctx=stream_context_create(['http'=>[
            'timeout'=>2,'ignore_errors'=>true,'follow_location'=>0,
            'header'=>"Connection: close\r\n",
        ]]);
        $body=@file_get_contents($url,false,$ctx);
        $headers=$http_response_header??[];
        $status=preg_match('~^HTTP/\\S+ (\\d{3})~',(string)($headers[0]??''),$match)?(int)$match[1]:null;
        return ['status'=>$status,'reachable'=>$status!==null,'bytes'=>is_string($body)?strlen($body):null,
            'maintenance_marker'=>is_string($body) && str_contains($body,'EZScore DEV') && str_contains($body,'Maintenance en cours')];
    }

    public static function classify(array $ports, array $http, bool $maintenance): string
    {
        if (!in_array(true,array_map(static fn($v):bool=>$v===false,$ports),true)
            && isset($http['status']) && $http['status']>=200 && $http['status']<400) return 'OK';
        if ($maintenance && (($ports['8501']??false) === true || ($ports['8502']??false) === true) && ($http['status']??null)===503) return 'MAINTENANCE';
        return 'ECHEC';
    }

    #[Route('/api/v2/infrastructure/web-hard-ping',name:'ezs_web_hard_ping',methods:['GET'])]
    public function index(Request $request): JsonResponse
    {
        if (!self::allowed((string)($_SERVER['APP_ENV']??getenv('APP_ENV')?:''),$request->getHost(),(string)$request->getClientIp()))
            return new JsonResponse(['error'=>'forbidden'],403);
        $results=[];
        foreach(self::SITES as $site=>$definition){
            $ports=[];
            foreach($definition['ports'] as $port) $ports[(string)$port]=self::tcp($port);
            $http=self::page($definition['page']);
            // Maintenance is a web operating mode, never an assumption from a PID record.
            $maint=($site==='prod' && $http['status']===503)
                || ($site==='dev' && $http['status']===503 && $http['maintenance_marker']===true);
            $results[$site]=['ports'=>$ports,'http'=>$http,'state'=>self::classify($ports,$http,$maint)];
        }
        return new JsonResponse(['schema'=>'ezs.web_hard_ping.v1','captured_utc'=>gmdate('c'),
            'sites'=>$results],200,['Cache-Control'=>'no-store']);
    }
}
