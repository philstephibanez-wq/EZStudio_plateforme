<?php

namespace EZSLab\Controller;

use EZSLab\Service\AnalysisRunRegistry;
use EZSLab\Service\CatalogService;
use EZSLab\Service\Database;
use EZSLab\Service\ProfileExecutionService;
use EZSLab\Service\WorkbenchCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WorkbenchController extends AbstractController
{
    private const AUDIO_EXTENSIONS = ['wav','mp3','flac','ogg','oga','m4a','aac'];

    private function songId(Request $request): ?int
    {
        $value = filter_var(
            $request->query->get('song'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        return ($value === false || $value === null) ? null : (int)$value;
    }

    private function runId(Request $request): ?int
    {
        $value = filter_var(
            $request->query->get('run'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        return ($value === false || $value === null) ? null : (int)$value;
    }

    #[Route('/home', name: 'workbench_home', methods: ['GET'])]
    public function home(
        CatalogService $catalog,
        \EZSLab\Service\LocalQueueVisibility $visibility,
    ): Response {
        $songs = $catalog->songs();
        $queue = $visibility->snapshot($songs);
        return $this->render('workbench/home.html.twig', [
            'songs' => $songs,
            'queue_active' => $queue['active'],
            'expertise_by_song' => $queue['expertise_by_song'],
        ]);
    }

    #[Route('/profile/analyze-all', name: 'profile_analyze_all', methods: ['POST'])]
    public function analyzeAllProfiles(Request $request, \EZSLab\Service\LocalProfileQueue $queue, WorkbenchCatalog $catalog): Response
    {
        $site = (string)$request->headers->get('Sec-Fetch-Site','');
        $origin = (string)$request->headers->get('Origin','');
        $referer = (string)$request->headers->get('Referer','');
        $base = $request->getSchemeAndHttpHost();
        if (($site !== '' && $site !== 'same-origin') || ($origin !== '' && $origin !== $base)
            || ($site === '' && $origin === '' && !str_starts_with($referer,$base.'/'))) {
            return new Response('Origine du formulaire refusÃ©e.',403);
        }
        $queued = 0; $skippedActive = 0; $skippedMissingSource = 0;
        foreach ($catalog->songs() as $song) {
            try {
                $result = $queue->enqueue((int)$song['id']);
                if ($result['created']) ++$queued;
                else ++$skippedActive;
            } catch (\DomainException) {
                ++$skippedMissingSource;
            }
        }
        return new RedirectResponse($this->generateUrl('workbench_home', [
            'profile_all_queued'=>$queued,
            'profile_all_skipped_active'=>$skippedActive,
            'profile_all_skipped_source'=>$skippedMissingSource,
        ]));
    }

    #[Route('/profile/export-all.zip', name: 'profile_export_all_zip', methods: ['GET'])]
    public function exportAllProfilesZip(): Response
    {
        $projectDir = dirname(__DIR__, 2);
        $script = $projectDir
            .DIRECTORY_SEPARATOR.'scripts'
            .DIRECTORY_SEPARATOR.'export-profile-results.py';

        if (!is_file($script)) {
            return new Response('profile_exporter_missing', 500);
        }

        $python = trim((string)(
            getenv('BENCH_PYTHON')
            ?: 'H:\\Python\\pythoncore-3.14-64\\python.exe'
        ));
        if ($python === '' || !is_file($python)) {
            return new Response('profile_export_python_missing', 500);
        }

        $tempRoot = is_dir('H:\\temp') ? 'H:\\temp' : sys_get_temp_dir();
        $output = $tempRoot
            .DIRECTORY_SEPARATOR
            .'EZStudio_PROFILE_ALL_'
            .gmdate('Ymd_His')
            .'_'.bin2hex(random_bytes(3))
            .'.zip';

        $command = escapeshellarg($python)
            .' '.escapeshellarg($script)
            .' --repo '.escapeshellarg($projectDir)
            .' --output '.escapeshellarg($output)
            .' 2>&1';

        $lines = [];
        $exitCode = 0;
        exec($command, $lines, $exitCode);

        if ($exitCode !== 0 || !is_file($output)) {
            return new Response(
                "profile_export_failed\n".implode("\n", $lines),
                500,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        $response = $this->file($output, basename($output));
        // Explicit ZIP MIME avoids Symfony File::getMimeType(), which requires
        // the optional symfony/mime component.
        $response->headers->set('Content-Type', 'application/zip');
        $response->deleteFileAfterSend(true);
        return $response;
    }

    #[Route('/analysis/jobs/{id<\d+>}/cancel', name: 'analysis_job_cancel', methods: ['POST'])]
    public function cancelAnalysisJob(
        int $id,
        Request $request,
        Database $db,
        \EZSLab\Service\LocalProfileQueue $queue,
    ): Response {
        try {
            $current = $db->analysisJob($id);
            if ($current === null) throw new \DomainException('job_not_found');
            if ((string)($current['dispatch_owner'] ?? 'lab') === 'orchestrator') {
                throw new \DomainException('archived_global_job_read_only');
            }
            $job = $db->requestAnalysisJobCancellation($id);
            $queue->cancelInOrchestrator($job);
            if ((string)($job['status'] ?? '') === 'cancelled'
                && in_array((string)($job['kind'] ?? ''), ['profile','assistant.profile.review'], true)) {
            }
        } catch (\DomainException|\RuntimeException $e) {
            $code = preg_match('/^[a-z0-9_]+$/D',$e->getMessage()) ? $e->getMessage() : 'job_cancellation_refused';
            return $request->isXmlHttpRequest()
                ? $this->json(['error'=>$code],409)
                : new Response($code,409);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'job_id' => (int)($job['id'] ?? $id),
                'status' => (string)($job['status'] ?? ''),
                'progress' => (int)($job['progress'] ?? 0),
            ]);
        }

        return new RedirectResponse($this->generateUrl('workbench_home'));
    }

    #[Route('/import', name: 'workbench_import', methods: ['GET'])]
    public function import(Request $request, WorkbenchCatalog $catalog): Response
    {
        return $this->render('workbench/import.html.twig', [
            'songs' => $catalog->songs(),
            'selected_song' => $catalog->selectedSong($this->songId($request)),
        ]);
    }

    #[Route('/workbench/stems', name: 'workbench_stems', methods: ['GET'])]
    public function stems(
        Request $request,
        WorkbenchCatalog $catalog,
        AnalysisRunRegistry $runRegistry,
    ): Response {
        $song = $catalog->selectedSong($this->songId($request));
        $runs = [];
        $selectedRun = null;

        if ($song) {
            $seen = [];
            foreach ($song['song_ids'] ?? [(int)$song['id']] as $songId) {
                foreach ($runRegistry->runsForSongItem((int)$songId, 'stems') as $row) {
                    $id = (int)$row['id'];
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $full = $runRegistry->run($id);
                    if ($full) {
                        $full['audio_artifacts'] = $this->audioArtifacts(
                            $full['artifacts'] ?? []
                        );
                        $runs[] = $full;
                    }
                }
            }

            usort(
                $runs,
                static fn(array $a, array $b): int => (int)$b['id'] <=> (int)$a['id']
            );

            $requestedRunId = $this->runId($request);
            foreach ($runs as $candidate) {
                if ($requestedRunId !== null && (int)$candidate['id'] === $requestedRunId) {
                    $selectedRun = $candidate;
                    break;
                }
            }

            if ($selectedRun === null) {
                foreach ($runs as $candidate) {
                    if (
                        (string)$candidate['state'] === 'done'
                        && ($candidate['audio_artifacts'] ?? []) !== []
                    ) {
                        $selectedRun = $candidate;
                        break;
                    }
                }
            }

            if ($selectedRun === null && $runs !== []) {
                $selectedRun = $runs[0];
            }
        }

        return $this->render('workbench/stems.html.twig', [
            'songs' => $catalog->songs(),
            'selected_song' => $song,
            'latest_run' => $catalog->latestRun($song),
            'stems_runs' => $runs,
            'selected_stems_run' => $selectedRun,
        ]);
    }

    #[Route('/workbench/chords', name: 'workbench_chords', methods: ['GET'])]
    public function chords(Request $request, WorkbenchCatalog $catalog): Response
    {
        $song = $catalog->selectedSong($this->songId($request));
        $run = $catalog->latestChordsRun($song);

        if ($run === null) {
            return $this->render('workbench/no_chords.html.twig', [
                'songs' => $catalog->songs(),
                'selected_song' => $song,
            ]);
        }

        return new RedirectResponse(
            $this->generateUrl('bench_view', ['id' => (int)$run['id']])
        );
    }

    #[Route('/workbench/lyrics', name: 'workbench_lyrics', methods: ['GET'])]
    public function lyrics(Request $request, WorkbenchCatalog $catalog): Response
    {
        $song = $catalog->selectedSong($this->songId($request));

        return $this->render('workbench/lyrics.html.twig', [
            'songs' => $catalog->songs(),
            'selected_song' => $song,
            'latest_run' => $catalog->latestRun($song),
        ]);
    }

    #[Route('/workbench/runs', name: 'workbench_runs', methods: ['GET'])]
    public function runs(Request $request, WorkbenchCatalog $catalog): Response
    {
        $song = $catalog->selectedSong($this->songId($request));

        return $this->render('workbench/runs.html.twig', [
            'songs' => $catalog->songs(),
            'selected_song' => $song,
            'runs' => $song['runs'] ?? [],
        ]);
    }

    private function audioArtifacts(array $artifacts): array
    {
        $out = [];

        foreach ($artifacts as $artifact) {
            $path = trim((string)($artifact['path'] ?? ''));
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($path === '' || !in_array($ext, self::AUDIO_EXTENSIONS, true)) {
                continue;
            }

            $metadata = json_decode((string)($artifact['metadata_json'] ?? ''), true);
            if (!is_array($metadata)) {
                $metadata = [];
            }

            $role = (string)($artifact['role'] ?? 'audio');
            $artifact['metadata'] = $metadata;
            $artifact['group'] = $this->artifactGroup($role, $metadata);
            $artifact['harmonic_default'] = $this->harmonicDefault($role, $metadata);
            $artifact['display_label'] = $this->displayLabel($role, $metadata);
            $artifact['confidence'] = $this->confidence($metadata);

            $out[] = $artifact;
        }

        usort($out, static function(array $a, array $b): int {
            $groups = ['vocals'=>0,'rhythm'=>1,'bass'=>2,'guitar'=>3,'keys'=>4,'strings'=>5,'winds'=>6,'plucked'=>7,'other'=>8];
            $ga = $groups[(string)$a['group']] ?? 99;
            $gb = $groups[(string)$b['group']] ?? 99;
            return $ga <=> $gb ?: strcasecmp((string)$a['display_label'], (string)$b['display_label']);
        });

        return $out;
    }

    private function artifactGroup(string $role, array $metadata): string
    {
        $taxonomy = strtolower((string)($metadata['taxonomy_group'] ?? ''));
        if ($taxonomy !== '') {
            return $taxonomy;
        }

        $r = strtolower($role);
        if (str_contains($r, 'vocal') || str_contains($r, 'voice')) return 'vocals';
        if (str_contains($r, 'drum') || str_contains($r, 'kick') || str_contains($r, 'snare')
            || str_contains($r, 'cymbal') || str_contains($r, 'percussion') || str_contains($r, 'hi_hat')) return 'rhythm';
        if (str_contains($r, 'bass') || str_contains($r, 'contrabass')) return 'bass';
        if (str_contains($r, 'guitar')) return 'guitar';
        if (str_contains($r, 'piano') || str_contains($r, 'organ') || str_contains($r, 'synth')
            || str_contains($r, 'keys')) return 'keys';
        if (str_contains($r, 'violin') || str_contains($r, 'viola') || str_contains($r, 'cello')
            || str_contains($r, 'string')) return 'strings';
        if (str_contains($r, 'flute') || str_contains($r, 'sax') || str_contains($r, 'brass')
            || str_contains($r, 'reed') || str_contains($r, 'wind')) return 'winds';
        if (str_contains($r, 'banjo') || str_contains($r, 'mandolin') || str_contains($r, 'ukulele')
            || str_contains($r, 'harp') || str_contains($r, 'pluck')) return 'plucked';
        return 'other';
    }

    private function harmonicDefault(string $role, array $metadata): bool
    {
        if (array_key_exists('harmonic_default', $metadata)) {
            return (bool)$metadata['harmonic_default'];
        }

        return in_array(
            $this->artifactGroup($role, $metadata),
            ['bass','guitar','keys','strings','winds','plucked','other'],
            true
        );
    }

    private function displayLabel(string $role, array $metadata): string
    {
        foreach (['instrument_label','taxonomy_label','display_label'] as $key) {
            $value = trim((string)($metadata[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return ucwords(str_replace(['_','-'], ' ', $role));
    }

    private function confidence(array $metadata): ?float
    {
        foreach (['instrument_confidence','classifier_confidence','confidence'] as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                return max(0.0, min(1.0, (float)$metadata[$key]));
            }
        }
        return null;
    }
}
