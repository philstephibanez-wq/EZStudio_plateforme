<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure\Controller;

use Ezs\Web\Module\Infrastructure\DesiredServiceState;
use Ezs\Web\Module\Infrastructure\NativeSiteLifecycle;
use Ezs\Web\Module\Infrastructure\NativeSiteObservation;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** DEV-local REST facade for the canonical FrankenPHP native lifecycle.
 * No legacy PHP-CGI process manager. Assistant delegates to its own canonical scripts.
 */
final class DevGroupOperationsController
{
    public static function allowed(string $environment, string $host, string $ip): bool
    {
        return $environment === 'dev'
            && in_array($host, ['127.0.0.1', 'localhost'], true)
            && in_array($ip, ['127.0.0.1', '::1'], true);
    }

    /** Existing pure group semantics retained for callers and historic tests.
     * This helper does not inspect or control any process.
     */
    public static function permission(array $states, string $group): array
    {
        $groups = ['dev' => ['ezscore_dev','ezscore_preview'], 'lab' => ['lab'], 'assistant' => ['assistant']];
        if (!isset($groups[$group])) throw new \InvalidArgumentException('EZS_DEV_GROUP_UNKNOWN');
        $names = $groups[$group];
        foreach ($names as $name) {
            if (!isset($states[$name]) || !in_array($states[$name], ['ATTESTED','ABSENT'], true)) {
                return ['start'=>false,'restart'=>false,'stop'=>false,'state'=>'UNVERIFIED'];
            }
        }
        $values = array_map(static fn(string $name): string => $states[$name], $names);
        $up = count(array_filter($values, static fn(string $state): bool => $state === 'ATTESTED'));
        $state = $up === count($values) ? 'RUNNING' : ($up === 0 ? 'STOPPED' : 'PARTIAL');
        return ['start'=>$state === 'STOPPED','restart'=>$state === 'RUNNING',
            'stop'=>$state === 'RUNNING' || $state === 'PARTIAL','state'=>$state];
    }

    private static function inaccessibleAssistant(): array
    {
        return ['state'=>'UNVERIFIED','start'=>false,'restart'=>false,'stop'=>false,'desired'=>'UNVERIFIED'];
    }

    /** Failure to prove Assistant ownership stays UNVERIFIED, never STOPPED. */
    private static function assistantObservation(NativeSiteObservation $observer): array
    {
        try { return $observer->observe('assistant'); }
        catch (\Throwable) { return self::inaccessibleAssistant(); }
    }

    private function allowedRequest(Request $request): bool
    {
        return self::allowed((string) ($_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: ''),
            $request->getHost(), (string) $request->getClientIp());
    }

    private function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict','use_strict_mode'=>true]);
        }
        if (!isset($_SESSION['ezs_dev_group_csrf'])) $_SESSION['ezs_dev_group_csrf'] = bin2hex(random_bytes(32));
        return (string) $_SESSION['ezs_dev_group_csrf'];
    }

    #[Route('/api/v2/infrastructure/dev-groups', name: 'ezs_dev_groups_read', methods: ['GET'])]
    public function read(Request $request): JsonResponse
    {
        if (!$this->allowedRequest($request)) return new JsonResponse(['error'=>'forbidden'],403);
        try {
            $observation = new NativeSiteObservation();
            return new JsonResponse([
                'schema'=>'ezs.dev_groups.v1', 'csrf'=>$this->token(),
                'groups'=>[
                    'dev'=>$observation->observe('dev'),
                    'lab'=>$observation->observe('lab'),
                    'assistant'=>self::assistantObservation($observation),
                ],
            ],200,['Cache-Control'=>'no-store']);
        } catch (\Throwable $error) {
            return new JsonResponse(['error'=>'native_inventory_unavailable'],503,['Cache-Control'=>'no-store']);
        }
    }

    #[Route('/api/v2/infrastructure/dev-group/{group}', name: 'ezs_dev_group_read_single', requirements: ['group'=>'dev|lab|assistant'], methods: ['GET'])]
    public function readGroup(Request $request, string $group): JsonResponse
    {
        if (!$this->allowedRequest($request)) return new JsonResponse(['error'=>'forbidden'],403);
        if (!in_array($group,['dev','lab','assistant'],true)) return new JsonResponse(['error'=>'unknown_group'],404);
        try {
            return new JsonResponse(['schema'=>'ezs.dev_group_single.v1','group'=>$group,
                'permission'=>$group === 'assistant'
                    ? self::assistantObservation(new NativeSiteObservation())
                    : (new NativeSiteObservation())->observe($group),
                'csrf'=>$this->token()],200,['Cache-Control'=>'no-store']);
        } catch (\Throwable $error) {
            return new JsonResponse(['error'=>'group_inventory_unavailable'],503,['Cache-Control'=>'no-store']);
        }
    }

    #[Route('/api/v2/infrastructure/dev-groups/{group}/{action}', name: 'ezs_dev_groups_action', requirements: ['group'=>'dev|lab|assistant','action'=>'start|stop|restart'], methods: ['POST'])]
    public function action(Request $request, string $group, string $action): JsonResponse
    {
        if (!$this->allowedRequest($request)) return new JsonResponse(['error'=>'forbidden'],403);
        if (!in_array($request->headers->get('Origin',''),['http://127.0.0.1:8790','http://localhost:8790'],true)) {
            return new JsonResponse(['error'=>'invalid_origin'],403);
        }
        if (!hash_equals($this->token(), (string) $request->headers->get('X-EZS-CSRF',''))) {
            return new JsonResponse(['error'=>'csrf'],403);
        }
        if ((string) $request->request->get('confirmation','') !== strtoupper($action).'_'.strtoupper($group)) {
            return new JsonResponse(['error'=>'confirmation_required'],400);
        }
        if (!in_array($group,['dev','lab','assistant'],true)) {
            return new JsonResponse(['error'=>'native_site_only'],409,['Cache-Control'=>'no-store']);
        }
        try {
            $native = new NativeSiteLifecycle();
            $observation = new NativeSiteObservation($native);
            $permission = $observation->observe($group);
            if (!$permission[$action]) return new JsonResponse(['error'=>'action_not_available'],409,['Cache-Control'=>'no-store']);
            $intent = new DesiredServiceState();
            if ($action === 'start') {
                $native->request($group,'Preflight');
                $intent->set($group,DesiredServiceState::RUNNING);
                $result = $native->start($group);
            } elseif ($action === 'stop') {
                $intent->set($group,DesiredServiceState::STOPPED);
                $result = $native->stop($group);
                if ($observation->observe($group)['state'] !== 'STOPPED') {
                    throw new \RuntimeException('EZS_STOP_NOT_VERIFIED_'.$group);
                }
            } else {
                // Distinct intent transitions: STOP first, reattest, then START.
                // A failed STOP never causes a speculative START.
                $native->request($group,'Preflight');
                $intent->set($group,DesiredServiceState::STOPPED);
                $stopped = $native->stop($group);
                try {
                    if ($observation->observe($group)['state'] !== 'STOPPED') {
                        throw new \RuntimeException('EZS_RESTART_STOP_NOT_VERIFIED');
                    }
                    $intent->set($group,DesiredServiceState::RUNNING);
                    $started = $native->start($group);
                    $result = ['stopped'=>$stopped,'started'=>$started];
                } catch (\Throwable $restartFailure) {
                    return new JsonResponse(['error'=>'restart_incomplete','phase'=>'after_stop'],503,['Cache-Control'=>'no-store']);
                }
            }
            return new JsonResponse(['schema'=>'ezs.dev_group_action.v1','group'=>$group,
                'action'=>$action,'processes'=>$result],200,['Cache-Control'=>'no-store']);
        } catch (\Throwable $error) {
            $reason = strtoupper($error->getMessage());
            // Only an explicitly controlled alphanumeric error code reaches the API.
            if (!preg_match('/^EZS_[A-Z0-9_]{1,160}$/D', $reason)) $reason = 'EZS_NATIVE_ACTION_UNKNOWN';
            return new JsonResponse(['error'=>'native_action_refused','reason_code'=>$reason],503,['Cache-Control'=>'no-store']);
        }
    }
}
