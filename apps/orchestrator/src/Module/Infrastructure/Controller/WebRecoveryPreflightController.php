<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure\Controller;

use Ezs\Web\Module\Infrastructure\DesiredServiceState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Safe observability only: no child process, no Windows task and no process mutations. */
final class WebRecoveryPreflightController
{
    public static function allowed(string $env, string $host, string $ip): bool
    {
        return $env === 'dev'
            && in_array($host, ['127.0.0.1', 'localhost'], true)
            && in_array($ip, ['127.0.0.1', '::1'], true);
    }

    #[Route('/api/v2/infrastructure/recovery-preflight', name: 'ezs_recovery_preflight', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        if (!self::allowed((string) ($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: ''), $request->getHost(), (string) $request->getClientIp())) {
            return new JsonResponse(['error' => 'forbidden'], 403, ['Cache-Control' => 'no-store']);
        }
        try {
            $desired = (new DesiredServiceState())->all();
            return new JsonResponse([
                'schema' => 'ezs.web_recovery_preflight.v1',
                'timestamp_utc' => gmdate('c'),
                'desired' => $desired,
                'recovery_enabled' => false,
                'reason' => 'WINDOWS_CHILD_PROCESS_ZERO_FOCUS_NOT_ATTESTED',
                'note' => 'No recovery or process inventory is executed by this endpoint.',
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'DESIRED_STATE_UNAVAILABLE', 'recovery_enabled' => false], 503, ['Cache-Control' => 'no-store']);
        }
    }
}
