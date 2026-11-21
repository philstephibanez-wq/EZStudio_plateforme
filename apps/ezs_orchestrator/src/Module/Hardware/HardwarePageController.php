<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Hardware;

use Shared\Hardware\Inventory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/** Read-only snapshot page. No browser token, resource allocation, monitoring or polling. */
final class HardwarePageController
{
    #[Route('/hardware', name: 'ezs_hardware_inventory_page', methods: ['GET'])]
    public function index(Request $request, Environment $twig): Response
    {
        $language = (string) $request->query->get('lang', 'fr');
        if (!in_array($language, ['fr', 'en'], true)) {
            $language = 'fr';
        }
        $versionFile = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '—';
        $snapshot = null;
        $reason = 'not_collected';
        $file = Inventory::snapshotPath();
        if (is_string($file) && $file !== '' && is_file($file) && is_readable($file)) {
            $raw = @file_get_contents($file);
            $candidate = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($candidate) && Inventory::validate($candidate)) {
                $snapshot = $candidate;
                $reason = 'ok';
            } else {
                $reason = 'invalid';
            }
        }
        return new Response($twig->render('hardware/index.html.twig', [
            'orchestrator_version' => $version,
            'active_page' => 'hardware',
            'hw_locale' => $language,
            'inventory' => $snapshot,
            'inventory_reason' => $reason,
        ]), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
