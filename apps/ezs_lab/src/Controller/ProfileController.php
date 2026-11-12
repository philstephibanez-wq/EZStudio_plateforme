<?php

namespace EZSLab\Controller;

use EZSLab\Service\AnalysisRunRegistry;
use EZSLab\Service\ProfileExecutionService;
use EZSLab\Service\ProfileFrenchSummary;
use EZSLab\Service\ProfileExternalReviewService;
use EZSLab\Service\ProfileValidationService;
use EZSLab\Service\WorkbenchCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    private function songId(Request $request): ?int
    {
        $value = filter_var($request->query->get('song'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return ($value === false || $value === null) ? null : (int)$value;
    }

    #[Route('/workbench/profile', name: 'workbench_profile', methods: ['GET'])]
    public function index(
        Request $request,
        WorkbenchCatalog $catalog,
        AnalysisRunRegistry $runRegistry,
        ProfileExecutionService $execution,
        ProfileFrenchSummary $summary,
        ProfileValidationService $validation,
        ProfileExternalReviewService $externalReview,
        ?\EZSLab\Service\ProfileReportPublication $reportPublication = null,
    ): Response {
        $song = $catalog->selectedSong($this->songId($request));
        $runs = [];
        $selected = null;

        if ($song) {
            $seen = [];
            foreach ($song['song_ids'] ?? [(int)$song['id']] as $songId) {
                foreach ($runRegistry->runsForSongItem((int)$songId, 'profile') as $row) {
                    $id = (int)$row['id'];
                    if (isset($seen[$id])) continue;
                    $seen[$id] = true;
                    $full = $runRegistry->run($id);
                    if ($full) $runs[] = $full;
                }
            }
            usort($runs, static fn(array $a, array $b): int => (int)$b['id'] <=> (int)$a['id']);

            $requested = filter_var($request->query->get('run'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($requested !== false && $requested !== null) {
                foreach ($runs as $candidate) {
                    if ((int)$candidate['id'] === (int)$requested) {
                        $selected = $candidate;
                        break;
                    }
                }
            }
            if ($selected === null && $runs !== []) $selected = $runs[0];
        }

        return $this->render('workbench/profile.html.twig', [
            'songs' => $catalog->songs(),
            'selected_song' => $song,
            'profile_runs' => $runs,
            'profile_report_publication_enabled' => ($reportPublication ?? new \EZSLab\Service\ProfileReportPublication())->enabled(),
            'selected_profile_run' => $selected,
            'profile_job' => $selected ? $execution->jobForRun((int)$selected['id']) : null,
            'profile_summary' => $selected ? $summary->build($selected) : null,
            'profile_external_revisions' => $selected ? $externalReview->revisionsForRun((int)$selected['id']) : [],
            'profile_validation_subjects' => $selected
                ? $validation->subjectsForRun($selected)
                : [],
            'profile_validations' => $selected
                ? $validation->validationsForRun((int)$selected['id'])
                : [],
            'profile_validation_stats' => $selected
                ? $validation->statsForRun(
                    (int)$selected['id'],
                    array_column(
                        $validation->subjectsForRun($selected),
                        'subject_key'
                    )
                )
                : [
                    'reviewed' => 0,
                    'ok' => 0,
                    'ko' => 0,
                    'unknown' => 0,
                    'known' => 0,
                    'accuracy' => null,
                ],
        ]);
    }

    #[Route(
        '/profile/{id<\d+>}/validate',
        name: 'profile_validate',
        methods: ['POST']
    )]
    public function validate(
        int $id,
        Request $request,
        AnalysisRunRegistry $runRegistry,
        ProfileValidationService $validation,
    ): Response {
        $run = $runRegistry->run($id);
        if (!$run || (string)($run['item'] ?? '') !== 'profile') {
            return new Response('Run PROFILE introuvable.', 404);
        }

        $annotations = $request->request->all('annotations');
        try {
            $validation->save(
                $run,
                is_array($annotations) ? $annotations : [],
            );
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return new Response(
                'Validation refusÃ©e : '.htmlspecialchars(
                    $e->getMessage(),
                    ENT_QUOTES
                ),
                409
            );
        }

        return new RedirectResponse(
            $this->generateUrl('workbench_profile', [
                'song' => (int)$run['song_id'],
                'run' => (int)$run['id'],
            ])
        );
    }

    #[Route('/workbench/profile/song/{id<\\d+>}/identity',name:'profile_song_identity',methods:['POST'])]
    public function updateIdentity(int $id, Request $request, \EZSLab\Service\SongIdentityEditor $editor): Response
    {
        // Same-origin AJAX with non-simple header; no Symfony Form/CSRF extension installed.
        if ($request->headers->get('X-EZS-Song-Identity') !== 'save') {
            return new Response('RequÃªte non autorisÃ©e.',403);
        }
        $origin=$request->headers->get('Origin');
        if ($origin !== null && $origin !== $request->getSchemeAndHttpHost()) {
            return new Response('Origine non autorisÃ©e.',403);
        }
        $site=$request->headers->get('Sec-Fetch-Site');
        if ($site !== null && !in_array($site,['same-origin','none'],true)) {
            return new Response('Origine non autorisÃ©e.',403);
        }
        try {
            $editor->update($id, (string)$request->request->get('title',''),
                (string)$request->request->get('artist',''));
        } catch (\InvalidArgumentException $e) {
            return new Response($e->getMessage(),422);
        } catch (\RuntimeException $e) {
            return new Response($e->getMessage(),404);
        }
        return new \Symfony\Component\HttpFoundation\JsonResponse(['ok'=>true,'song_id'=>$id]);
    }

    #[Route('/profile/{id<\d+>}/status', name: 'profile_status', methods: ['GET'])]
    public function status(int $id, AnalysisRunRegistry $runs): Response
    {
        $run = $runs->run($id);
        if (!$run || ($run['item'] ?? '') !== 'profile') {
            return new Response('PROFILE introuvable', 404);
        }
        $state = (string) $run['state'];
        $percent = (int) ($run['labels']['progress'] ?? 0);
        return new \Symfony\Component\HttpFoundation\JsonResponse([
            'state' => $state,
            'progress' => max(0, min(100, $percent)),
        ], 200, ['Cache-Control' => 'no-store']);
    }
    /** Read-only observation of actual LAB one-shot jobs and runner progress. */
    #[Route('/profile/song/{id<\d+>}/oneshot-status', name: 'profile_oneshot_status', methods: ['GET'])]
    public function oneShotStatus(int $id, \EZSLab\Service\Database $db): Response
    {
        $stmt = $db->pdo()->prepare(<<<'SQL'
SELECT j.id AS job_id,j.scientific_run_id AS run_id,j.status AS job_status,
       j.progress AS job_progress,r.state AS run_state
FROM analysis_jobs j
JOIN scientific_runs r ON r.id=j.scientific_run_id AND r.item='profile'
WHERE j.song_id=? AND j.kind='profile' AND j.dispatch_owner='lab'
ORDER BY j.id DESC LIMIT 1
SQL);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $this->json(['available'=>false], 200, ['Cache-Control'=>'no-store']);
        }
        $jobId = (int)$row['job_id'];
        $state = (string)$row['run_state'];
        $percent = max(0, min(100, (int)$row['job_progress']));
        $stage = null;
        $observedAt = null;
        if ((string)$row['job_status'] === 'running' && $state === 'running') {
            $root = dirname(__DIR__, 2);
            // The path is fixed by the trusted integer job ID; never from HTTP input.
            $progress = $root.'/var/tmp/jobs/'.$jobId.'/progress.json';
            if (is_file($progress) && filesize($progress) <= 4096) {
                $raw = json_decode((string)file_get_contents($progress), true);
                if (is_array($raw) && is_int($raw['percent'] ?? null)
                    && is_string($raw['message'] ?? null)) {
                    $percent = max($percent, min(99, max(0, $raw['percent'])));
                    $stage = mb_substr(trim($raw['message']), 0, 140);
                    $observedAt = is_numeric($raw['updated_at'] ?? null)
                        ? (int)$raw['updated_at'] : null;
                }
            }
        }
        return $this->json([
            'available'=>true,
            'job_id'=>$jobId,
            'run_id'=>(int)$row['run_id'],
            'state'=>$state,
            'job_status'=>(string)$row['job_status'],
            'cancellation_requested'=>(string)$row['job_status'] === 'cancelling',
            'progress'=>$state === 'done' ? 100 : $percent,
            'stage'=>$stage,
            'observed_at'=>$observedAt,
        ], 200, ['Cache-Control'=>'no-store']);
    }

    /** Request cancellation of this LAB one-shot only. Never kill a PID from a request. */
    #[Route('/profile/oneshot/{id<\d+>}/cancel', name: 'profile_once_cancel', methods: ['POST'])]
    public function cancelOneShot(int $id, Request $request, \EZSLab\Service\Database $db, \EZSLab\Service\LocalProfileQueue $queue): Response
    {
        $origin = (string)$request->headers->get('Origin', '');
        $site = (string)$request->headers->get('Sec-Fetch-Site', '');
        if ($request->headers->get('X-EZS-Profile-Cancel') !== 'request'
            || ($site !== '' && $site !== 'same-origin')
            || ($origin !== '' && $origin !== $request->getSchemeAndHttpHost())) {
            return $this->json(['ok'=>false, 'reason'=>'origin_forbidden'], 403);
        }
        $job = $db->analysisJob($id);
        if ($job === null || (string)$job['kind'] !== 'profile'
            || (string)($job['dispatch_owner'] ?? '') !== 'lab'
            || (int)($job['scientific_run_id'] ?? 0) <= 0) {
            return $this->json(['ok'=>false, 'reason'=>'not_local_profile'], 404);
        }
        if ((string)$job['status'] === 'queued') {
            $updated = $db->requestAnalysisJobCancellation($id);
            try { $queue->wake(); }
            catch (\Throwable $error) { error_log('profile_queue_cancel_wake: '.$error->getMessage()); }
            return $this->json(['ok'=>true, 'job_id'=>$id, 'state'=>(string)$updated['status'],
                'message'=>'Job retirÃ© de la file avant exÃ©cution.'],202,['Cache-Control'=>'no-store']);
        }
        if (!in_array((string)$job['status'], ['running','cancelling'], true)) {
            return $this->json(['ok'=>false, 'reason'=>'job_not_active'], 409);
        }
        // An owned one-shot context must exist; orphan/legacy jobs are not cancellable here.
        $root = dirname(__DIR__, 2);
        $jobFile = $root.'/var/tmp/profile-once/'.$id.'/job.json';
        if (!is_file($jobFile)) {
            return $this->json(['ok'=>false, 'reason'=>'oneshot_context_missing'], 409);
        }
        try {
            $updated = $db->requestAnalysisJobCancellation($id);
        } catch (\Throwable $error) {
            return $this->json(['ok'=>false, 'reason'=>'cancel_refused'], 409);
        }
        if ((string)($updated['status'] ?? '') !== 'cancelling') {
            return $this->json(['ok'=>false, 'reason'=>'job_already_terminal'], 409);
        }
        return $this->json([
            'ok'=>true, 'job_id'=>$id, 'state'=>'cancelling',
            'message'=>'Annulation demandÃ©e ; arrÃªt du worker non encore confirmÃ©.',
        ], 202, ['Cache-Control'=>'no-store']);
    }
    /** Admission commune : une chanson devient un job FIFO, sans exÃ©cution PHP HTTP. */
    #[Route('/profile/analyze', name: 'profile_analyze', methods: ['GET','POST'])]
    public function analyze(Request $request, \EZSLab\Service\LocalProfileQueue $queue): Response
    {
        if ($request->isMethod('GET')) return new RedirectResponse($this->generateUrl('workbench_profile'));
        $site = (string)$request->headers->get('Sec-Fetch-Site','');
        $origin = (string)$request->headers->get('Origin','');
        $referer = (string)$request->headers->get('Referer','');
        $base = $request->getSchemeAndHttpHost();
        if (($site !== '' && $site !== 'same-origin') || ($origin !== '' && $origin !== $base)
            || ($site === '' && $origin === '' && !str_starts_with($referer,$base.'/'))) {
            return new Response('Origine du formulaire refusÃ©e.',403);
        }
        $songId = filter_var($request->request->get('song'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($songId === false || $songId === null) return new Response('Chanson invalide.',400);
        try {
            $created = $queue->enqueue((int)$songId);
            $queue->wake();
        } catch (\DomainException $error) {
            return new Response($error->getMessage(),409);
        } catch (\Throwable $error) {
            return new Response('Admission conservÃ©e ou refusÃ©e ; vÃ©rifier la queue locale et les journaux : '.$error->getMessage(),500);
        }
        $previousJobId = max(0, $created['job_id'] - 1);
        return new RedirectResponse($this->generateUrl('workbench_profile', [
            'song'=>(int)$songId, 'oneshot'=>'started', 'after_job'=>$previousJobId,
        ]));
    }

}
