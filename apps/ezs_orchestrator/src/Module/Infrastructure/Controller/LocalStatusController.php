<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure\Controller;

use Ezs\Web\Module\Infrastructure\LocalPortStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class LocalStatusController
{
    #[Route('/api/v2/infrastructure/listeners', name: 'ezs_v2_listeners', methods: ['GET'])]
    public function index(LocalPortStatus $status): JsonResponse
    {
        return new JsonResponse(['schema' => 'ezs.listeners.v1', 'scope' => 'dev_only', 'items' => $status->snapshot()], 200, ['Cache-Control' => 'no-store']);
    }
}
