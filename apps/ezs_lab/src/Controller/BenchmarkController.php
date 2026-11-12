<?php
namespace EZSLab\Controller;

use EZSLab\Service\BenchmarkLauncher;
use EZSLab\Service\Database;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BenchmarkController extends AbstractController
{
    #[Route('/legacy', name: 'bench_index', methods: ['GET'])]
    public function index(Database $db): Response
    {
        return $this->render('benchmark/index.html.twig', [
            'runs' => $db->allRuns(),
            'scores' => $db->globalScores(),
        ]);
    }

    #[Route('/run', name: 'bench_start', methods: ['POST'])]
    public function start(Request $request, Database $db, BenchmarkLauncher $launcher): Response
    {
        /** @var UploadedFile|null $audio */
        $audio = $request->files->get('audio');
        if (!$audio instanceof UploadedFile || !$audio->isValid()) return new Response('Fichier audio invalide.', 400);

        $signature = (string)$request->request->get('signature', 'Auto');
        $allowed = ['Auto','2/4','3/4','4/4','6/8','9/8','12/8'];
        if (!in_array($signature, $allowed, true)) return new Response('Signature invalide.', 400);

        $original = $audio->getClientOriginalName();
        $title = trim((string)$request->request->get('title', '')) ?: pathinfo($original, PATHINFO_FILENAME);
        $artist = trim((string)$request->request->get('artist', ''));

        $sha = hash_file('sha256', $audio->getPathname());
        if ($sha === false) return new Response('Impossible de calculer SHA-256.', 500);

        $songId = $db->createSong($title, $artist, $original, $sha);
        $projectDir = (string)$this->getParameter('kernel.project_dir');
        $audioDir = $projectDir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'benchmark-audio';
        if (!is_dir($audioDir)) mkdir($audioDir, 0777, true);

        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowedExt = ['mp3','wav','flac','ogg','oga','m4a','mp4','aac'];
        if (!in_array($ext, $allowedExt, true)) $ext = 'bin';

        $placeholder = $audioDir.DIRECTORY_SEPARATOR.'pending-'.$songId.'.'.$ext;
        $audio->move($audioDir, basename($placeholder));
        $runId = $db->createRun($songId, $signature, $placeholder);
        $final = $audioDir.DIRECTORY_SEPARATOR.'run-'.$runId.'.'.$ext;
        rename($placeholder, $final);
        $db->pdo()->prepare('UPDATE benchmark_runs SET input_path=? WHERE id=?')->execute([$final, $runId]);

        try {
            $launcher->launch($runId, $final, $signature);
        } catch (\Throwable $e) {
            return new Response('Analyse en erreur : '.htmlspecialchars($e->getMessage(), ENT_QUOTES), 500);
        }

        return new RedirectResponse($this->generateUrl('bench_view', ['id' => $runId]));
    }

    #[Route('/run/{id<\d+>}', name: 'bench_view', methods: ['GET'])]
    public function view(int $id, Database $db): Response
    {
        $run = $db->run($id);
        if (!$run) throw $this->createNotFoundException();

        $audioAvailable = is_file((string)$run['input_path']);
        $audioUrl = $audioAvailable ? '/benchmark-audio/'.rawurlencode(basename((string)$run['input_path'])) : null;
        $result = !empty($run['result_json']) ? json_decode((string)$run['result_json'], true) : null;

        $phaseDiagnostics = [];
        if (is_array($result) && isset($result['algorithms']) && is_array($result['algorithms'])) {
            foreach ($result['algorithms'] as $item) {
                if (isset($item['algorithm'])) $phaseDiagnostics[(string)$item['algorithm']] = $item['phase_scores'] ?? [];
            }
        }

        return $this->render('benchmark/view.html.twig', [
            'run' => $run,
            'algorithms' => $db->algorithms($id),
            'review' => $db->runReview($id),
            'audio_available' => $audioAvailable,
            'audio_url' => $audioUrl,
            'meter_diagnostic' => is_array($result) ? ($result['meter'] ?? null) : null,
            'convergence' => is_array($result) ? ($result['convergence'] ?? null) : null,
            'phase_diagnostics' => $phaseDiagnostics,
            'no_chord_benchmark' => is_array($result) ? ($result['no_chord_benchmark'] ?? null) : null,
        ]);
    }

    #[Route('/run/{id<\d+>}/reanalyze-cached-stems', name: 'bench_reanalyze_cached_stems', methods: ['GET','POST'])]
    public function reanalyzeCachedStems(
        int $id,
        Database $db,
        BenchmarkLauncher $launcher
    ): Response {
        $sourceRun = $db->run($id);
        if (!$sourceRun) {
            throw $this->createNotFoundException();
        }

        $audioPath = (string)($sourceRun['input_path'] ?? '');
        if ($audioPath === '' || !is_file($audioPath)) {
            return new Response(
                'Ré-analyse impossible : audio source absent.',
                409
            );
        }

        $audioHash = trim((string)($sourceRun['audio_sha256'] ?? ''));
        if ($audioHash === '') {
            $computed = hash_file('sha256', $audioPath);
            if ($computed === false) {
                return new Response(
                    'Ré-analyse impossible : SHA-256 audio indisponible.',
                    500
                );
            }
            $audioHash = $computed;
        }

        // Contract V8B: this path MUST reuse already-generated stems.
        // It must never trigger a new stem separation.
        $cacheRoot = 'H:\\temp\\EZStudio_lab\\stems';
        $currentJson = $cacheRoot
            .DIRECTORY_SEPARATOR.$audioHash
            .DIRECTORY_SEPARATOR.'current.json';

        if (!is_file($currentJson)) {
            return new Response(
                'Ré-analyse refusée : cache stems absent. Aucun redécoupage automatique autorisé.',
                409
            );
        }

        $signature = (string)($sourceRun['requested_signature'] ?? 'Auto');
        $newRunId = $db->createRun(
            (int)$sourceRun['song_id'],
            $signature,
            $audioPath
        );

        $db->pdo()->prepare(
            'INSERT INTO run_logs(run_id,created_at,level,message)
             VALUES(?,datetime(\'now\'),?,?)'
        )->execute([
            $newRunId,
            'INFO',
            'V8B ré-analyse demandée avec cache stems obligatoire : '.$currentJson,
        ]);

        try {
            $launcher->launch($newRunId, $audioPath, $signature);
        } catch (\Throwable $e) {
            $db->pdo()->prepare(
                "UPDATE benchmark_runs
                 SET status='error',updated_at=datetime('now'),error=?
                 WHERE id=?"
            )->execute([$e->getMessage(), $newRunId]);

            return new Response(
                'Ré-analyse en erreur : '.htmlspecialchars($e->getMessage(), ENT_QUOTES),
                500
            );
        }

        return new RedirectResponse(
            $this->generateUrl('bench_view', ['id' => $newRunId])
        );
    }

    #[Route('/run/{id<\d+>}/review', name: 'bench_review', methods: ['POST'])]
    public function review(int $id, Request $request, Database $db): Response
    {
        $run = $db->run($id);
        if (!$run) throw $this->createNotFoundException();

        $approved = $request->request->all('approved');
        $reference = trim((string)$request->request->get('reference', 'Riffstation'));
        $comment = trim((string)$request->request->get('comment', ''));

        $db->saveAlgorithmApprovals($id, is_array($approved) ? $approved : [], $reference, $comment);
        return new RedirectResponse($this->generateUrl('bench_view', ['id' => $id]));
    }

    #[Route('/run/{id<\d+>}/status', name: 'bench_status', methods: ['GET'])]
    public function status(int $id, Database $db): JsonResponse
    {
        $run = $db->run($id);
        if (!$run) {
            return new JsonResponse(['error' => 'run_not_found'], 404);
        }

        $logs = $db->logs($id, 12);
        $tail = [];
        foreach ($logs as $row) {
            $tail[] = [
                'level' => (string)($row['level'] ?? 'INFO'),
                'message' => (string)($row['message'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }

        return new JsonResponse([
            'id' => (int)$run['id'],
            'status' => (string)$run['status'],
            'progress' => (int)$run['progress'],
            'error' => $run['error'] !== null ? (string)$run['error'] : null,
            'updated_at' => (string)$run['updated_at'],
            'done' => (string)$run['status'] === 'done',
            'logs' => $tail,
        ]);
    }

    #[Route('/report', name: 'bench_report', methods: ['GET'])]
    public function report(Database $db): Response
    {
        return $this->render('benchmark/report.html.twig', [
            'scores' => $db->globalScores(),
            'rows' => $db->reportRows(),
        ]);
    }

    #[Route('/run/{id<\d+>}/delete', name: 'bench_delete', methods: ['POST'])]
    public function delete(int $id, Database $db): Response
    {
        $deleted = $db->deleteSongByRunId($id);
        if ($deleted === null) throw $this->createNotFoundException();

        foreach ($deleted['audio_paths'] as $audioPath) {
            if (is_string($audioPath) && $audioPath !== '' && is_file($audioPath)) @unlink($audioPath);
        }

        $projectDir = (string)$this->getParameter('kernel.project_dir');
        $resultsDir = $projectDir.DIRECTORY_SEPARATOR.'results';
        foreach ($deleted['run_ids'] as $runId) {
            foreach (glob($resultsDir.DIRECTORY_SEPARATOR.sprintf('run-%06d-*', $runId)) ?: [] as $dir) {
                $this->removeTree($dir);
            }
        }

        return new RedirectResponse($this->generateUrl('bench_index'));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        $items = scandir($path);
        if ($items === false) return;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $child = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($child)) $this->removeTree($child); else @unlink($child);
        }
        @rmdir($path);
    }
}
