<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** DEV maintenance is a route-only switch, never a process lifecycle operation. */
final class DevWebModeController
{
    private const FLAG_DIR = 'H:/EZS_orchestrator/runtime/web-modes';
    private const FLAG = self::FLAG_DIR.'/dev.maintenance';

    public static function allowed(string $environment, string $host, string $ip): bool
    {
        return $environment === 'dev'
            && in_array($host, ['127.0.0.1', 'localhost'], true)
            && in_array($ip, ['127.0.0.1', '::1'], true);
    }

    /**
     * Shared local anti-CSRF nonce: the native FrankenPHP PHP runtime may not
     * preserve session cookies between separate site handlers. Keep the token
     * outside document roots, guarded by a lock, and do not use a predictable
     * value. Origin + loopback + explicit confirmation are still mandatory.
     */
    private static function token(): string
    {
        if (!is_dir(self::FLAG_DIR) && !@mkdir(self::FLAG_DIR, 0750, true) && !is_dir(self::FLAG_DIR)) {
            throw new \RuntimeException('dev_csrf_directory_unavailable');
        }
        $path = self::FLAG_DIR.'/dev-csrf.key';
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            throw new \RuntimeException('dev_csrf_open_failed');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('dev_csrf_lock_failed');
            }
            rewind($handle);
            $token = trim((string) stream_get_contents($handle));
            if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
                if ($token !== '') {
                    throw new \RuntimeException('dev_csrf_token_invalid');
                }
                $token = bin2hex(random_bytes(32));
                rewind($handle);
                if (!ftruncate($handle, 0) || fwrite($handle, $token."\n") !== strlen($token) + 1 || !fflush($handle)) {
                    throw new \RuntimeException('dev_csrf_write_failed');
                }
            }
            return $token;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function permitted(Request $request): bool
    {
        return self::allowed((string) ($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: ''),
            $request->getHost(), (string) $request->getClientIp());
    }

    #[Route('/api/v2/infrastructure/dev-web-mode', name: 'ezs_dev_web_mode_read', methods: ['GET'])]
    public function read(Request $request): JsonResponse
    {
        if (!self::permitted($request)) return new JsonResponse(['error'=>'forbidden'],403);
        try { $csrf = self::token(); } catch (\RuntimeException $e) {
            return new JsonResponse(['error'=>'dev_csrf_unavailable'],503);
        }
        return new JsonResponse([
            'schema'=>'ezs.dev_web_mode.v1',
            'mode'=>is_file(self::FLAG) ? 'MAINTENANCE' : 'NORMAL',
            'csrf'=>$csrf,
        ],200,['Cache-Control'=>'no-store']);
    }

    #[Route('/api/v2/infrastructure/dev-web-mode/{mode}', name: 'ezs_dev_web_mode_change', requirements: ['mode'=>'normal|maintenance'], methods: ['POST'])]
    public function change(Request $request, string $mode): JsonResponse
    {
        if (!self::permitted($request)) return new JsonResponse(['error'=>'forbidden'],403);
        if (!in_array($request->headers->get('Origin', ''),
            ['http://127.0.0.1:8790','http://localhost:8790'], true)) {
            return new JsonResponse(['error'=>'invalid_origin'],403);
        }
        try { $csrf = self::token(); } catch (\RuntimeException $e) {
            return new JsonResponse(['error'=>'dev_csrf_unavailable'],503);
        }
        if (!hash_equals($csrf, (string) $request->headers->get('X-EZS-CSRF',''))) {
            return new JsonResponse(['error'=>'csrf'],403);
        }
        if ((string) $request->request->get('confirmation','') !== strtoupper($mode).'_DEV') {
            return new JsonResponse(['error'=>'confirmation_required'],400);
        }
        if (!is_dir(self::FLAG_DIR) && !@mkdir(self::FLAG_DIR,0750,true) && !is_dir(self::FLAG_DIR)) {
            return new JsonResponse(['error'=>'dev_mode_dir_unavailable'],503);
        }
        $lock=@fopen(self::FLAG_DIR.'/dev-mode.lock','c+');
        if (!is_resource($lock)) return new JsonResponse(['error'=>'dev_mode_lock_unavailable'],503);
        try {
            if (!flock($lock,LOCK_EX|LOCK_NB)) return new JsonResponse(['error'=>'dev_mode_busy'],409);
            if ($mode === 'maintenance') {
                if (!is_file(self::FLAG) && file_put_contents(self::FLAG,"MAINTENANCE\n",LOCK_EX) === false) {
                    return new JsonResponse(['error'=>'dev_mode_write_failed'],503);
                }
            } elseif (is_file(self::FLAG) && !@unlink(self::FLAG)) {
                return new JsonResponse(['error'=>'dev_mode_remove_failed'],503);
            }
            $observed=is_file(self::FLAG) ? 'MAINTENANCE' : 'NORMAL';
            if ($observed !== strtoupper($mode)) return new JsonResponse(['error'=>'dev_mode_not_confirmed'],503);
            return new JsonResponse(['schema'=>'ezs.dev_web_mode.v1','mode'=>$observed],200,['Cache-Control'=>'no-store']);
        } finally {
            flock($lock,LOCK_UN);
            fclose($lock);
        }
    }
}
