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
        if (!in_array($producer, ['lab', 'assistant'], true) || $runId === false
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
        if (is_array($manifest) && ($manifest['schema'] ?? '') === 'ezstudio.assistant-profile-raw-archive.v1') {
            if ($producer !== 'assistant' || ($manifest['run_id'] ?? null) !== (int) $runId
                || !is_string($manifest['trace_id'] ?? null)
                || !hash_equals($trace, substr($manifest['trace_id'], 0, 12))) {
                return new Response('Manifeste Assistant invalide.', 409);
            }
            return $this->renderAssistant($request, $manifest, $raw, $root, $prefix, $runId, $trace, $selected);
        }
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

    /** Reassemble and verify the canonical Assistant 1.7a17 raw archive (no schema rewrite). */
    private function renderAssistant(Request $request, array $manifest, string $raw, string $root,
        string $prefix, int $runId, string $trace, string $selected): Response
    {
        $rows = $manifest['artifacts'] ?? null;
        if (!is_array($rows) || ($manifest['artifact_count'] ?? -1) !== count($rows)) {
            return new Response('Index Assistant invalide.', 409);
        }
        $groups = [];
        foreach ($rows as $item) {
            if (!is_array($item) || !is_string($item['path'] ?? null)
                || !preg_match('~^(?:timeline-[0-9]{4}\.jsonl|(?:source-sets|assistant-preset-evidence|stage-observations)\.json\.part-[0-9]{4}|snapshots/[0-9]{4}_[A-Za-z0-9_.-]+\.json\.part-[0-9]{4})$~D', $item['path'])) {
                return new Response('Chemin Assistant invalide.', 409);
            }
            $group = preg_replace('/\.part-[0-9]{4}$/D', '', $item['path']);
            if (!is_string($group)) return new Response('Index Assistant invalide.', 409);
            $groups[$group][] = $item;
        }
        $documents = [];
        $indices = [];
        $number = 0;
        foreach ($groups as $name => $items) {
            $label = sprintf('doc-%04d', $number++);
            $indices[$label] = $items;
            $documents[] = ['logical_name' => $label, 'display_name' => $name, 'fragment_count' => count($items)];
        }
        $documentRaw = $raw;
        if ($selected !== '') {
            if (!isset($indices[$selected])) return new Response('Document Assistant absent.', 404);
            $documentRaw = '';
            $expectedPart = 0;
            foreach ($indices[$selected] as $part) {
                $name = $part['path'];
                if (preg_match('/\.part-([0-9]{4})$/D', $name, $match)) {
                    if ((int) $match[1] !== $expectedPart++) return new Response('Séquence de segments incorrecte.', 409);
                }
                $bytes = @file_get_contents($root.'/'.$prefix.$name);
                if (!is_string($bytes) || strlen($bytes) !== ($part['size_bytes'] ?? -1)
                    || !hash_equals((string) ($part['sha256'] ?? ''), hash('sha256', $bytes))) {
                    return new Response('Intégrité du segment Assistant incorrecte.', 409);
                }
                $documentRaw .= $bytes;
            }
        }
        return $this->render('forensic/profile_trace.html.twig', [
            'manifest' => $manifest, 'run_id' => $runId, 'producer' => 'assistant',
            'trace_id' => $trace, 'selected_document' => $selected,
            'raw_content' => $documentRaw, 'documents' => $documents,
        ]);
    }

}
