<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Controller;

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
    public function index(Request $request, Environment $twig, LabQueueProbe $labQueue): Response
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
        return new Response($twig->render('jobs/index.html.twig', [
            'orchestrator_version' => $version,
            'active_page' => 'jobs',
            'history' => $history,
            'global_queue' => GlobalQueueDashboard::read(null, $filters),
            'lab_queue' => $labQueue->status(),
        ]), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[Route('/jobs/{id}/retry', name: 'ezs_v2_jobs_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retry(int $id, Request $request, QueueManager $queueManager): RedirectResponse
    {
        $retried = $queueManager->retry($id);
        if ($retried !== null && ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? '') !== 'test') {
            $platformRoot = str_replace('/', '\\', dirname(__DIR__, 5));
            $php = is_file('H:\\PHP\\php-8.5-x64\\php.exe') ? 'H:\\PHP\\php-8.5-x64\\php.exe' : 'php';
            $cmd = sprintf('cmd /c "cd /d %s && set APP_ID=orchestrator && start "" /B "%s" bin\\console orchestrator:queue:consume --drain"', $platformRoot, $php);
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) { @pclose($handle); }
        }
        return $this->redirectBack($request);
    }

    #[Route('/jobs/{id}/delete', name: 'ezs_v2_jobs_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, QueueManager $queueManager): RedirectResponse
    {
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