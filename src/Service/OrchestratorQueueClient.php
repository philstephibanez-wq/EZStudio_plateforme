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
     * @return array{ok:bool, queue_id:int, source:string, status:string, created_at:string}
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
}
