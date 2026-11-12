<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Controller;

use Ezs\Web\Module\Coordination\LegacyJobHistoryReader;
use Ezs\Web\Module\Coordination\GlobalQueueDashboard;
use Ezs\Web\Module\Coordination\LabQueueProbe;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class JobsPageController
{
    #[Route('/jobs', name: 'ezs_v2_jobs_page', methods: ['GET'])]
    public function index(Environment $twig, LabQueueProbe $labQueue): Response
    {
        $root = dirname(__DIR__, 4);
        $versionFile = $root . DIRECTORY_SEPARATOR . 'VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : 'inconnue';
        $history = (new LegacyJobHistoryReader($root))->recent();
        return new Response($twig->render('jobs/index.html.twig', [
            'orchestrator_version' => $version,
            'active_page' => 'jobs',
            'history' => $history,
            'global_queue' => GlobalQueueDashboard::read(),
            'lab_queue' => $labQueue->status(),
        ]), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
