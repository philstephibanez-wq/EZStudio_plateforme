<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Controller;

use App\Service\JobRetryCoordinator;
use App\Service\QueueActionCsrfManager;
use App\Service\QueueManager;
use Ezs\Web\Module\Coordination\LegacyJobHistoryReader;
use Ezs\Web\Module\Coordination\GlobalQueueDashboard;
use Ezs\Web\Module\Coordination\LabQueueProbe;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class JobsPageController
{
    #[Route('/jobs', name: 'ezs_v2_jobs_page', methods: ['GET'])]
    public function index(Request $request, Environment $twig, LabQueueProbe $labQueue, QueueActionCsrfManager $csrf): Response
    {
        $root = dirname(__DIR__, 4);
        $versionFile = $root . DIRECTORY_SEPARATOR . 'VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : 'inconnue';
        $history = \App\Service\QueueBarrierPaths::isTest() ? [] : (new LegacyJobHistoryReader($root))->recent();
        $filters = [
            'state' => (string) $request->query->get('state', 'all'),
            'actor' => (string) $request->query->get('actor', 'all'),
            'category' => (string) $request->query->get('category', 'all'),
            'item' => (string) $request->query->get('item', 'all'),
            'limit' => (int) $request->query->get('limit', 30),
        ];
        $globalQueue = GlobalQueueDashboard::read(null, $filters);
        $retryTokens = [];
        $deleteTokens = [];
        foreach (($globalQueue['jobs'] ?? []) as $row) {
            $jid = (int) ($row['id'] ?? 0);
            if ($jid > 0) {
                $retryTokens[$jid] = $csrf->generate($request, 'retry_job_' . $jid);
                $deleteTokens[$jid] = $csrf->generate($request, 'delete_job_' . $jid);
            }
        }
        $session = $request->getSession();
        $flashError = $session->get('_ezs_jobs_flash_error', '');
        if ($flashError !== '') {
            $session->remove('_ezs_jobs_flash_error');
        }
        return new Response($twig->render('jobs/index.html.twig', [
            'orchestrator_version' => $version,
            'active_page' => 'jobs',
            'history' => $history,
            'global_queue' => $globalQueue,
            // EZS_FIFO_ACTIVE_RETRY_R1B: show the only actionable failed job.
            'queue_paused_on_error' => is_file(\App\Service\QueueBarrierPaths::pause(dirname(__DIR__, 6))),
            'queue_blocked_job_id' => (static function (): int {
                $file = \App\Service\QueueBarrierPaths::pause(dirname(__DIR__, 6));
                $raw = is_file($file) ? @file_get_contents($file) : false;
                $pause = is_string($raw) ? json_decode($raw, true) : null;
                return is_array($pause) && ($pause['schema'] ?? null) === 'ezs.queue.pause_on_ko.v1'
                    && is_int($pause['global_job_id'] ?? null) ? $pause['global_job_id'] : 0;
            })(),
            'lab_queue' => \App\Service\QueueBarrierPaths::isTest() ? ['connected'=>false,'reason'=>'test_isolation','jobs'=>[],'source'=>'lab_queue_live'] : $labQueue->status(),
            'retry_tokens' => $retryTokens,
            'delete_tokens' => $deleteTokens,
            'flash_error' => $flashError,
        ]), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[Route('/jobs/{id}/retry', name: 'ezs_v2_jobs_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retry(int $id, Request $request, JobRetryCoordinator $coordinator, QueueActionCsrfManager $csrf): Response
    {
        // Local workstation-only workflow; authenticated remote HTTP is not sufficient.
        // Retain the POST route and the session-bound CSRF check below.
        if (!in_array($request->getClientIp(), ['127.0.0.1', '::1'], true)) {
            return new Response('Action locale uniquement.', 403);
        }
        $provided = (string) $request->request->get('_token', '');
        if (!$csrf->isValid($request, 'retry_job_' . $id, $provided)) {
            return new Response('Jeton CSRF invalide ou manquant.', 403);
        }
        $outcome = $coordinator->retry($id);
        if (!$outcome['ok']) {
            $request->getSession()->set('_ezs_jobs_flash_error', 'Relance refusée pour le job #' . $id . ' (' . $outcome['reason'] . ').');
            return $this->redirectBack($request);
        }
        if (($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? '') !== 'test') {
            $platformRoot = str_replace('/', '\\', dirname(__DIR__, 5));
            $php = is_file('H:\\PHP\\php-8.5-x64\\php.exe') ? 'H:\\PHP\\php-8.5-x64\\php.exe' : 'php';
            $cmd = sprintf('cmd /c "cd /d %s && set APP_ID=orchestrator && start "" /B "%s" bin\\console orchestrator:queue:consume --drain"', $platformRoot, $php);
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) { @pclose($handle); }
        }
        return $this->redirectBack($request);
    }

    #[Route('/jobs/{id}/delete', name: 'ezs_v2_jobs_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, QueueManager $queueManager, QueueActionCsrfManager $csrf): Response
    {
        // Local workstation-only workflow; authenticated remote HTTP is not sufficient.
        // Retain the POST route and the session-bound CSRF check below.
        if (!in_array($request->getClientIp(), ['127.0.0.1', '::1'], true)) {
            return new Response('Action locale uniquement.', 403);
        }
        $provided = (string) $request->request->get('_token', '');
        if (!$csrf->isValid($request, 'delete_job_' . $id, $provided)) {
            return new Response('Jeton CSRF invalide ou manquant.', 403);
        }
        $job = $queueManager->findForStoppedCancellation($id);
        if ($job !== null && $job->getStatus() === 'waiting') {
            if (!$queueManager->reserveWaitingCancellation($id)) {
                $request->getSession()->set('_ezs_jobs_flash_error', 'Annulation refusée : identité ou état modifié.');
            }
        } elseif (!$queueManager->delete($id)) {
            $request->getSession()->set('_ezs_jobs_flash_error', 'Suppression refusée : annulation non acquittée ou état actif.');
        }
        return $this->redirectBack($request);
    }

    private function redirectBack(Request $request): RedirectResponse
    {
        $query = array_filter([
            'state' => (string) $request->query->get('state', ''),
            'actor' => (string) $request->query->get('actor', ''),
            'category' => (string) $request->query->get('category', ''),
            'item' => (string) $request->query->get('item', ''),
            'limit' => (string) $request->query->get('limit', ''),
        ], static fn(string $v): bool => $v !== '' && $v !== 'all' && $v !== '30');
        return new RedirectResponse('/jobs' . ($query !== [] ? '?' . http_build_query($query) : ''));
    }
}
