<?php

namespace EZSLab\Controller;

use EZSLab\Service\Database;
use EZSLab\Service\AnalysisRunRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ScientificRunController extends AbstractController
{
    #[Route('/scientific-runs/song/{id<\d+>}', name: 'scientific_run_song', methods: ['GET'])]
    public function song(int $id, Database $db, AnalysisRunRegistry $runRegistry): Response
    {
        $stmt = $db->pdo()->prepare(
            'SELECT * FROM songs WHERE id=?'
        );
        $stmt->execute([$id]);
        $song = $stmt->fetch();

        if (!$song) {
            throw $this->createNotFoundException();
        }

        return $this->render('scientific_run/song.html.twig', [
            'song' => $song,
            'runs' => $runRegistry->runsForSong($id),
        ]);
    }

    #[Route('/scientific-runs/run/{id<\d+>}', name: 'scientific_run', methods: ['GET'])]
    public function run(int $id, AnalysisRunRegistry $runRegistry): Response
    {
        $run = $runRegistry->run($id);
        if (!$run) {
            throw $this->createNotFoundException();
        }

        return $this->render('scientific_run/run.html.twig', [
            'run' => $run,
            'lineage' => $runRegistry->lineage($id),
        ]);
    }

    #[Route('/scientific-runs/run/{id<\d+>}.json', name: 'scientific_run_json', methods: ['GET'])]
    public function runJson(int $id, AnalysisRunRegistry $runRegistry): JsonResponse
    {
        $run = $runRegistry->run($id);
        if (!$run) {
            return new JsonResponse(['error' => 'run_not_found'], 404);
        }

        return new JsonResponse([
            'run' => $run,
            'lineage' => $runRegistry->lineage($id),
        ]);
    }

    #[Route('/scientific-runs/compare/{item<profile|stems|chords|lyrics>}', name: 'scientific_run_compare', methods: ['GET'])]
    public function compare(string $item, AnalysisRunRegistry $runRegistry): Response
    {
        return $this->render('scientific_run/compare.html.twig', [
            'item' => $item,
            'rows' => $runRegistry->compareAcrossSongs($item),
        ]);
    }
}
