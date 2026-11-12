<?php

namespace EZSLab\Controller;

use EZSLab\Service\Database;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LabController extends AbstractController
{
    #[Route('/', name: 'lab_index', methods: ['GET'])]
    public function index(Request $request, Database $db): Response
    {
        $songs = $db->allSongs();

        $requestedSongId = filter_var(
            $request->query->get('song'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        $selectedSong = null;
        if ($requestedSongId !== false && $requestedSongId !== null) {
            $selectedSong = $db->song((int)$requestedSongId);
        }
        if ($selectedSong === null && $songs !== []) {
            $selectedSong = $db->song((int)$songs[0]['id']);
        }

        $workbenchRuns = [];
        $latestRun = null;

        if ($selectedSong !== null) {
            $latestRun = $db->latestRunForSong((int)$selectedSong['id']);

            foreach ($db->runsForSong((int)$selectedSong['id']) as $row) {
                $result = !empty($row['result_json'])
                    ? json_decode((string)$row['result_json'], true)
                    : null;

                $stems = is_array($result)
                    ? ($result['no_chord_benchmark']['stems'] ?? null)
                    : null;

                $workbenchRuns[] = [
                    'row' => $row,
                    'stems_ready' => is_array($stems) && !empty($stems['available']),
                    'chords_ready' => (string)($row['status'] ?? '') === 'done',
                    'no_chord_ready' => is_array($result)
                        && !empty($result['no_chord_benchmark']['active_variant']),
                    'lyrics_ready' => false,
                    'engine_version' => is_array($result)
                        ? ($result['engine_version'] ?? $row['engine_version'])
                        : $row['engine_version'],
                ];
            }
        }

        return $this->render('lab/index.html.twig', [
            'songs' => $songs,
            'selected_song' => $selectedSong,
            'latest_run' => $latestRun,
            'runs' => $workbenchRuns,
            'scores' => $db->globalScores(),
        ]);
    }

    #[Route('/lab/run/{id<\d+>}', name: 'lab_run', methods: ['GET'])]
    public function run(int $id, Database $db): Response
    {
        $run = $db->run($id);
        if (!$run) {
            throw $this->createNotFoundException();
        }

        $result = !empty($run['result_json'])
            ? json_decode((string)$run['result_json'], true)
            : null;

        $observability = is_array($result)
            ? ($result['observability'] ?? null)
            : null;

        $plots = [];
        if (is_array($observability) && !empty($observability['artifact_root'])) {
            $runJson = rtrim((string)$observability['artifact_root'], '\\/')
                .DIRECTORY_SEPARATOR.'run.json';
            if (is_file($runJson)) {
                $doc = json_decode((string)file_get_contents($runJson), true);
                if (is_array($doc) && isset($doc['plots']) && is_array($doc['plots'])) {
                    foreach ($doc['plots'] as $plot) {
                        if (is_string($plot) && strtolower(pathinfo($plot, PATHINFO_EXTENSION)) === 'png') {
                            $plots[] = basename($plot);
                        }
                    }
                }
            }
        }

        return $this->render('lab/run.html.twig', [
            'run' => $run,
            'result' => $result,
            'algorithms' => $db->algorithms($id),
            'logs' => $db->logs($id, 250),
            'observability' => $observability,
            'plots' => array_values(array_unique($plots)),
        ]);
    }

    #[Route('/lab/run/{id<\d+>}/plot/{file}', name: 'lab_plot', methods: ['GET'])]
    public function plot(int $id, string $file, Database $db): Response
    {
        if ($file !== basename($file) || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'png') {
            throw $this->createNotFoundException();
        }

        $run = $db->run($id);
        if (!$run || empty($run['result_json'])) {
            throw $this->createNotFoundException();
        }

        $result = json_decode((string)$run['result_json'], true);
        $root = is_array($result)
            ? ($result['observability']['artifact_root'] ?? null)
            : null;
        if (!is_string($root) || $root === '') {
            throw $this->createNotFoundException();
        }

        $path = rtrim($root, '\\/').DIRECTORY_SEPARATOR.'plots'.DIRECTORY_SEPARATOR.$file;
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($path, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    #[Route('/lab/run/{id<\d+>}/scientific-export', name: 'lab_export', methods: ['GET'])]
    public function scientificExport(int $id, Database $db): Response
    {
        $run = $db->run($id);
        if (!$run || empty($run['result_json'])) {
            throw $this->createNotFoundException();
        }

        $result = json_decode((string)$run['result_json'], true);
        $zip = is_array($result)
            ? ($result['observability']['scientific_zip'] ?? null)
            : null;
        if (!is_string($zip) || !is_file($zip)) {
            throw $this->createNotFoundException();
        }

        return (new BinaryFileResponse($zip))
            ->setContentDisposition('attachment', basename($zip));
    }
}
