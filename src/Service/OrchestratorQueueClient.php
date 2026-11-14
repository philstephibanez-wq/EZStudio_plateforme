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

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $this->defaultHeaders(true),
            'json' => [
                'source' => $source,
                'payload' => $payload,
            ],
            'timeout' => 5.0,
        ]);

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if (!in_array($statusCode, [200, 201], true) || !($data['ok'] ?? false)) {
            throw new \RuntimeException(
                'orchestrator_dispatch_failed: ' . ($data['error'] ?? ('HTTP_' . $statusCode))
            );
        }

        return $data;
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
            'timeout' => 5.0,
        ])->getStatusCode();
    }

    public function fail(int $jobId, ?string $errorMessage = null): void
    {
        $url = rtrim($this->orchestratorBaseUrl, '/') . '/api/queue/' . $jobId . '/fail';

        $this->httpClient->request('POST', $url, [
            'headers' => $this->defaultHeaders(true),
            'json' => ['error' => $errorMessage],
            'timeout' => 5.0,
        ])->getStatusCode();
    }

    public function cancel(int $jobId): void
    {
        $url = rtrim($this->orchestratorBaseUrl, '/') . '/api/queue/' . $jobId . '/cancel';

        $this->httpClient->request('POST', $url, [
            'headers' => $this->defaultHeaders(false),
            'timeout' => 5.0,
        ])->getStatusCode();
    }
}