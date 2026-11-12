<?php

namespace EZSLab\Controller;

use EZSLab\Service\AnalysisRunRegistry;
use EZSLab\Service\LabCatalog;
use EZSLab\Service\StemsExecutionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StemsLabController extends AbstractController
{
    #[Route('/stems', name: 'lab_stems', methods: ['GET'])]
    public function index(
        Request $request,
        LabCatalog $catalog,
        AnalysisRunRegistry $runRegistry,
        StemsExecutionService $execution,
    ): Response {
        $songId = filter_var(
            $request->query->get('song'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $song = $catalog->song(
            ($songId === false || $songId === null) ? null : (int)$songId
        );

        $songJobs = $song ? $execution->jobsForSong((int)$song['id']) : [];
        $scientificRuns = [];
        foreach ($songJobs as $job) {
            $runId = (int)($job['scientific_run_id'] ?? 0);
            if ($runId > 0) {
                $run = $runRegistry->run($runId);
                if ($run) {
                    $scientificRuns[(int)$job['job_id']] = $run;
                }
            }
        }

        return $this->render('lab/stems.html.twig', [
            'songs' => $catalog->songs(),
            'selected_song' => $song,
            'jobs' => $songJobs,
            'scientific_runs' => $scientificRuns,
            'source_available' => $song ? $catalog->sourcePath($song) !== null : false,
        ]);
    }

    #[Route('/stems/analyze', name: 'lab_stems_analyze', methods: ['POST'])]
    public function analyze(
        Request $request,
        LabCatalog $catalog,
        AnalysisRunRegistry $runRegistry,
        StemsExecutionService $execution,
    ): Response {
        $songId = filter_var(
            $request->request->get('song'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($songId === false || $songId === null) {
            return new Response('Chanson invalide.', 400);
        }

        $song = $catalog->song((int)$songId);
        if ($song === null) {
            return new Response('Chanson introuvable.', 404);
        }

        $source = $catalog->sourcePath($song);
        if ($source === null) {
            return new Response(
                'Audio source indisponible : impossible de lancer STEMS.',
                409
            );
        }

        $force = $request->request->getBoolean('force', false);
        $engineProfile = trim((string)$request->request->get(
            'engine_profile',
            'canonical_roformer'
        ));
        if ($engineProfile === '') {
            $engineProfile = 'canonical_roformer';
        }

        $scientific = $runRegistry->createRun(
            (int)$song['id'],
            'stems',
            ['name' => $engineProfile],
            [
                'audio_hash' => (string)$song['audio_sha256'],
                'force_reanalysis' => $force,
                'engine_profile' => $engineProfile,
                'output_contract' => 'ezstudio.stems.v1',
            ],
        );

        $queued = $execution->queue(
            (int)$scientific['id'],
            $source,
            (string)$song['audio_sha256'],
            $force,
            $engineProfile,
        );

        return new RedirectResponse(
            $this->generateUrl('lab_stems_job', ['id' => (int)$queued['job_id']])
        );
    }

    #[Route('/stems/run', name: 'lab_stems_run', methods: ['POST'])]
    public function create(): Response
    {
        return new Response(
            'Endpoint historique désactivé : utiliser Import puis STEMS.',
            Response::HTTP_GONE
        );
    }

    #[Route('/stems/job/{id<\d+>}', name: 'lab_stems_job', methods: ['GET'])]
    public function job(
        int $id,
        AnalysisRunRegistry $runRegistry,
        StemsExecutionService $execution,
    ): Response {
        $job = $execution->jobView($id);
        if ($job === null) {
            throw $this->createNotFoundException();
        }

        $scientificRun = null;
        $scientificRunId = (int)($job['scientific_run_id'] ?? 0);
        if ($scientificRunId > 0) {
            $scientificRun = $runRegistry->run($scientificRunId);
        }

        $manifest = null;
        $diagnostics = null;
        $workerLog = '';
        $result = is_array($job['result'] ?? null) ? $job['result'] : [];

        if (!empty($result['manifest']) && is_file((string)$result['manifest'])) {
            $manifest = json_decode(
                (string)file_get_contents((string)$result['manifest']),
                true
            );
        }
        if (!empty($result['diagnostics']) && is_file((string)$result['diagnostics'])) {
            $diagnostics = json_decode(
                (string)file_get_contents((string)$result['diagnostics']),
                true
            );
        }

        $storageRoot = (string)($job['paths']['storage_root'] ?? '');
        $logPath = $storageRoot !== ''
            ? $storageRoot.DIRECTORY_SEPARATOR.'worker.log'
            : '';
        if ($logPath !== '' && is_file($logPath)) {
            $workerLog = (string)file_get_contents($logPath);
            if (strlen($workerLog) > 100000) {
                $workerLog = substr($workerLog, -100000);
            }
        }

        return $this->render('lab/stems_job.html.twig', [
            'job' => $job,
            'scientific_run' => $scientificRun,
            'manifest' => is_array($manifest) ? $manifest : null,
            'diagnostics' => is_array($diagnostics) ? $diagnostics : null,
            'worker_log' => $workerLog,
        ]);
    }
}
