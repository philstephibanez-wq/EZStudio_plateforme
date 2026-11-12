<?php

namespace EZSLab\Controller;

use EZSLab\Service\ChordsExperimentService;
use EZSLab\Service\ChordsExecutionService;
use EZSLab\Service\AnalysisRunRegistry;
use EZSLab\Service\LabCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ChordsExperimentController extends AbstractController
{
    #[Route('/chords/experiment', name: 'lab_chords_experiment', methods: ['GET'])]
    public function index(
        Request $request,
        LabCatalog $catalog,
        ChordsExperimentService $experiments,
        AnalysisRunRegistry $runRegistry,
    ): Response {
        $songId = filter_var(
            $request->query->get('song'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $song = $catalog->song(
            ($songId === false || $songId === null) ? null : (int)$songId
        );

        return $this->render('lab/chords_experiment.html.twig', [
            'songs' => $catalog->songs(),
            'selected_song' => $song,
            'stems_runs' => $song
                ? $experiments->stemsRuns((int)$song['id'])
                : [],
            'chords_runs' => $song
                ? $runRegistry->runsForSongItem((int)$song['id'], 'chords')
                : [],
            'legacy_runs' => $song ? ($song['runs'] ?? []) : [],
        ]);
    }

    #[Route('/chords/experiment/create', name: 'lab_chords_experiment_create', methods: ['POST'])]
    public function create(
        Request $request,
        LabCatalog $catalog,
        ChordsExperimentService $experiments,
        ChordsExecutionService $execution,
    ): Response {
        $songId = (int)$request->request->get('song', 0);
        $parentRunId = (int)$request->request->get('parent_stems_run_id', 0);

        $song = $catalog->song($songId);
        if (!$song) {
            return new Response('Chanson introuvable.', 404);
        }

        try {
            $run = $experiments->createConfiguredRun(
                (int)$song['id'],
                $parentRunId,
                $request->request->all('chord_inputs'),
                $request->request->all('no_chord_inputs'),
                (string)$request->request->get('engine', 'lv-chordia'),
                (string)$request->request->get('signature', 'Auto'),
            );
        } catch (\InvalidArgumentException $e) {
            return new Response('Configuration CHORDS invalide : '.$e->getMessage(), 400);
        }

        try {
            $execution->queue((int)$run['id']);
        } catch (\Throwable $e) {
            return new Response(
                'Run CHORDS créé mais mise en queue impossible : '.$e->getMessage(),
                500
            );
        }

        return new RedirectResponse(
            $this->generateUrl('scientific_run', ['id' => (int)$run['id']])
        );
    }
}
