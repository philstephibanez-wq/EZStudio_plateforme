<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OrchestratorQueueClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $orchestratorBaseUrl,
        private readonly ?string $internalToken = null,
    ) {}

    /** @return array<string,string> */
    private function defaultHeaders(bool $json = false): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }
        $token = $this->resolveToken();
        if ($token !== '') {
            $headers['X-EZS-Queue-Token'] = $token;
        }
        return $headers;
    }

    private function resolveToken(): string
    {
        $token = $this->internalToken
            ?? ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: '');
        return trim((string) $token);
    }

    /**
     * @return array{ok:bool, queue_id:int, source:string, status:string, progress:int, created_at:string, deduplicated?:bool}
     */
    public function dispatch(string $source, array $payload): array
    {
        $url = rtrim($this->orchestratorBaseUrl, '/') . '/api/queue/dispatch';

        EzsFifoTrace::log('ORCHESTRATOR_DISPATCH_START',['source'=>$source,'local_job_id'=>(int)($payload['job_id']??0),'run_id'=>(int)($payload['run_id']??0)]);
        $response = $this->httpClient->request('POST', $url, [
            'headers' => $this->defaultHeaders(true),
            'json' => [
                'source' => $source,
                'payload' => $payload,
            ],
            'timeout' => 15.0,
        ]);

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);
        EzsFifoTrace::log('ORCHESTRATOR_DISPATCH_HTTP',['source'=>$source,'http_status'=>$statusCode,'ack_queue_id'=>(int)($data['queue_id']??0),'ack_status'=>(string)($data['status']??'unknown'),'remote_error'=>(string)($data['error']??'none')]);

        if (!in_array($statusCode, [200, 201], true) || !($data['ok'] ?? false)) {
            throw new \RuntimeException(
                'orchestrator_dispatch_failed: ' . ($data['error'] ?? ('HTTP_' . $statusCode))
            );
        }

        return $data;
    }

    /** Authenticated and read-only execution fence; Orchestrator is authoritative. */
    public function executionProof(int $globalJobId): array
    {
        if ($globalJobId <= 0) { throw new \InvalidArgumentException('invalid_global_job_id'); }
        $url = rtrim($this->orchestratorBaseUrl, '/') . '/api/queue/' . $globalJobId . '/execution-proof';
        $response = $this->httpClient->request('GET', $url, [
            'headers' => $this->defaultHeaders(false), 'timeout' => 5.0,
        ]);
        $status = $response->getStatusCode();
        $data = $response->toArray(false);
        EzsFifoTrace::log('E2E_GO_PROOF_HTTP',['global_job_id'=>$globalJobId,'http_status'=>$status,'result'=>(string)($data['error']??'ok')]);
        if ($status !== 200 || !($data['ok'] ?? false) || !is_array($data['proof'] ?? null)) {
            throw new \RuntimeException('orchestrator_execution_proof_unavailable_http_'.$status);
        }
        return $data['proof'];
    }

    public function attachPid(int $jobId, int $pid): void
    {
        $url = rtrim($this->orchestratorBaseUrl, '/') . '/api/queue/' . $jobId . '/attach-pid';

        $this->httpClient->request('POST', $url, [
            'headers' => $this->defaultHeaders(true),
            'json' => ['pid' => $pid],
            'timeout' => 3.0,
        ])->getStatusCode();
    }

    public function reportProgress(int $jobId, int $progress): void
    {
        $url = rtrim($this->orchestratorBaseUrl, '/') . '/api/queue/' . $jobId . '/progress';
        $bounded = max(0, min(100, $progress));

        $this->httpClient->request('POST', $url, [
            'headers' => $this->defaultHeaders(true),
            'json' => ['progress' => $bounded],
            'timeout' => 3.0,
        ])->getStatusCode();
    }

    public function complete(int $jobId): void
    {
        $url = rtrim($this->orchestratorBaseUrl, '/') . '/api/queue/' . $jobId . '/complete';

        $this->httpClient->request('POST', $url, [
            'headers' => $this->defaultHeaders(false),
            'timeout' => 15.0,
        ])->getStatusCode();
    }

    public function fail(int $jobId, ?string $errorMessage = null): void
    {
        $url = rtrim($this->orchestratorBaseUrl, '/') . '/api/queue/' . $jobId . '/fail';

        $this->httpClient->request('POST', $url, [
            'headers' => $this->defaultHeaders(true),
            'json' => ['error' => $errorMessage],
            'timeout' => 15.0,
        ])->getStatusCode();
    }

    /** Require an explicit Orchestrator cancellation acknowledgement, not just HTTP 200. */
    public function cancelVerified(int $jobId): array
    {
        if ($jobId<1) throw new \InvalidArgumentException('invalid_global_job_id');
        $url=rtrim($this->orchestratorBaseUrl,'/').'/api/queue/'.$jobId.'/cancel';
        $response=$this->httpClient->request('POST',$url,[
            'headers'=>$this->defaultHeaders(false),'timeout'=>15.0,
        ]);
        $code=$response->getStatusCode();
        $data=$response->toArray(false);
        if ($code!==200 || !is_array($data) || ($data['ok']??false)!==true
            || (int)($data['queue_id']??0)!==$jobId) {
            throw new \RuntimeException('expertise_global_cancellation_unverified_http_'.$code);
        }
        return $data;
    }

    public function cancel(int $jobId): void
    {
        $url = rtrim($this->orchestratorBaseUrl, '/') . '/api/queue/' . $jobId . '/cancel';

        $this->httpClient->request('POST', $url, [
            'headers' => $this->defaultHeaders(false),
            'timeout' => 15.0,
        ])->getStatusCode();
    }
}