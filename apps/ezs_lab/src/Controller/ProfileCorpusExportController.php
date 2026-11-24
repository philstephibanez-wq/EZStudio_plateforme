<?php

declare(strict_types=1);

namespace EZSLab\Controller;

use EZSLab\Service\Database;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** PROFILE-only, on-demand, bounded corpus export. Historical revisions are never changed. */
final class ProfileCorpusExportController extends AbstractController
{
    private const MAX_RUNS = 1000;
    private const MAX_ZIP_BYTES = 150_000_000;

    #[Route('/profile/export-all-scientific.zip', name: 'profile_corpus_export', methods: ['POST'])]
    public function download(Request $request, Database $database, ProfileScientificExportController $single): Response
    {
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
        $limit = (string) $request->request->get('revisions', '5');
        if (!in_array($limit, ['2', '5', 'all'], true)) {
            return new Response('Choix invalide : 2, 5 ou Toutes.', 400);
        }
        $pdo = $database->pdo();
        try {
            $selection = $this->selectRuns($pdo, $limit);
        } catch (\Throwable $e) {
            return new Response('Sélection PROFILE impossible.', 422);
        }
        $ids = $selection['run_ids'];
        if (count($ids) > self::MAX_RUNS) {
            return new Response('Plus de 1000 runs sélectionnés : export par lots nécessaire.', 413);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ezs_profiles_');
        if ($tmp === false) return new Response('Répertoire temporaire indisponible.', 503);
        $outer = new \ZipArchive();
        if ($outer->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return new Response('Impossible de créer le ZIP.', 503);
        }
        $index = [];
        $exportedRevisions = 0;
        try {
            foreach ($ids as $id) {
                // Reuses validated per-run export, including redaction and artifact SHA256 validation.
                // The per-run exporter returns ALL historical revisions: filter BEFORE adding to corpus.
                $response = $single->download($id, $request, $database);
                if (!$response instanceof BinaryFileResponse) {
                    throw new \RuntimeException('per_run_export_failed:'.$id.':'.$response->getStatusCode());
                }
                $innerPath = $response->getFile()->getPathname();
                try {
                    $inner = new \ZipArchive();
                    if ($inner->open($innerPath) !== true) {
                        throw new \RuntimeException('per_run_zip_invalid:'.$id);
                    }
                    $runManifest = null;
                    $reviews = null;
                    $entries = [];
                    try {
                        for ($i = 0; $i < $inner->numFiles; ++$i) {
                            $filename = $inner->getNameIndex($i);
                            if (!is_string($filename) || !preg_match('/^[a-z_]+\.json$/D', $filename)) {
                                throw new \RuntimeException('unexpected_run_entry:'.$id);
                            }
                            $contents = $inner->getFromIndex($i);
                            if (!is_string($contents)) throw new \RuntimeException('unreadable_run_entry:'.$id);
                            if ($filename === 'manifest.json') {
                                $runManifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                            } elseif ($filename === 'expertise_revisions.json') {
                                $reviews = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                            } else {
                                $entries[$filename] = $contents;
                            }
                        }
                    } finally {
                        $inner->close();
                    }
                    if (!is_array($runManifest) || (int)($runManifest['run_id'] ?? 0) !== $id || !is_array($reviews)) {
                        throw new \RuntimeException('per_run_manifest_invalid:'.$id);
                    }
                    $allowed = $selection['revision_ids_by_run'][$id] ?? [];
                    $kept = array_values(array_filter($reviews, static fn($rev): bool =>
                        is_array($rev) && isset($allowed[(int)($rev['id'] ?? 0)])
                    ));
                    usort($kept, static fn(array $a, array $b): int => (int)$b['id'] <=> (int)$a['id']);
                    $revIds = array_map(static fn(array $rev): int => (int)$rev['id'], $kept);
                    if (count($kept) !== count($allowed)) {
                        throw new \RuntimeException('selected_revision_missing:'.$id);
                    }
                    $runManifest['revision_ids'] = $revIds;
                    $runManifest['selected_revision_limit'] = $limit;
                    $runManifest['all_revision_ids_exported'] = ($limit === 'all');
                    $entries['expertise_revisions.json'] = $this->encode($kept);
                    $entries['manifest.json'] = $this->encode($runManifest);
                    foreach ($entries as $filename => $contents) {
                        if (!$outer->addFromString(sprintf('runs/%06d/%s', $id, $filename), $contents)) {
                            throw new \RuntimeException('corpus_zip_write_failed');
                        }
                    }
                    $exportedRevisions += count($kept);
                    $index[] = [
                        'run_id' => $id,
                        'song_id' => $selection['song_by_run'][$id],
                        'audio_sha256' => $runManifest['audio_sha256'] ?? null,
                        'revision_ids' => $revIds,
                        'included' => $runManifest['included'] ?? [],
                        'missing_or_unavailable' => $runManifest['missing_or_unavailable'] ?? [],
                        'redaction_count' => $runManifest['redaction_count'] ?? 0,
                        'latest_run_for_song' => isset($selection['latest_run_ids'][$id]),
                    ];
                    // Size is the actual compressed ZIP on disk, not the repeated raw JSON size.
                    // Periodically flush local archive entries to enforce a hard bound after closure.
                } finally {
                    @unlink($innerPath);
                }
            }
            $manifest = [
                'schema' => 'ezstudio.profile-corpus-export.v2',
                'generated_at_utc' => gmdate('c'),
                'phase' => 'profile',
                'selection' => [
                    'revision_limit_per_song' => $limit,
                    'selection_rule' => 'latest_expertise_revisions_per_song_plus_latest_profile_run',
                    'selected_revision_count' => $exportedRevisions,
                    'total_revision_count' => $selection['total_revision_count'],
                    'song_count' => $selection['song_count'],
                ],
                'run_count' => count($index),
                'runs' => $index,
                'notes' => [
                    'Export manuel en lecture seule, sans reanalyse et sans audio.',
                    'Les revisions non selectionnees restent disponibles en base et via lexport individuel.',
                    'Les runs sans expertise figurent lorsque ce sont les derniers runs de leur chanson.',
                    'La concordance documentaire et la precision musicale sont des mesures distinctes.',
                ],
            ];
            if (!$outer->addFromString('manifest.json', $this->encode($manifest))) {
                throw new \RuntimeException('corpus_manifest_write_failed');
            }
            if (!$outer->close()) throw new \RuntimeException('corpus_zip_close_failed');
            if (filesize($tmp) > self::MAX_ZIP_BYTES) {
                @unlink($tmp);
                return new Response('Archive supérieure à 150 Mo compressés : choisir 2 expertises ou réduire le corpus.', 413);
            }
        } catch (\Throwable $e) {
            @$outer->close();
            @unlink($tmp);
            return new Response('Export global refusé : '.$e->getMessage(), 422, ['Content-Type'=>'text/plain; charset=UTF-8']);
        }
        $file = new BinaryFileResponse($tmp);
        $file->deleteFileAfterSend(true);
        $file->headers->set('Content-Type', 'application/zip');
        $file->headers->set('Content-Disposition', 'attachment; filename="EZS_PROFILE_CATALOGUE_'.($limit === 'all' ? 'TOUTES' : $limit).'_EXPERTISES.zip"');
        $file->headers->set('Cache-Control', 'no-store, private');
        $file->headers->set('X-Content-Type-Options', 'nosniff');
        return $file;
    }

    /** @return array{run_ids:list<int>, revision_ids_by_run:array<int,array<int,bool>>, latest_run_ids:array<int,bool>, song_by_run:array<int,int>, song_count:int, total_revision_count:int} */
    private function selectRuns(\PDO $pdo, string $limit): array
    {
        // Always group by song_id. Do not mix same-title recordings or silently select only one run.
        $runs = $pdo->query("SELECT id,song_id FROM scientific_runs WHERE item='profile' ORDER BY id DESC")->fetchAll(\PDO::FETCH_ASSOC);
        $latestBySong = [];
        $songByRun = [];
        foreach ($runs as $run) {
            $id = (int)$run['id'];
            $song = (int)$run['song_id'];
            $songByRun[$id] = $song;
            if (!isset($latestBySong[$song])) $latestBySong[$song] = $id;
        }
        $selected = array_fill_keys(array_values($latestBySong), true);
        $revisionIdsByRun = [];
        $perSong = [];
        $totalRevisions = 0;
        $tableCheck = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='profile_external_revisions'")->fetchColumn();
        if ($tableCheck !== false) {
            $q = $pdo->query("SELECT e.id,e.run_id,r.song_id FROM profile_external_revisions e JOIN scientific_runs r ON r.id=e.run_id WHERE r.item='profile' ORDER BY e.id DESC");
            while (($revision = $q->fetch(\PDO::FETCH_ASSOC)) !== false) {
                ++$totalRevisions;
                $song = (int)$revision['song_id'];
                if ($limit !== 'all' && ($perSong[$song] ?? 0) >= (int)$limit) continue;
                $id = (int)$revision['run_id'];
                $revisionIdsByRun[$id][(int)$revision['id']] = true;
                $selected[$id] = true;
                $perSong[$song] = ($perSong[$song] ?? 0) + 1;
            }
        }
        $ids = array_map('intval', array_keys($selected));
        sort($ids, SORT_NUMERIC);
        return [
            'run_ids' => $ids,
            'revision_ids_by_run' => $revisionIdsByRun,
            'latest_run_ids' => array_fill_keys(array_values($latestBySong), true),
            'song_by_run' => $songByRun,
            'song_count' => count($latestBySong),
            'total_revision_count' => $totalRevisions,
        ];
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    }
}
