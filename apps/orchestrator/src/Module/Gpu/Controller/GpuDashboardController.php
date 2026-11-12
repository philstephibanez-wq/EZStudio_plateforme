<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Gpu\Controller;

use Ezs\Web\Module\Gpu\GpuProbe;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class GpuDashboardController
{
    #[Route('/api/v2/gpu/dashboard', name: 'ezs_v2_gpu_dashboard', methods: ['GET'])]
    public function dashboard(
        Request $request,
        GpuProbe $probe,
        #[Autowire('%kernel.environment%')] string $env
    ): JsonResponse {
        $host = $request->getHost();
        if ($env !== 'dev'
            || !in_array($host, ['127.0.0.1', 'localhost', 'orchestrator.ezstudio.local'], true)
            || !in_array($request->getClientIp(), ['127.0.0.1', '::1'], true)) {
            return new JsonResponse(['available' => false, 'error' => 'forbidden'], 403);
        }
        try {
            $result = $probe->read();
            return new JsonResponse([
                'available' => true,
                'timestamp_ms' => (int)round(microtime(true) * 1000),
                'gpu' => $result['gpu'],
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (\Throwable $exception) {
            $reason = $exception->getMessage();
            if (!in_array($reason, ['gpu_probe_execution_failed', 'gpu_probe_binary_missing'], true)) {
                $reason = 'gpu_probe_parse_or_unexpected_failure';
            }
            return new JsonResponse(['available' => false, 'error' => 'gpu_unavailable', 'reason' => $reason], 503, ['Cache-Control' => 'no-store']);
        }
    }
}
