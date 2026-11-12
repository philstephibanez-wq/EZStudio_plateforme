<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure\Controller;

use Ezs\Web\Module\Infrastructure\EnvironmentCatalog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class EnvironmentController
{
    #[Route('/api/v2/environments', name: 'ezs_v2_environments', methods: ['GET'])]
    public function index(EnvironmentCatalog $catalog): JsonResponse
    {
        return new JsonResponse(['schema' => 'ezs.environments.v1', 'mode' => 'capabilities_only', 'items' => $catalog->all()]);
    }
}
