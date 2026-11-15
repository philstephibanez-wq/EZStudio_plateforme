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
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

final class JobsPageController
{
    #[Route('/jobs', name: 'ezs_v2_jobs_page', methods: ['GET'])]
    public function index(Request $request, Environment $twig, LabQueueProbe $labQueue, QueueActionCsrfManager $csrf): Response
    {
        $root = dirname(__DIR__, 4);
        $versionFile = $root . DIRECTORY_SEPARATOR . 'VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : 'inconnue';
        $history = (new LegacyJobHistoryReader($root))->recent();
        $filters = [
            'state' => (string) $request->query->get('state', 'all'),
            'actor' => (string) $request->query->get('actor', 'all'),
            'category' => (string) $request->query->get('category', 'all'),
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
            'lab_queue' => $labQueue->status(),
            'retry_tokens' => $retryTokens,
            'delete_tokens' => $deleteTokens,
            'flash_error' => $flashError,
        ]), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[IsGranted('ROLE_ORCHESTRATOR_OPERATOR')]
    #[Route('/jobs/{id}/retry', name: 'ezs_v2_jobs_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retry(int $id, Request $request, JobRetryCoordinator $coordinator, QueueActionCsrfManager $csrf): Response
    {
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

    #[IsGranted('ROLE_ORCHESTRATOR_OPERATOR')]
    #[Route('/jobs/{id}/delete', name: 'ezs_v2_jobs_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, QueueManager $queueManager, QueueActionCsrfManager $csrf): Response
    {
        $provided = (string) $request->request->get('_token', '');
        if (!$csrf->isValid($request, 'delete_job_' . $id, $provided)) {
            return new Response('Jeton CSRF invalide ou manquant.', 403);
        }
        $queueManager->delete($id);
        return $this->redirectBack($request);
    }

    private function redirectBack(Request $request): RedirectResponse
    {
        $query = array_filter([
            'state' => (string) $request->query->get('state', ''),
            'actor' => (string) $request->query->get('actor', ''),
            'category' => (string) $request->query->get('category', ''),
            'limit' => (string) $request->query->get('limit', ''),
        ], static fn(string $v): bool => $v !== '' && $v !== 'all' && $v !== '30');
        return new RedirectResponse('/jobs' . ($query !== [] ? '?' . http_build_query($query) : ''));
    }
}
