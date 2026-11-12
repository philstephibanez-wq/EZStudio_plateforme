<?php

declare(strict_types=1);

namespace EZSLab\Controller;

use EZSLab\Service\Database;
use EZSLab\Service\DistributedAnalysisLeaseService;
use EZSLab\Service\ChordsExecutionService;
use EZSLab\Service\LabAnalysisTokenGuard;
use EZSLab\Service\ProfileExecutionService;
use EZSLab\Service\ProfileExternalReviewService;
use EZSLab\Service\StemsExecutionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/internal/analysis/desktop')]
final class AnalysisDesktopController extends AbstractController
{
    public function __construct(
        private readonly LabAnalysisTokenGuard $guard,
        private readonly Database $db,
        private readonly DistributedAnalysisLeaseService $distributed,
        private readonly ProfileExecutionService $profileExecution,
        private readonly ProfileExternalReviewService $profileExternalReview,
        private readonly ChordsExecutionService $chordsExecution,
        private readonly StemsExecutionService $stemsExecution,
    ) {}

    #[Route('/profile/reviews/queue', name: 'lab_internal_profile_review_queue', methods: ['GET'])]
    public function profileReviewQueue(Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $limit = filter_var(
            $request->query->get('limit', 100),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 500]]
        );
        if ($limit === false) {
            return $this->json(['error' => 'limit_must_be_1_500'], 422);
        }

        $reviews = $this->profileExternalReview->queue((int)$limit);
        return $this->json([
            'schema' => 'ezstudio.profile-review-queue.v1',
            'count' => count($reviews),
            'reviews' => $reviews,
        ]);
    }

    #[Route('/profile/reviews/latest', name: 'lab_internal_profile_review_latest', methods: ['GET'])]
    public function latestProfileReview(Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $rawRunId = $request->query->get('run_id');
        $runId = null;
        if ($rawRunId !== null) {
            $runId = filter_var($rawRunId, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]);
            if ($runId === false) {
                return $this->json(['error' => 'run_id_invalid'], 422);
            }
        }
        return $this->json(['ok'=>true,
            'item'=>$this->profileExternalReview->latestReviewForDisplay($runId)]);
    }

    #[Route('/profile/reviews/run/{id<\d+>}', name: 'lab_internal_profile_review_run', methods: ['GET'])]
    public function profileReviewRun(Request $request, int $id): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $item = $this->profileExternalReview->reviewItemForRun($id);
        if ($item === null) return $this->json(['error'=>'profile_run_not_available'],404);
        return $this->json(['item'=>$item]);
    }

    #[Route('/profile/reviews/run/{id<\d+>}/final-assertions', name: 'lab_internal_profile_final_assertions', methods: ['GET'])]
    public function profileFinalAssertions(Request $request, int $id): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        try {
            $data = $this->profileExternalReview->assertionsForRun($id);
            return $this->json(['schema'=>'ezstudio.profile-final-assertions.v1',
                'run_id'=>$id,'count'=>$data['final_assertions_count'],
                'assertions'=>$data['final_assertions']]);
        } catch (\RuntimeException $e) {
            return $this->json(['error'=>$e->getMessage()],409);
        }
    }

    #[Route('/profile/reviews', name: 'lab_internal_profile_review_submit', methods: ['POST'])]
    public function profileReviewSubmit(Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        try {
            $revision = $this->profileExternalReview->save($request->toArray());
            return $this->json([
                'schema' => 'ezstudio.profile-review-submit.v1',
                'ok' => true,
                'revision' => $revision,
            ]);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }
    #[Route('/hello', name: 'lab_internal_analysis_hello', methods: ['POST'])]
    public function hello(Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        return $this->json(['schema_version'=>'ezstudio.analysis-desktop.v1','target'=>'lab','server_time'=>gmdate('c'),'state'=>['ok'=>true],'commands'=>[]]);
    }

    #[Route('/heartbeat', name: 'lab_internal_analysis_heartbeat', methods: ['POST'])]
    public function heartbeat(Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        return $this->json(['schema_version'=>'ezstudio.analysis-desktop.v1','target'=>'lab','server_time'=>gmdate('c'),'state'=>['ok'=>true],'commands'=>[]]);
    }

    #[Route('/jobs/queue', name: 'lab_internal_analysis_queue', methods: ['GET'])]
    public function queue(Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        return $this->json(['schema_version'=>'ezstudio.analysis-desktop.v1','jobs'=>$this->db->analysisQueue()]);
    }

    #[Route('/jobs/claim', name: 'lab_internal_analysis_claim', methods: ['POST'])]
    public function claim(Request $request): Response
    {
        $this->guard->assertAuthorized($request);
        $job=$this->db->claimNextAnalysisJob();
        if($job===null) return new Response('',Response::HTTP_NO_CONTENT);
        return $this->json($this->db->analysisJobContext((int)$job['id']));
    }

    #[Route('/jobs/{id<\d+>}/context', name: 'lab_internal_analysis_context', methods: ['GET'])]
    public function context(int $id, Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $job=$this->db->analysisJob($id);
        if($job===null) return $this->json(['error'=>'job_not_found'],404);
        return $this->json($this->db->analysisJobContext($id));
    }

    #[Route('/jobs/{id<\d+>}/progress', name: 'lab_internal_analysis_progress', methods: ['POST'])]
    public function progress(int $id, Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $payload=$request->toArray();
        $value=filter_var($payload['progress']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100]]);
        if($value===false) return $this->json(['error'=>'progress_must_be_0_100'],422);
        if(!$this->db->updateAnalysisJobProgress($id,(int)$value)) return $this->json(['error'=>'job_not_running'],409);
        $this->profileExecution->progressFromJob($id,(int)$value);
        $this->stemsExecution->progressFromJob($id,(int)$value);
        $this->chordsExecution->progressFromJob($id,(int)$value);
        return $this->json(['job_id'=>$id,'status'=>'running','progress'=>(int)$value]);
    }

    #[Route('/jobs/{id<\d+>}/complete', name: 'lab_internal_analysis_complete', methods: ['POST'])]
    public function complete(int $id, Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        try{
            $this->db->completeAnalysisJob($id);
            $this->profileExecution->completeFromJob($id);
            $this->stemsExecution->completeFromJob($id);
            $this->chordsExecution->completeFromJob($id);
        }catch(\RuntimeException $e){return $this->json(['error'=>$e->getMessage()],409);}
        return $this->json(['job_id'=>$id,'status'=>'completed','progress'=>100]);
    }


    #[Route('/jobs/{id<\d+>}/cancelled', name: 'lab_internal_analysis_cancelled', methods: ['POST'])]
    public function cancelled(int $id, Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        try {
            $this->db->markAnalysisJobCancelled($id);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        return $this->json([
            'job_id' => $id,
            'status' => 'cancelled',
        ]);
    }

    #[Route('/jobs/{id<\d+>}/fail', name: 'lab_internal_analysis_fail', methods: ['POST'])]
    public function fail(int $id, Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $payload=$request->toArray();
        $error=trim((string)($payload['error']??'orchestrator_job_failed'));
        $this->db->failAnalysisJob($id,$error);
        $this->profileExecution->failFromJob($id,$error);
        $this->stemsExecution->failFromJob($id,$error);
        $this->chordsExecution->failFromJob($id,$error);
        return $this->json(['job_id'=>$id,'status'=>'failed']);
    }

    #[Route('/distributed/workers/heartbeat', name: 'lab_internal_distributed_worker_heartbeat', methods: ['POST'])]
    public function distributedWorkerHeartbeat(Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $payload = $request->toArray();
        try {
            $result = $this->distributed->heartbeatWorker(
                (string)($payload['worker_id'] ?? ''),
                is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [],
                (string)($payload['state'] ?? 'idle')
            );
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
        return $this->json($result);
    }

    #[Route('/distributed/workers', name: 'lab_internal_distributed_workers', methods: ['GET'])]
    public function distributedWorkers(Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        return $this->json([
            'schema_version' => 'ezstudio.distributed.workers.v1',
            'workers' => $this->distributed->workersSnapshot(),
        ]);
    }
    #[Route('/distributed/jobs/eligible', name: 'lab_internal_distributed_jobs_eligible', methods: ['POST'])]
    public function distributedEligibleJobs(Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $payload = $request->toArray();
        try {
            $jobs = $this->distributed->eligibleJobs(
                (string)($payload['worker_id'] ?? ''),
                max(1, min(500, (int)($payload['limit'] ?? 100)))
            );
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
        return $this->json([
            'schema_version' => 'ezstudio.distributed.jobs.v1',
            'jobs' => $jobs,
        ]);
    }

    #[Route('/distributed/jobs/{id<\d+>}/claim', name: 'lab_internal_distributed_job_claim', methods: ['POST'])]
    public function distributedClaim(int $id, Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $payload = $request->toArray();
        try {
            $context = $this->distributed->claim(
                $id,
                (string)($payload['worker_id'] ?? ''),
                (int)($payload['lease_seconds'] ?? 60)
            );
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
        return $this->json($context);
    }

    #[Route('/distributed/jobs/{id<\d+>}/lease-heartbeat', name: 'lab_internal_distributed_job_lease_heartbeat', methods: ['POST'])]
    public function distributedLeaseHeartbeat(int $id, Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $payload = $request->toArray();
        try {
            $result = $this->distributed->renewLease(
                $id,
                (string)($payload['worker_id'] ?? ''),
                (string)($payload['lease_token'] ?? ''),
                (int)($payload['lease_seconds'] ?? 60)
            );
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
        return $this->json($result);
    }

    #[Route('/distributed/jobs/{id<\d+>}/context', name: 'lab_internal_distributed_job_context', methods: ['POST'])]
    public function distributedContext(int $id, Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $payload = $request->toArray();
        try {
            $context = $this->distributed->context(
                $id,
                (string)($payload['worker_id'] ?? ''),
                (string)($payload['lease_token'] ?? '')
            );
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
        return $this->json($context);
    }

    #[Route('/distributed/jobs/{id<\d+>}/progress', name: 'lab_internal_distributed_job_progress', methods: ['POST'])]
    public function distributedProgress(int $id, Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $payload = $request->toArray();
        $value = filter_var(
            $payload['progress'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 100]]
        );
        if ($value === false) {
            return $this->json(['error' => 'progress_must_be_0_100'], 422);
        }

        try {
            $this->distributed->assertLease(
                $id,
                (string)($payload['worker_id'] ?? ''),
                (string)($payload['lease_token'] ?? '')
            );
            if (!$this->db->updateAnalysisJobProgress($id, (int)$value)) {
                return $this->json(['error' => 'job_not_running'], 409);
            }
            $this->profileExecution->progressFromJob($id, (int)$value);
            $this->stemsExecution->progressFromJob($id, (int)$value);
            $this->chordsExecution->progressFromJob($id, (int)$value);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        return $this->json([
            'job_id' => $id,
            'status' => 'running',
            'progress' => (int)$value,
        ]);
    }

    #[Route('/distributed/jobs/{id<\d+>}/complete', name: 'lab_internal_distributed_job_complete', methods: ['POST'])]
    public function distributedComplete(int $id, Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $payload = $request->toArray();
        $workerId = (string)($payload['worker_id'] ?? '');
        $leaseToken = (string)($payload['lease_token'] ?? '');

        try {
            $this->distributed->assertLease($id, $workerId, $leaseToken);
            $this->db->completeAnalysisJob($id);
            $this->profileExecution->completeFromJob($id);
            $this->stemsExecution->completeFromJob($id);
            $this->chordsExecution->completeFromJob($id);
            $this->distributed->releaseLease($id, $workerId, $leaseToken);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        return $this->json([
            'job_id' => $id,
            'status' => 'completed',
            'progress' => 100,
        ]);
    }

    #[Route('/distributed/jobs/{id<\d+>}/cancelled', name: 'lab_internal_distributed_job_cancelled', methods: ['POST'])]
    public function distributedCancelled(int $id, Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $payload = $request->toArray();
        $workerId = (string)($payload['worker_id'] ?? '');
        $leaseToken = (string)($payload['lease_token'] ?? '');

        try {
            $this->distributed->assertLease($id, $workerId, $leaseToken);
            $this->db->markAnalysisJobCancelled($id);
            $this->distributed->releaseLease($id, $workerId, $leaseToken);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        return $this->json([
            'job_id' => $id,
            'status' => 'cancelled',
        ]);
    }

    #[Route('/distributed/jobs/{id<\d+>}/fail', name: 'lab_internal_distributed_job_fail', methods: ['POST'])]
    public function distributedFail(int $id, Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $payload = $request->toArray();
        $workerId = (string)($payload['worker_id'] ?? '');
        $leaseToken = (string)($payload['lease_token'] ?? '');
        $error = trim((string)($payload['error'] ?? 'distributed_worker_job_failed'));

        try {
            $this->distributed->assertLease($id, $workerId, $leaseToken);
            $this->db->failAnalysisJob($id, $error);
            $this->profileExecution->failFromJob($id, $error);
            $this->stemsExecution->failFromJob($id, $error);
            $this->chordsExecution->failFromJob($id, $error);
            $this->distributed->releaseLease($id, $workerId, $leaseToken);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        return $this->json([
            'job_id' => $id,
            'status' => 'failed',
        ]);
    }

}
