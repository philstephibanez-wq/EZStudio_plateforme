<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure\Controller;

use Ezs\Web\Module\Infrastructure\DesiredServiceState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Asynchronous application supervision: never performs a native inventory in HTTP. */
final class RestSupervisionController
{
    private const ROOT = 'H:/EZS_orchestrator';
    private const DIR = self::ROOT.'/runtime/supervision';
    private const STATE = self::DIR.'/rest-operation.json';

    public static function allowed(Request $request): bool
    {
        return (($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: '') === 'dev')
            && in_array($request->getHost(), ['127.0.0.1','localhost'], true)
            && in_array($request->getClientIp(), ['127.0.0.1','::1'], true)
            && $request->getPort() === 8790;
    }

    /** @return array<string,mixed> */
    public static function readState(string $file): array
    {
        if (!is_file($file)) return ['state'=>'IDLE'];
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || ($data['schema']??'') !== 'ezs.supervision.rest.v1')
            throw new \RuntimeException('EZS_REST_STATE_INVALID');
        return $data;
    }

    /** @param array<string,mixed> $data */
    public static function writeState(string $file, array $data): void
    {
        $data['schema'] = 'ezs.supervision.rest.v1';
        $json = json_encode($data, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
        $temp = $file.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !rename($temp,$file))
                throw new \RuntimeException('EZS_REST_STATE_WRITE_FAILED');
        } finally { if (is_file($temp)) unlink($temp); }
    }

    #[Route('/api/v2/infrastructure/rest-supervision', name:'ezs_rest_supervision', methods:['GET','POST'])]
    public function handle(Request $request): JsonResponse
    {
        $headers = ['Cache-Control'=>'no-store'];
        if (!self::allowed($request)) return new JsonResponse(['error'=>'forbidden'],403,$headers);
        if ($request->isMethod('GET')) {
            try { return new JsonResponse(self::readState(self::STATE),200,$headers); }
            catch (\Throwable $e) { return new JsonResponse(['error'=>$e->getMessage()],503,$headers); }
        }
        if (!in_array($request->headers->get('Origin',''), ['http://127.0.0.1:8790','http://localhost:8790'], true))
            return new JsonResponse(['error'=>'invalid_origin'],403,$headers);
        // This endpoint only starts a reconciliation of already-persisted intentions;
        // no caller may specify commands, paths, groups or desired states.
        try {
            $desired = (new DesiredServiceState())->all();
            $groups = array_values(array_filter(['dev','lab','assistant','prod'],
                static fn(string $group): bool => ($desired[$group]??'') === DesiredServiceState::RUNNING));
            if ($groups === []) return new JsonResponse(['state'=>'IDLE','detail'=>'no_running_intentions'],200,$headers);
            if (!is_dir(self::DIR) && !mkdir(self::DIR,0700,true) && !is_dir(self::DIR))
                throw new \RuntimeException('EZS_REST_DIR_FAILED');
            $lock = fopen(self::DIR.'/rest-operation.lock','c+');
            if (!is_resource($lock) || !flock($lock,LOCK_EX|LOCK_NB)) {
                if (is_resource($lock)) fclose($lock);
                return new JsonResponse(['state'=>'RUNNING','detail'=>'concurrent_request'],202,$headers);
            }
            try {
                $prior = self::readState(self::STATE);
                // One attempt per page launch; while a worker is active, avoid spawning duplicates.
                if (($prior['state']??'') === 'RUNNING' && (time()-(int)($prior['started_at']??0)) < 900)
                    return new JsonResponse($prior,202,$headers);
                if (($prior['state']??'') === 'RUNNING')
                    return new JsonResponse(['state'=>'FAILED','error'=>'previous_operation_stale_manual_review_required'],409,$headers);
                $php = 'H:/PHP/php-8.5-x64/php.exe';
                $worker = self::ROOT.'/scripts/rest_supervision_worker.php';
                if (!is_file($php) || !is_file($worker)) throw new \RuntimeException('EZS_REST_DEPENDENCY_MISSING');
                if (!class_exists('COM')) throw new \RuntimeException('EZS_REST_WINDOWS_COM_UNAVAILABLE');
                $id = bin2hex(random_bytes(16));
                $state = ['state'=>'RUNNING','id'=>$id,'started_at'=>time(),'groups'=>$groups];
                self::writeState(self::STATE,$state);
                try {
                    // Windows Shell Run(windowStyle=0, wait=false): hidden detached PHP, no CMD, PS or VBS script.
                    $shell = new \COM('WScript.Shell');
                    $command = '"'.str_replace('/','\\',$php).'" "'.str_replace('/','\\',$worker).'" '.$id;
                    $shell->Run($command,0,false);
                } catch (\Throwable $launchFailure) {
                    self::writeState(self::STATE,['state'=>'FAILED','id'=>$id,'finished_at'=>time(),'error'=>'worker_launch_failed']);
                    throw new \RuntimeException('EZS_REST_WORKER_LAUNCH_FAILED',0,$launchFailure);
                }
                return new JsonResponse($state,202,$headers);
            } finally { flock($lock,LOCK_UN);fclose($lock); }
        } catch (\Throwable $e) { return new JsonResponse(['error'=>$e->getMessage()],503,$headers); }
    }
}
