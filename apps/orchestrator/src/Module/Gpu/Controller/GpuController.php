<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Gpu\Controller;

use Ezs\Web\Module\Gpu\GpuProbe;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class GpuController
{
    #[Route('/api/v2/gpu/main', name: 'ezs_v2_gpu_main', methods: ['GET'])]
    public function main(Request $request, GpuProbe $probe): JsonResponse
    {
        $token = getenv('EZS_GPU_REST_TOKEN');
        if (!is_string($token) || $token === '' || !hash_equals($token, $request->headers->get('X-EZS-GPU-Token', ''))) {
            return new JsonResponse(['error' => 'forbidden'], 403);
        }
        try {
            return new JsonResponse($probe->read());
        } catch (\Throwable $error) {
            return new JsonResponse(['error' => 'gpu_unavailable'], 503);
        }
    }
}
