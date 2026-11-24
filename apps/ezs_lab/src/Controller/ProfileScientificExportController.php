<?php

declare(strict_types=1);

namespace EZSLab\Controller;

use EZSLab\Service\AudioSourceResolver;
use EZSLab\Service\Database;
use EZSLab\Service\LabResourceResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** On-demand, read-only evidence export. No publisher, scheduler or scientific computation. */
final class ProfileScientificExportController extends AbstractController
{
    private const MAX_PROFILE_JSON = 8_000_000;

    #[Route('/workbench/profile/{id<\d+>}/scientific-export', name: 'profile_scientific_export', methods: ['POST'])]
    public function download(int $id, Request $request, Database $database): Response
    {
        // A form POST must come from the same UI origin; never expose this export via GET.
        $site = (string) $request->headers->get('Sec-Fetch-Site', '');
        $origin = (string) $request->headers->get('Origin', '');
        $base = $request->getSchemeAndHttpHost();
        if (($site !== '' && $site !== 'same-origin')
            || ($origin !== '' && $origin !== $base)
            || ($site === '' && $origin === '')) {
            return new Response('Origine non autorisée.', 403);
        }
        if (!class_exists(\ZipArchive::class)) {
            return new Response('Extension PHP ZIP indisponible.', 503);
        }
        $pdo = $database->pdo();
        $stmt = $pdo->prepare('SELECT r.*, s.title, s.artist, s.audio_sha256 FROM scientific_runs r JOIN songs s ON s.id=r.song_id WHERE r.id=? AND r.item=?');
        $stmt->execute([$id, 'profile']);
        $run = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$run) {
            return new Response('Run PROFILE introuvable.', 404);
        }

        $missing = [];
        $redactions = [];
        $runFields = ['id','public_id','song_id','item','state','engine_name','engine_version','model_name','config_json','environment_json','metrics_json','diagnostics_json','human_validation_json','created_at','finished_at','title','artist','audio_sha256'];
        $scientific = [];
        foreach ($runFields as $key) {
            if (!array_key_exists($key, $run)) { $missing[] = 'scientific_runs.'.$key; continue; }
            $value = $run[$key];
            if (str_ends_with($key, '_json')) {
                try {
                    $value = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    $missing[] = 'scientific_runs.'.$key.':invalid_json';
                    $value = null;
                }
            }
            $scientific[$key] = $this->scrub($value, $redactions, 'run.'.$key);
        }
        $reviews = [];
        if ($this->tableExists($pdo, 'profile_external_revisions')) {
            $q = $pdo->prepare('SELECT id,run_id,revision_key,reviewer,status,identity_json,sources_json,consensus_json,comparison_json,confidence,schema_version,created_at,updated_at FROM profile_external_revisions WHERE run_id=? ORDER BY id');
            $q->execute([$id]);
            foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $record) {
                foreach (['identity_json','sources_json','consensus_json','comparison_json'] as $key) {
                    try { $record[$key] = json_decode((string)$record[$key], true, 512, JSON_THROW_ON_ERROR); }
                    catch (\JsonException $e) { $missing[] = 'expertise.'.($record['id'] ?? 'unknown').'.'.$key.':invalid_json'; $record[$key] = null; }
                }
                $reviews[] = $this->scrub($record, $redactions, 'expertise');
            }
            if (!$reviews) $missing[] = 'expertise:aucune_revision';
        } else {
            $missing[] = 'profile_external_revisions:table_absente';
        }

        $preset = $this->presetSnapshot($pdo, $id, $missing, $redactions);
        $artifactList = [];
        $profileDocument = null;
        if ($this->tableExists($pdo, 'scientific_artifacts')) {
            $q = $pdo->prepare('SELECT a.*, s.audio_sha256 FROM scientific_artifacts a JOIN scientific_runs r ON r.id=a.run_id JOIN songs s ON s.id=r.song_id WHERE a.run_id=? ORDER BY a.id');
            $q->execute([$id]);
            foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $artifact) {
                $role = (string) ($artifact['role'] ?? '');
                $artifactList[] = [
                    'artifact_id' => $artifact['artifact_id'] ?? null,
                    'role' => $role,
                    'sha256' => $artifact['sha256'] ?? null,
                    'created_at' => $artifact['created_at'] ?? null,
                    'metadata' => $this->scrub(json_decode((string)($artifact['metadata_json'] ?? '{}'), true) ?? [], $redactions, 'artifact.metadata'),
                ];
                if ($role !== 'profile_json' || $profileDocument !== null) continue;
                try {
                    $path = (new LabResourceResolver(new AudioSourceResolver()))->artifact($artifact);
                    if (filesize($path) > self::MAX_PROFILE_JSON) { $missing[] = 'profile_json:trop_volumineux'; continue; }
                    $raw = file_get_contents($path);
                    if (!is_string($raw)) throw new \RuntimeException('artifact_unreadable');
                    $doc = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($doc)) throw new \RuntimeException('artifact_not_json_object');
                    $profileDocument = $this->scrub($doc, $redactions, 'profile_json');
                } catch (\Throwable $e) {
                    $missing[] = 'profile_json:indisponible_ou_invalide';
                }
            }
            if ($profileDocument === null) $missing[] = 'profile_json:aucune_copie_exportable';
        } else {
            $missing[] = 'scientific_artifacts:table_absente';
        }

        $manifest = [
            'schema' => 'ezstudio.profile-scientific-export.v1',
            'generated_at_utc' => gmdate('c'),
            'run_id' => $id,
            'item' => 'profile',
            'audio_sha256' => $run['audio_sha256'] ?? null,
            'revision_ids' => array_column($reviews, 'id'),
            'included' => ['scientific_run.json','expertise_revisions.json','preset_snapshot.json','artifact_registry.json'],
            'missing_or_unavailable' => array_values(array_unique($missing)),
            'redaction_count' => count($redactions),
            'notes' => [
                'Export manuel en lecture seule. Pas de reanalyse, pas de publication GitHub.',
                'Aucun binaire audio, stem ou modele.',
                'Consensus documentaire et exactitude musicale independante sont deux mesures distinctes.',
                'Un artefact absent est signale, jamais reconstruit.',
            ],
        ];
        if ($profileDocument !== null) $manifest['included'][] = 'profile_analysis.json';
        $tmp = tempnam(sys_get_temp_dir(), 'ezs_profile_');
        if ($tmp === false) return new Response('Répertoire temporaire indisponible.', 503);
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return new Response('Impossible de créer le ZIP.', 503);
        }
        try {
            $files = [
                'scientific_run.json' => $scientific,
                'expertise_revisions.json' => $reviews,
                'preset_snapshot.json' => $preset,
                'artifact_registry.json' => $artifactList,
                'manifest.json' => $manifest,
            ];
            if ($profileDocument !== null) $files['profile_analysis.json'] = $profileDocument;
            foreach ($files as $name => $data) {
                $encoded = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
                if (strlen($encoded) > 16_000_000) throw new \RuntimeException('export_document_too_large');
                if (!$zip->addFromString($name, $encoded."\n")) throw new \RuntimeException('zip_write_failed');
            }
        } catch (\Throwable $e) {
            $zip->close(); @unlink($tmp);
            return new Response('Export impossible : documents non exportables.', 422);
        }
        if (!$zip->close()) { @unlink($tmp); return new Response('ZIP incomplet.', 503); }
        $response = new BinaryFileResponse($tmp);
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', 'attachment; filename="EZS_PROFILE_ANALYSE_EXPERTISE_RUN_'. $id .'.zip"');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }

    /** Exact preset lineage for the run, not the current promoted preset. */
    private function presetSnapshot(\PDO $pdo, int $id, array &$missing, array &$redactions): array
    {
        $tables = ['scientific_run_preset','preset_phase_revisions','preset_phase_revision_modules','preset_module_revisions'];
        foreach ($tables as $table) {
            if (!$this->tableExists($pdo, $table)) {
                $missing[] = 'preset:table_absente:'.$table;
                return [];
            }
        }
        $s = $pdo->prepare('SELECT * FROM scientific_run_preset WHERE run_id=?');
        $s->execute([$id]); $binding = $s->fetch(\PDO::FETCH_ASSOC);
        if (!$binding) { $missing[] = 'preset:run_sans_revision'; return []; }
        $revisionId = (int) $binding['phase_revision_id'];
        $s = $pdo->prepare('SELECT * FROM preset_phase_revisions WHERE id=?');
        $s->execute([$revisionId]); $phase = $s->fetch(\PDO::FETCH_ASSOC);
        if (!$phase) { $missing[] = 'preset:revision_introuvable'; return []; }
        $s = $pdo->prepare('SELECT prm.position,m.* FROM preset_phase_revision_modules prm JOIN preset_module_revisions m ON m.id=prm.module_revision_id WHERE prm.phase_revision_id=? ORDER BY prm.position');
        $s->execute([$revisionId]);
        $modules = $s->fetchAll(\PDO::FETCH_ASSOC);
        if (!$modules) $missing[] = 'preset:aucun_module_historique';
        foreach (['metadata_json'] as $key) {
            if (isset($phase[$key])) $phase[$key] = json_decode((string)$phase[$key], true) ?? [];
        }
        foreach ($modules as &$module) {
            if (isset($module['composition_json'])) {
                $module['composition_json'] = json_decode((string)$module['composition_json'], true) ?? [];
            }
        }
        unset($module);
        return $this->scrub(['binding'=>$binding,'phase_revision'=>$phase,'module_revisions'=>$modules], $redactions, 'preset');
    }

    /** Preserve scientific evidence while removing credentials, filesystem paths and URL query secrets. */
    private function scrub(mixed $value, array &$redactions, string $path): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                $name = (string) $key;
                $loc = $path.'.'.$name;
                if (preg_match('/(?:^|_)(?:token|secret|password|passwd|authorization|cookie|api_key|private_key|refresh_token|access_key|audio_bytes|waveform|pcm|binary|model_weights)(?:_|$)/i', $name)
                    || preg_match('/(?:^|_)(?:email|telephone|phone|address|session|jwt|user_home|source_path|file_path|input_path|output_path|checkpoint)(?:_|$)/i', $name)) {
                    $out[$key] = '[EXPURGE]'; $redactions[] = $loc;
                } else {
                    $out[$key] = $this->scrub($child, $redactions, $loc);
                }
            }
            return $out;
        }
        if (is_string($value)) {
            $old = $value;
            $value = preg_replace('/(?i)\b(?:bearer\s+\S{8,}|gh[pousr]_[a-z0-9_]{12,}|github_pat_[a-z0-9_]{12,}|sk-[a-z0-9_-]{12,})/', '[EXPURGE]', $value);
            $value = preg_replace('~(?i)(?:[a-z]:[\\\\/](?:users|utilisateurs)[\\\\/][^\\\\/\s]+|/(?:home|users)/[^/\s]+)~', '[CHEMIN_PRIVE]', $value);
            $value = preg_replace('~(?<![A-Za-z0-9])(?:[A-Za-z]:[\\\\/][^\s\"\x27<>]+)~', '[CHEMIN_LOCAL]', $value);
            // URLs retain their origin and path for independent verification, never their query/fragment.
            $value = preg_replace_callback('~https?://[^\s"\x27<>]+~i', static function ($m) {
                $u = parse_url($m[0]);
                if (!is_array($u) || empty($u['host']) || empty($u['scheme'])) return '[URL_EXPURGEE]';
                return $u['scheme'].'://'.$u['host'].(isset($u['port']) ? ':'.$u['port'] : '').($u['path'] ?? '');
            }, $value);
            if ($old !== $value) $redactions[] = $path;
            return $value;
        }
        return $value;
    }
}
