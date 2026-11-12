<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure\Controller;

use Ezs\Web\Module\Infrastructure\DevBackendOwnership;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class DevBackendController
{
    #[Route('/api/v2/infrastructure/dev-backend', name: 'ezs_v2_dev_backend', methods: ['GET'])]
    public function index(Request $request, DevBackendOwnership $ownership): JsonResponse
    {
        if (($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: '') !== 'dev' ||
            !in_array($request->getHost(), ['127.0.0.1','localhost'], true) ||
            !in_array($request->getClientIp(), ['127.0.0.1','::1'], true)) {
            return new JsonResponse(['error'=>'forbidden'],403);
        }
        return new JsonResponse(['schema'=>'ezs.dev_backend.v1','item'=>$ownership->snapshot()],200,['Cache-Control'=>'no-store']);
    }
}
