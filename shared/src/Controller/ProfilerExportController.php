<?php
declare(strict_types=1);
namespace Shared\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ProfilerExportController
{
    #[Route('/_profiler-export', name: 'shared_profiler_export', methods: ['GET'])]
    public function export(Request $request, KernelInterface $kernel): Response
    {
        if ($kernel->getEnvironment() !== 'dev') {
            return new Response('Forbidden', 403);
        }
        $projectDir = $kernel->getProjectDir();
        $cacheDir = $kernel->getCacheDir();
        $appName = basename(dirname($cacheDir));
        $profilerDir = $cacheDir . '/profiler';
        $token = trim((string) $request->query->get('token', ''));

        $out = ["=== EZSTUDIO DIAGNOSTIC EXPORT ===", "UTC: " . gmdate('c'), "App: " . $appName, "Token: " . ($token ?: 'AUCUN'), ""];
        $logs = [$projectDir . '/var/log/' . $appName . '/dev.log', $projectDir . '/apps/ezs_assistant/runtime/worker.log', $projectDir . '/apps/ezs_assistant/runtime/worker.err.log'];
        $qDir = $projectDir . '/apps/ezs_lab/var/logs/profile-queue';
        if (is_dir($qDir)) {
            $ql = glob($qDir . '/*.log') ?: [];
            rsort($ql);
            $logs = array_merge($logs, array_slice($ql, 0, 2));
        }
        foreach ($logs as $f) {
            if (is_file($f)) {
                $lines = @file($f, FILE_IGNORE_NEW_LINES) ?: [];
                $out[] = "--- " . basename($f) . " ---";
                $out[] = implode("\n", array_slice($lines, -45));
                $out[] = "";
            }
        }
        $out[] = "=== PROFIL SYMFONY ===";
        if ($token !== '' && preg_match('/^[a-f0-9]{6,16}$/i', $token)) {
            $pFile = $profilerDir . '/' . substr($token, -2, 2) . '/' . substr($token, -4, 2) . '/' . $token;
            if (is_file($pFile)) {
                $raw = (string) file_get_contents($pFile);
                $dec = @gzdecode($raw);
                $out[] = is_string($dec) ? $dec : $raw;
            }
        }
        $txt = preg_replace('/(TOKEN|SECRET|PASSWORD|KEY)(["\']?\s*[:=]\s*["\']?|";s:\d+:")([^"\'\r\n]+)/i', '$1$2[MASQUE]', implode("\n", $out)) ?? '';
        return new Response($txt, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => 'attachment; filename="profiler-' . $appName . '-' . ($token ?: 'log') . '.txt"']);
    }
}