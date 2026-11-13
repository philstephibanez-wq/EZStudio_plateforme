<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OrchestratorQueueClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $orchestratorBaseUrl,
    ) {}

    /**
     * Envoie un job en REST à orchestrator (aucun accès direct SQLite).
     *
     * @return array{ok:bool, queue_id:int, source:string, status:string, progress:int, created_at:string}
     */
    public function dispatch(string $source, array $payload): array
    {
        $url = rtrim($this->orchestratorBaseUrl, '/') . '/api/queue/dispatch';

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'source' => $source,
                'payload' => $payload,
            ],
            'timeout' => 5.0,
        ]);

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($statusCode !== 201 || !($data['ok'] ?? false)) {
            throw new \RuntimeException(
                'orchestrator_dispatch_failed: ' . ($data['error'] ?? ('HTTP_' . $statusCode))
            );
        }

        return $data;
    }

    public function reportProgress(int $jobId, int $progress): void
    {
        $url = rtrim($this->orchestratorBaseUrl, '/') . '/api/queue/' . $jobId . '/progress';
        $bounded = max(0, min(100, $progress));

        $this->httpClient->request('POST', $url, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => ['progress' => $bounded],
            'timeout' => 3.0,
        ])->getStatusCode();
    }

    public function complete(int $jobId): void
    {
        $url = rtrim($this->orchestratorBaseUrl, '/') . '/api/queue/' . $jobId . '/complete';

        $this->httpClient->request('POST', $url, [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 5.0,
        ])->getStatusCode();
    }

    public function fail(int $jobId, ?string $errorMessage = null): void
    {
        $url = rtrim($this->orchestratorBaseUrl, '/') . '/api/queue/' . $jobId . '/fail';

        $this->httpClient->request('POST', $url, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => ['error' => $errorMessage],
            'timeout' => 5.0,
        ])->getStatusCode();
    }
    public function cancel(int $jobId): void
    {
        $url = rtrim($this->orchestratorBaseUrl, '/') . '/api/queue/' . $jobId . '/cancel';

        $this->httpClient->request('POST', $url, [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 5.0,
        ])->getStatusCode();
    }
}