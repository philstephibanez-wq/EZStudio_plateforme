<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure\Controller;

use Ezs\Web\Module\Infrastructure\ProdModeService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProdOperationsController
{
    private function allowed(Request $request): bool
    {
        return ($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: '') === 'dev'
            && in_array($request->getHost(), ['127.0.0.1', 'localhost'], true)
            && in_array($request->getClientIp(), ['127.0.0.1', '::1'], true);
    }

    private function sessionToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict','use_strict_mode'=>true]);
        }
        if (!isset($_SESSION['ezs_prod_csrf'])) {
            $_SESSION['ezs_prod_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['ezs_prod_csrf'];
    }

    private function backendReady(): bool
    {
        $context=stream_context_create(['http'=>[
            'timeout'=>2,
            'ignore_errors'=>true,
            'header'=>"Host: ezscore.logandplay.com\r\n",
        ]]);
        $body=@file_get_contents('http://127.0.0.1:8511/fr/catalog',false,$context);
        $status=$http_response_header[0] ?? '';
        return is_string($body) && strlen($body)>0
            && preg_match('~^HTTP/\\S+ [234]\\d\\d~',$status)===1;
    }

    #[Route('/api/v2/infrastructure/prod-control', name:'ezs_prod_control_read', methods:['GET'])]
    public function read(Request $request): JsonResponse
    {
        if (!$this->allowed($request)) return new JsonResponse(['error'=>'forbidden'],403);
        $t0=hrtime(true);
        $token=$this->sessionToken();
        $t1=hrtime(true);
        $config='H:/EZScore/var/runtime/online-gateway.json';
        $desired=null;
        if (is_file($config)) {
            $data=json_decode((string)file_get_contents($config),true);
            if (is_array($data) && is_bool($data['maintenance'] ?? null)) $desired=$data['maintenance'];
        }
        $t2=hrtime(true);
        $gateway=null;
        $context=stream_context_create(['http'=>['timeout'=>1,'ignore_errors'=>true]]);
        $raw=@file_get_contents('http://127.0.0.1:8510/__ezscore_gateway_status',false,$context);
        if (is_string($raw)) {
            $data=json_decode($raw,true);
            if (is_array($data) && ($data['protocol'] ?? '')==='ezscore.online-gateway.v3' && ($data['ok'] ?? false)===true) $gateway=$data;
        }
        $t3=hrtime(true);
        $socket=@stream_socket_client('tcp://127.0.0.1:8501',$errno,$errstr,0.5);
        $caddy=is_resource($socket);
        if ($caddy) fclose($socket);
        $t4=hrtime(true);
        $backend=$gateway!==null && $caddy && $this->backendReady();
        $t5=hrtime(true);
        return new JsonResponse([
            'schema'=>'ezs.prod_control.v1',
            'timing_ms'=>[
                'session'=>round(($t1-$t0)/1000000,2),
                'config'=>round(($t2-$t1)/1000000,2),
                'gateway'=>round(($t3-$t2)/1000000,2),
                'caddy'=>round(($t4-$t3)/1000000,2),
                'backend'=>round(($t5-$t4)/1000000,2),
                'total'=>round(($t5-$t0)/1000000,2),
            ],
            'gateway_online'=>$gateway!==null,
            'caddy_listening'=>$caddy,
            'backend_ready'=>$backend,
            'maintenance'=>$gateway['maintenance'] ?? null,
            'desired_maintenance'=>$desired,
            'csrf'=>$token,
            'actions'=>[
                'maintenance'=>$gateway!==null && $caddy && ($gateway['maintenance'] ?? null)===false,
                'normal'=>$backend && ($gateway['maintenance'] ?? null)===true,
            ],
            'note'=>$backend?'Backend Symfony accessible depuis Caddy interne.':'Mode normal indisponible : backend Symfony non vérifié.',
        ],200,['Cache-Control'=>'no-store']);
    }

    #[Route('/api/v2/infrastructure/prod-control/{action}', name:'ezs_prod_control_action', requirements:['action'=>'maintenance|normal'], methods:['POST'])]
    public function action(Request $request,string $action,ProdModeService $prodMode): JsonResponse
    {
        if (!$this->allowed($request)) return new JsonResponse(['error'=>'forbidden'],403);
        $origin=$request->headers->get('Origin','');
        if (!in_array($origin,['http://127.0.0.1:8790','http://localhost:8790'],true)) return new JsonResponse(['error'=>'invalid_origin'],403);
        $token=$this->sessionToken();
        if (!hash_equals($token,(string)$request->headers->get('X-EZS-CSRF',''))) return new JsonResponse(['error'=>'csrf'],403);
        $expected=$action==='normal'?'NORMAL':'MAINTENANCE';
        if ((string)$request->request->get('confirmation','')!==$expected) return new JsonResponse(['error'=>'confirmation_required'],400);
        try {
            $result=$prodMode->apply($action);
            return new JsonResponse($result,200,['Cache-Control'=>'no-store']);
        } catch (\RuntimeException $failure) {
            return new JsonResponse(['error'=>$failure->getMessage()],503,['Cache-Control'=>'no-store']);
        }

    }
}
