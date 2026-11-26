<?php

declare(strict_types=1);

namespace EZSLab\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Local-only read-only view of the exact redacted JSON stored in Reports. */
final class ProfileForensicDiagnosticController extends AbstractController
{
    #[Route('/workbench/profile/forensic-diagnostic', name: 'profile_forensic_diagnostic', methods: ['GET'])]
    public function show(Request $request): Response
    {
        if (getenv('EZS_RAW_DIAGNOSTIC_LOCAL') !== '1'
            || !in_array((string) $request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)) {
            return new Response('Diagnostic local désactivé.', 403);
        }
        $producer = (string) $request->query->get('producer', 'lab');
        $runId = filter_var($request->query->get('run_id'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]);
        $trace = (string) $request->query->get('trace_id', '');
        $selected = (string) $request->query->get('document', '');
        if ($producer !== 'lab' || $runId === false
            || $runId === null || !preg_match('/^[0-9a-f]{12}$/D', $trace)
            || ($selected !== '' && !preg_match('/^[a-z0-9_-]{1,64}$/D', $selected))) {
            return new Response('Identifiant de diagnostic invalide.', 422);
        }
        $root = rtrim((string) (getenv('EZS_RUN_REPORTS_ROOT') ?: 'H:/EZStudio_Run_Reports'), '\\/');
        $prefix = sprintf('diagnostics/%s/profile/run-%06d/trace-%s/', $producer, (int) $runId, $trace);
        $manifestFile = $root.'/'.$prefix.'manifest.json';
        if (!is_file($manifestFile)) return new Response('Manifeste absent.', 404);
        $raw = file_get_contents($manifestFile);
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($manifest) || ($manifest['schema'] ?? '') !== 'ezs.forensic-manifest.v1'
            || ($manifest['producer'] ?? null) !== $producer || ($manifest['run_id'] ?? null) !== (int) $runId
            || ($manifest['trace_id'] ?? null) !== $trace) {
            return new Response('Manifeste invalide.', 409);
        }
        $documents = is_array($manifest['documents'] ?? null) ? $manifest['documents'] : [];
        $documentRaw = $raw;
        if ($selected !== '') {
            $target = null;
            foreach ($documents as $item) {
                if (($item['logical_name'] ?? null) === $selected) { $target = $item; break; }
            }
            if ($target === null) return new Response('Document absent du manifeste.', 404);
            $pieces = $target['fragments'] ?? null;
            if (!is_array($pieces) || $pieces === []) return new Response('Fragments invalides.', 409);
            $documentRaw = '';
            foreach ($pieces as $part) {
                $name = $part['path'] ?? null;
                if (!is_string($name) || !preg_match('~^'.preg_quote($prefix, '~').'data/[a-z0-9_-]+\.part-[0-9]{5}$~D', $name)) {
                    return new Response('Chemin non autorisé.', 409);
                }
                $bytes = file_get_contents($root.'/'.$name);
                if (!is_string($bytes) || strlen($bytes) !== ($part['bytes'] ?? -1)
                    || !hash_equals((string) ($part['sha256'] ?? ''), hash('sha256', $bytes))) {
                    return new Response('Intégrité fragment incorrecte.', 409);
                }
                $documentRaw .= $bytes;
            }
            if (!hash_equals((string) ($target['sha256'] ?? ''), hash('sha256', $documentRaw))) {
                return new Response('Intégrité document incorrecte.', 409);
            }
        }
        // Keep original byte representation (after redaction), no re-serialization.
        return $this->render('forensic/profile_trace.html.twig', [
            'manifest' => $manifest,
            'run_id' => (int) $runId,
            'producer' => $producer,
            'trace_id' => $trace,
            'selected_document' => $selected,
            'raw_content' => $documentRaw,
            'documents' => $documents,
        ]);
    }


    /** Live FIFO/worker timeline rendered in the EXISTING forensic/profile_trace.html.twig.
     * Local-only, read-only, whitelisted JSONL. Does not expose paths, subprocess output or secrets.
     */
    #[Route('/workbench/profile/forensic-diagnostic/live', name: 'profile_forensic_live', methods: ['GET'])]
    public function live(Request $request): Response
    {
        if (getenv('EZS_RAW_DIAGNOSTIC_LOCAL') !== '1'
            || !in_array((string)$request->server->get('REMOTE_ADDR'), ['127.0.0.1','::1'], true)) {
            return new Response('Diagnostic local désactivé.', 403);
        }
        $run = filter_var($request->query->get('run_id'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $global = filter_var($request->query->get('global_job_id'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (($run === false || $run === null) && ($global === false || $global === null)) {
            return new Response('run_id ou global_job_id requis.', 422);
        }
        $runId = is_int($run) ? $run : 0;
        $globalId = is_int($global) ? $global : 0;
        $file = 'H:/temp/EZS_FIFO_TRACE_R1/events.jsonl';
        $events = [];
        $history = [];
        $relatedGlobal = [];
        if (is_file($file) && is_readable($file)) {
            $handle = fopen($file, 'rb');
            if (is_resource($handle)) {
                $size = (int)filesize($file);
                if ($size > 524288) fseek($handle, -524288, SEEK_END);
                while (($line = fgets($handle)) !== false) {
                    $row = json_decode($line, true);
                    if (!is_array($row) || !is_string($row['event'] ?? null)) continue;
                    $history[] = $row;
                    if ($runId && (int)($row['run_id'] ?? 0) === $runId
                        && (int)($row['global_job_id'] ?? 0) > 0) {
                        $relatedGlobal[(int)$row['global_job_id']] = true;
                    }
                }
                fclose($handle);
            }
        }
        foreach ($history as $row) {
            $gid = (int)($row['global_job_id'] ?? 0);
            if (($runId && (int)($row['run_id'] ?? 0) === $runId)
                || ($globalId && $gid === $globalId)
                || ($runId && $gid > 0 && isset($relatedGlobal[$gid]))) {
                $events[] = $row;
                if (count($events) > 400) array_shift($events);
            }
        }
        $raw = json_encode(['schema'=>'ezs.e2e-live-diagnostic.r1', 'run_id'=>$runId,
            'global_job_id'=>$globalId, 'event_count'=>count($events), 'events'=>$events],
            JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return $this->render('forensic/profile_trace.html.twig', [
            'manifest'=>['completeness'=>'chronologie locale','omissions'=> $events ? [] : ['aucune trace correspondante (ou accès journal refusé)']],
            'run_id'=>$runId, 'producer'=>'lab', 'trace_id'=>'live',
            'selected_document'=>'timeline', 'raw_content'=>$raw ?: '{}', 'documents'=>[],
        ]);
    }

}
