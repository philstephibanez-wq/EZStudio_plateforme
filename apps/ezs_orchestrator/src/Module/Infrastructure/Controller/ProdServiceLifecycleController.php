<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure\Controller;

use Ezs\Web\Module\Infrastructure\DesiredServiceState;
use Ezs\Web\Module\Infrastructure\NativeWindowsLifecycle;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Commandes locales de processus PROD ; aucune modification de mode maintenance. */
final class ProdServiceLifecycleController
{
    private function allowed(Request $request):bool
    {
        return DevGroupOperationsController::allowed(
            (string)($_SERVER['APP_ENV']??getenv('APP_ENV')?:''),
            $request->getHost(),
            (string)$request->getClientIp()
        );
    }

    private function token():string
    {
        if(session_status()!==PHP_SESSION_ACTIVE)
            session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict','use_strict_mode'=>true]);
        if(!isset($_SESSION['ezs_prod_csrf']))$_SESSION['ezs_prod_csrf']=bin2hex(random_bytes(32));
        return (string)$_SESSION['ezs_prod_csrf'];
    }

    public static function permission(array $states):array
    {
        $wanted=array_keys(NativeWindowsLifecycle::prodSpecs());
        $vals=[];
        foreach($wanted as $name){
            $state=$states[$name]??null;
            if(!in_array($state,['ATTESTED','ABSENT'],true))
                return ['state'=>'UNVERIFIED','start'=>false,'restart'=>false,'stop'=>false];
            $vals[]=$state;
        }
        $up=count(array_filter($vals,static fn($x)=>$x==='ATTESTED'));
        $total=count($vals);
        return [
            'state'=>$up===$total?'RUNNING':($up===0?'STOPPED':'PARTIAL'),
            'start'=>$up<$total,
            'restart'=>$up===$total,
            'stop'=>$up>0,
        ];
    }

    #[Route('/api/v2/infrastructure/prod-services',name:'ezs_prod_services_read',methods:['GET'])]
    public function read(Request $request):JsonResponse
    {
        if(!$this->allowed($request))return new JsonResponse(['error'=>'forbidden'],403);
        try{
            $svc=new NativeWindowsLifecycle(profile:'prod');
            return new JsonResponse([
                'schema'=>'ezs.prod_services.v1',
                'csrf'=>$this->token(),
                'permission'=>self::permission($svc->status()),
                'desired'=>(new DesiredServiceState())->get('prod'),
            ],200,['Cache-Control'=>'no-store']);
        }catch(\Throwable $error){
            return new JsonResponse(['error'=>$error->getMessage()],503,['Cache-Control'=>'no-store']);
        }
    }

    #[Route('/api/v2/infrastructure/prod-services/{action}',name:'ezs_prod_services_action',requirements:['action'=>'start|stop|restart'],methods:['POST'])]
    public function action(Request $request,string $action):JsonResponse
    {
        if(!$this->allowed($request))return new JsonResponse(['error'=>'forbidden'],403);
        if(!in_array($request->headers->get('Origin',''),['http://127.0.0.1:8790','http://localhost:8790'],true))
            return new JsonResponse(['error'=>'invalid_origin'],403);
        if(!hash_equals($this->token(),(string)$request->headers->get('X-EZS-CSRF','')))
            return new JsonResponse(['error'=>'csrf'],403);
        if((string)$request->request->get('confirmation','')!==strtoupper($action).'_PROD')
            return new JsonResponse(['error'=>'confirmation_required'],400);
        try{
            $svc=new NativeWindowsLifecycle(profile:'prod');
            $permission=self::permission($svc->status());
            if(!$permission[$action])return new JsonResponse(['error'=>'action_not_available'],409,['Cache-Control'=>'no-store']);
            (new DesiredServiceState())->set('prod',$action==='stop'?DesiredServiceState::STOPPED:DesiredServiceState::RUNNING);
            if($action==='start')$result=$svc->startGroup('prod');
            elseif($action==='stop')$result=$svc->stopGroup('prod');
            else{
                // Aucun kill d'une signature inconnue : stopGroup atteste chaque PID.
                $stopped=$svc->stopGroup('prod');
                $result=['stopped'=>$stopped,'started'=>$svc->startGroup('prod')];
            }
            return new JsonResponse(['schema'=>'ezs.prod_service_action.v1','action'=>$action,'processes'=>$result],200,['Cache-Control'=>'no-store']);
        }catch(\Throwable $error){
            return new JsonResponse(['error'=>$error->getMessage()],503,['Cache-Control'=>'no-store']);
        }
    }
}
