<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure\Controller;

use Ezs\Web\Module\Infrastructure\NativeSiteObservation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** DEV-only native observation: no PID, commands, secrets or process mutation. */
final class DevGroupDebugController
{
    public static function allowed(string $environment,string $host,string $ip):bool
    {
        return DevGroupOperationsController::allowed($environment,$host,$ip);
    }

    /** Historic presentation helper retained for consumers and tests. */
    public static function summary(array $states):array
    {
        $result=[];
        foreach(['dev'=>['ezscore_dev','ezscore_preview'],'lab'=>['lab'],'assistant'=>['assistant']] as $group=>$names){
            $members=[];
            foreach($names as $name)$members[$name]=(string)($states[$name]??'MISSING');
            $result[$group]=$members;
        }
        return $result;
    }

    private static function diagnostic(NativeSiteObservation $observer, string $site): array
    {
        try { return $observer->observeDiagnostic($site); }
        catch (\Throwable $failure) {
            $message=$failure->getMessage();
            $safe = preg_match('/^EZS_[A-Z0-9_]{1,160}$/D',$message) === 1
                ? $message : 'NATIVE_OBSERVATION_FAILED';
            return ['state'=>'UNVERIFIED','reason_code'=>$safe,
                'can_start'=>false,'can_stop'=>false];
        }
    }

    #[Route('/api/v2/infrastructure/dev-debug-native', name:'ezs_dev_group_debug_native', methods:['GET'])]
    public function native(Request $request):Response
    {
        if (!self::allowed((string)($_SERVER['APP_ENV']??getenv('APP_ENV')?:''),$request->getHost(),(string)$request->getClientIp())) {
            return new Response('forbidden',403,['Cache-Control'=>'no-store']);
        }
        $observer=new NativeSiteObservation();
        $diagnostics=[];
        $groups=[];
        foreach (['dev','lab','assistant'] as $site) {
            $diagnostics[$site]=self::diagnostic($observer,$site);
            $groups[$site]=[$site=>$diagnostics[$site]['state']];
        }
        // Preserve the historic global failure contract while retaining per-site errors.
        // A single failed inventory must not hide independent, valid observations.
        $failed=0;
        foreach ($diagnostics as $detail) {
            if (!array_key_exists('desired', $detail)) ++$failed;
        }
        $result=['schema'=>'ezs.dev_group_native_debug.v1','groups'=>$groups,
            'diagnostics'=>$diagnostics,
            'error'=>$failed === count($diagnostics) ? 'INVENTORY_UNAVAILABLE' : null,
            'timestamp_utc'=>gmdate('Y-m-d\TH:i:s\Z')];
        return new Response(json_encode($result,JSON_THROW_ON_ERROR),200,[
            'Content-Type'=>'application/json; charset=UTF-8','Cache-Control'=>'no-store',
            'X-Content-Type-Options'=>'nosniff','X-Robots-Tag'=>'noindex, nofollow',
        ]);
    }
}
