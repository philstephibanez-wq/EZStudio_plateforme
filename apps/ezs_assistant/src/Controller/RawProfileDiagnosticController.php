<?php
namespace EZSAssistant\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;

/** Optional, local-only read-only diagnostics. Never pass GitHub credentials to the browser. */
final class RawProfileDiagnosticController extends AbstractController
{
    private const REPOSITORY = 'philstephibanez-wq/EZStudio_Run_Reports';

    private function fetch(string $path): string
    {
        $gh = $_ENV['EZS_RAW_DIAGNOSTIC_GH'] ?? $_SERVER['EZS_RAW_DIAGNOSTIC_GH'] ?? 'gh';
        $process = new Process([$gh, 'api', 'repos/'.self::REPOSITORY.'/contents/'.$path]);
        $process->setTimeout(12);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('github_archive_read_failed');
        }
        $row = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($row) || !isset($row['content']) || !is_string($row['content'])) {
            throw new \RuntimeException('github_archive_content_invalid');
        }
        $raw = base64_decode($row['content'], true);
        if ($raw === false) {
            throw new \RuntimeException('github_archive_base64_invalid');
        }
        return $raw;
    }

    #[Route('/diagnostic/profile/raw', name: 'assistant_raw_profile_diagnostic', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $enabled = $_SERVER['EZS_RAW_DIAGNOSTIC_LOCAL'] ?? $_ENV['EZS_RAW_DIAGNOSTIC_LOCAL'] ?? '';
        $addr = $request->server->get('REMOTE_ADDR');
        // Explicit opt-in and direct loopback only. Reverse-proxied remote requests denied.
        if ($enabled !== '1' || !in_array($addr, ['127.0.0.1', '::1'], true)
            || $request->headers->has('X-Forwarded-For') || $request->headers->has('Forwarded')) {
            return new Response('Diagnostic brut indisponible', 403);
        }
        $run = (string)$request->query->get('run_id', '');
        $trace = (string)$request->query->get('trace_id', '');
        $selected = (string)$request->query->get('file', 'manifest.json');
        if (strlen($selected) > 180) return new Response('Pièce invalide', 422);
        if (!preg_match('/^[1-9][0-9]{0,8}$/D', $run)
            || !preg_match('/^[a-f0-9]{12}$/D', $trace)) {
            return new Response('Identifiants invalides', 422);
        }
        $root = sprintf('diagnostics/assistant/profile/run-%06d/trace-%s', (int)$run, $trace);
        try {
            $manifestRaw = $this->fetch($root.'/manifest.json');
            $manifest = json_decode($manifestRaw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['run_id'] ?? null) !== (int)$run
                || !str_starts_with((string)($manifest['trace_id'] ?? ''), $trace)) {
                throw new \RuntimeException('archive_manifest_identity_mismatch');
            }
            $allowed = ['manifest.json' => hash('sha256', $manifestRaw)];
            foreach ($manifest['artifacts'] ?? [] as $item) {
                if (is_array($item) && isset($item['path'], $item['sha256'])
                    && is_string($item['path']) && is_string($item['sha256'])
                    && preg_match('~^(?:timeline-[0-9]{4}\\.jsonl|snapshots/[0-9]{4}_[A-Za-z0-9_.-]+\\.json\\.part-[0-9]{4})$~D', $item['path'])) {
                    if (!preg_match('/^[a-f0-9]{64}$/D', $item['sha256'])) {
                        throw new \RuntimeException('archive_manifest_sha256_invalid');
                    }
                    $allowed[$item['path']] = $item['sha256'];
                }
            }
            if (!isset($allowed[$selected])) {
                return new Response('Pièce absente du manifeste', 404);
            }
            $raw = $selected === 'manifest.json' ? $manifestRaw : $this->fetch($root.'/'.$selected);
            if (!hash_equals($allowed[$selected], hash('sha256', $raw))) {
                throw new \RuntimeException('archive_artifact_sha256_mismatch');
            }
            return $this->render('diagnostics/profile_raw.html.twig', [
                'runId'=>(int)$run, 'traceId'=>$trace, 'selected'=>$selected,
                'manifest'=>$manifest, 'artifactNames'=>array_keys($allowed),
                'rawText'=>$raw, 'sha256'=>hash('sha256', $raw),
                'version'=>trim((string)@file_get_contents(dirname(__DIR__, 2).'/VERSION')),
            ]);
        } catch (\Throwable $e) {
            // Avoid exposing credentials, host paths and private API response bodies.
            return new Response('Archive indisponible ou intégrité invalide : '.get_class($e), 503);
        }
    }
}
