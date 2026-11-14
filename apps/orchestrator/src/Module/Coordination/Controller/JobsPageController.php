<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Controller;

use Ezs\Web\Module\Coordination\LegacyJobHistoryReader;
use Ezs\Web\Module\Coordination\GlobalQueueDashboard;
use Ezs\Web\Module\Coordination\LabQueueProbe;
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
}