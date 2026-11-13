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

        return $this->json([
            'ok' => true,
            'queue_id' => $job->getId(),
            'source' => $job->getSource(),
            'status' => $job->getStatus(),
            'created_at' => $job->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], 201);
    }
}
