<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure\Controller;

use Ezs\Web\Module\Infrastructure\DevHttpStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class DevHealthController
{
    #[Route('/api/v2/infrastructure/dev-health', name: 'ezs_v2_dev_health', methods: ['GET'])]
    public function index(Request $request, DevHttpStatus $health): JsonResponse
    {
        if (($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: '') !== 'dev' ||
            !in_array($request->getClientIp(), ['127.0.0.1', '::1'], true) ||
            !in_array($request->getHost(), ['127.0.0.1', 'localhost'], true)) {
            return new JsonResponse(['error' => 'forbidden'], 403);
        }
        return new JsonResponse(['schema'=>'ezs.dev_health.v1', 'scope'=>'dev_loopback_only',
            'timestamp_utc'=>gmdate('c'), 'items'=>$health->snapshot()], 200, ['Cache-Control'=>'no-store']);
    }
}
