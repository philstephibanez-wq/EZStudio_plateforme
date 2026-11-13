<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Controller;

use App\Service\QueueManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class QueueDispatchController extends AbstractController
{
    #[Route('/api/queue/dispatch', name: 'orchestrator_queue_dispatch', methods: ['POST'])]
    public function dispatch(Request $request, QueueManager $queueManager): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['ok' => false, 'error' => 'invalid_json'], 400);
        }

        $source = trim((string) ($data['source'] ?? ''));
        $payload = $data['payload'] ?? null;

        if (!in_array($source, ['lab', 'assistant'], true) || !is_array($payload)) {
            return $this->json(['ok' => false, 'error' => 'invalid_dispatch_envelope'], 422);
        }

        $job = $queueManager->dispatch($source, $payload);
        $this->wakeConsumer();

        return $this->json([
            'ok' => true,
            'queue_id' => $job->getId(),
            'source' => $job->getSource(),
            'status' => $job->getStatus(),
            'progress' => $job->getProgress(),
            'created_at' => $job->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    #[Route('/api/queue/claim', name: 'orchestrator_queue_claim', methods: ['POST'])]
    public function claim(QueueManager $queueManager): JsonResponse
    {
        $job = $queueManager->claimNextWaiting();
        if ($job === null) {
            return $this->json(['ok' => true, 'job' => null]);
        }

        return $this->json([
            'ok' => true,
            'job' => [
                'id' => $job->getId(),
                'source' => $job->getSource(),
                'status' => $job->getStatus(),
                'progress' => $job->getProgress(),
                'attempts' => $job->getAttempts(),
                'payload' => $job->getPayload(),
                'created_at' => $job->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    #[Route('/api/queue/{id}/progress', name: 'orchestrator_queue_progress', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function progress(int $id, Request $request, QueueManager $queueManager): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['progress']) || !is_numeric($data['progress'])) {
            return $this->json(['ok' => false, 'error' => 'invalid_progress_payload'], 422);
        }

        $job = $queueManager->updateProgress($id, (int) $data['progress']);
        if ($job === null) {
            return $this->json(['ok' => false, 'error' => 'job_not_found'], 404);
        }

        return $this->json([
            'ok' => true,
            'queue_id' => $job->getId(),
            'status' => $job->getStatus(),
            'progress' => $job->getProgress(),
        ]);
    }

    #[Route('/api/queue/{id}/complete', name: 'orchestrator_queue_complete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function complete(int $id, QueueManager $queueManager): JsonResponse
    {
        $job = $queueManager->markEnded($id, false, null);
        if ($job === null) {
            return $this->json(['ok' => false, 'error' => 'job_not_found'], 404);
        }

        return $this->json([
            'ok' => true,
            'queue_id' => $job->getId(),
            'status' => $job->getStatus(),
            'progress' => $job->getProgress(),
            'has_error' => $job->hasError(),
        ]);
    }

    #[Route('/api/queue/{id}/fail', name: 'orchestrator_queue_fail', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function fail(int $id, Request $request, QueueManager $queueManager): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $errorMessage = is_array($data) && isset($data['error']) ? trim((string) $data['error']) : null;
        if ($errorMessage === '') {
            $errorMessage = null;
        }

        $job = $queueManager->markEnded($id, true, $errorMessage);
        if ($job === null) {
            return $this->json(['ok' => false, 'error' => 'job_not_found'], 404);
        }

        return $this->json([
            'ok' => true,
            'queue_id' => $job->getId(),
            'status' => $job->getStatus(),
            'progress' => $job->getProgress(),
            'has_error' => $job->hasError(),
            'error_message' => $job->getErrorMessage(),
        ]);
    }

    private function wakeConsumer(): void
    {
        if (($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? '') === 'test') {
            return;
        }
        $platformRoot = str_replace('/', '\\', dirname(__DIR__, 5));
        $php = is_file('H:\\PHP\\php-8.5-x64\\php.exe') ? 'H:\\PHP\\php-8.5-x64\\php.exe' : 'php';
        $cmd = sprintf('cmd /c "cd /d %s && set APP_ID=orchestrator && start "" /B "%s" bin\\console orchestrator:queue:consume --drain"', $platformRoot, $php);
        $handle = @popen($cmd, 'r');
        if (is_resource($handle)) {
            @pclose($handle);
        }
    }
}