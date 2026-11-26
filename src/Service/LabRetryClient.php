<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Orchestrator may only call LAB's private REST contract; never opens LAB SQLite. */
final class LabRetryClient
{
    public function __construct(private readonly HttpClientInterface $httpClient) {}

    public function request(string $action,int $globalJobId,int $attempt,string $source,array $payload): array
    {
        if (!in_array($action,['prepare','commit','abort','state'],true)) throw new \InvalidArgumentException('invalid_retry_action');
        $token=trim((string)($_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN']??$_SERVER['EZSTUDIO_ANALYSIS_WORKER_TOKEN']??getenv('EZSTUDIO_ANALYSIS_WORKER_TOKEN')?:''));
        if ($token==='') { EzsFifoTrace::log('RETRY_TRANSPORT_ERROR',['action'=>$action,'global_job_id'=>$globalJobId,'attempt'=>$attempt,'reason'=>'lab_retry_token_not_configured']); return ['ok'=>false,'reason'=>'lab_retry_token_not_configured']; }
        $base=trim((string)($_ENV['EZSTUDIO_LAB_BASE_URL']??$_SERVER['EZSTUDIO_LAB_BASE_URL']??getenv('EZSTUDIO_LAB_BASE_URL')?:'http://lab.ezstudio.local:8000'));
        try {
            $response=$this->httpClient->request('POST',rtrim($base,'/').'/internal/analysis/desktop/jobs/retry/'.$action,[
                'headers'=>['X-EZScore-Analysis-Token'=>$token,'Accept'=>'application/json'],
                'json'=>['global_job_id'=>$globalJobId,'attempt'=>$attempt,'source'=>$source,'payload'=>$payload],
                'timeout'=>$action==='state'?3.0:15.0,
            ]);
            $code=$response->getStatusCode();
            $data=$response->toArray(false);
            EzsFifoTrace::log('RETRY_HTTP_RESULT',['action'=>$action,'global_job_id'=>$globalJobId,'attempt'=>$attempt,'http_status'=>$code,'result'=>(($data['ok']??false)===true)?'ok':'rejected','reason'=>(string)($data['reason']??'none')]);
            if (!is_array($data) || ($data['schema']??'')!=='ezstudio.lab-job-retry.v1') {
                return ['ok'=>false,'reason'=>'lab_retry_contract_invalid'];
            }
            if ($code!==200 || ($data['ok']??false)!==true) {
                return ['ok'=>false,'reason'=>(string)($data['reason']??('lab_retry_http_'.$code))];
            }
            return $data;
        } catch (\Throwable $e) {
            EzsFifoTrace::log('RETRY_TRANSPORT_ERROR',['action'=>$action,'global_job_id'=>$globalJobId,'attempt'=>$attempt,'exception'=>get_class($e)]);
            return ['ok'=>false,'reason'=>'lab_retry_transport_unavailable'];
        }
    }
}
