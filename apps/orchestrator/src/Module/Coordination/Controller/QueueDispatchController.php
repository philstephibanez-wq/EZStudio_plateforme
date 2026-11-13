<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Controller;

use App\Service\QueueManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;
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
            'created_at' => $job->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    #[Route('/api/queue/claim', name: 'orchestrator_queue_claim', methods: ['POST'])]
    public function claim(QueueManager $queueManager): JsonResponse
    {
        $job = $queueManager->claimNextPending();
        if ($job === null) {
            return $this->json(['ok' => true, 'job' => null]);
        }

        return $this->json([
            'ok' => true,
            'job' => [
                'id' => $job->getId(),
                'source' => $job->getSource(),
                'status' => $job->getStatus(),
                'payload' => $job->getPayload(),
                'created_at' => $job->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    #[Route('/api/queue/{id}/complete', name: 'orchestrator_queue_complete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function complete(int $id, QueueManager $queueManager): JsonResponse
    {
        $job = $queueManager->markCompleted($id);
        if ($job === null) {
            return $this->json(['ok' => false, 'error' => 'job_not_found'], 404);
        }

        return $this->json(['ok' => true, 'queue_id' => $job->getId(), 'status' => $job->getStatus()]);
    }

    #[Route('/api/queue/{id}/fail', name: 'orchestrator_queue_fail', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function fail(int $id, QueueManager $queueManager): JsonResponse
    {
        $job = $queueManager->markFailed($id);
        if ($job === null) {
            return $this->json(['ok' => false, 'error' => 'job_not_found'], 404);
        }

        return $this->json(['ok' => true, 'queue_id' => $job->getId(), 'status' => $job->getStatus()]);
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