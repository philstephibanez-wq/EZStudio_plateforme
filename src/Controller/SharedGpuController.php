<?php
declare(strict_types=1);

namespace App\Controller;

require_once dirname(__DIR__) . '/Service/SharedGpuTelemetryService.php';

use App\Service\SharedGpuTelemetryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class SharedGpuController
{
    #[Route('/api/gpu/telemetry', name: 'shared_gpu_telemetry', methods: ['GET'])]
    public function telemetry(): JsonResponse
    {
        $data = (new SharedGpuTelemetryService())->snapshot();
        return new JsonResponse($data, ($data['available'] ?? false) ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}