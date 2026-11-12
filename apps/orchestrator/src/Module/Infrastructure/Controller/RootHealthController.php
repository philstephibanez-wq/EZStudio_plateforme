<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** DEV ROOT liveness: proves Symfony routed and served the request, not child services. */
final class RootHealthController
{
    #[Route('/health', name: 'ezs_root_health', methods: ['GET'])]
    public function health(Request $request): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store'];
        if (($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: '') !== 'dev'
            || !in_array($request->getClientIp(), ['127.0.0.1', '::1'], true)
            || !in_array($request->getHost(), ['127.0.0.1', 'localhost'], true)) {
            return new JsonResponse(['status' => 'FORBIDDEN'], 403, $headers);
        }

        // VERSION is part of the same deployment; missing/invalid is a failure.
        $versionFile = dirname(__DIR__, 4).'/VERSION';
        $version = is_file($versionFile) && !is_link($versionFile)
            ? trim((string) file_get_contents($versionFile)) : '';
        if (!preg_match('/^\d+\.\d+[a-z]\d*$/D', $version)) {
            return new JsonResponse([
                'schema' => 'ezs.root.health.v1',
                'component' => 'orchestrator-root',
                'status' => 'FAILED',
                'reason' => 'VERSION_UNAVAILABLE',
                'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            ], 503, $headers);
        }

        return new JsonResponse([
            'schema' => 'ezs.root.health.v1',
            'component' => 'orchestrator-root',
            'status' => 'HEALTHY',
            'version' => $version,
            'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ], 200, $headers);
    }
}
