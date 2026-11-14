<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Gpu\Controller;
use App\Service\SharedGpuTelemetryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
final class GpuDashboardController {
    #[Route('/api/v2/gpu/dashboard', name: 'ezs_v2_gpu_dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse {
        $data = (new SharedGpuTelemetryService())->snapshot();
        return new JsonResponse($data, ($data['available'] ?? false) ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}