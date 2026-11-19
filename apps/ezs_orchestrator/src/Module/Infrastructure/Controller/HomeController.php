<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure\Controller;

use Ezs\Web\Module\Infrastructure\EnvironmentCatalog;
use Ezs\Web\Module\Coordination\AssistantReadinessProbe;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class HomeController
{
    #[Route('/', name: 'ezs_v2_home', methods: ['GET'])]
    public function index(Environment $twig, EnvironmentCatalog $catalog, AssistantReadinessProbe $assistant): Response
    {
        $versionFile = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : 'inconnue';
        return new Response($twig->render('infrastructure/index.html.twig', ['environments' => $catalog->all(), 'orchestrator_version' => $version, 'active_page' => 'infrastructure', 'dev_version' => '—', 'lab_version' => '—', 'assistant_version' => '—', 'assistant_readiness' => $assistant->status()]), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
