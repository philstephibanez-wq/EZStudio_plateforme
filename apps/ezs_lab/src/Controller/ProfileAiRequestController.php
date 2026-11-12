<?php

declare(strict_types=1);
namespace EZSLab\Controller;

use EZSLab\Service\LabAnalysisTokenGuard;
use EZSLab\Service\ProfileAiRequestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileAiRequestController extends AbstractController
{
    public function __construct(private readonly ProfileAiRequestService $queue, private readonly LabAnalysisTokenGuard $guard) {}

    #[Route('/workbench/profile/{id<\d+>}/ai-review',name:'profile_ai_review_request',methods:['POST'])]
    public function request(int $id,Request $request): JsonResponse
    {
        // LAB does not install symfony/form or Twig csrf_token().
        // Require a non-simple same-origin AJAX request instead of a cross-site form POST.
        if ($request->headers->get('X-EZS-Profile-AI-Action') !== 'request') {
            return $this->json(['error'=>'same_origin_ajax_required'],403);
        }
        $origin = $request->headers->get('Origin');
        if ($origin !== null && $origin !== $request->getSchemeAndHttpHost()) {
            return $this->json(['error'=>'cross_origin_denied'],403);
        }
        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if ($fetchSite !== null && !in_array($fetchSite, ['same-origin','none'],true)) {
            return $this->json(['error'=>'cross_site_denied'],403);
        }
        try {return $this->json(['ok'=>true,'request'=>$this->queue->enqueue($id)],202);}
        catch (\RuntimeException $e) {return $this->json(['error'=>$e->getMessage()],409);}
    }

    #[Route('/workbench/profile/{id<\d+>}/ai-review',name:'profile_ai_review_status',methods:['GET'])]
    public function status(int $id): JsonResponse
    {
        return $this->json(['request'=>$this->queue->get($id)]);
    }

    #[Route('/internal/analysis/desktop/profile/{id<\d+>}/scientific-items',name:'lab_internal_profile_scientific_items',methods:['GET'])]
    public function scientificItems(Request $request,int $id,\EZSLab\Service\AnalysisRunRegistry $runs): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $run=$runs->run($id);
        if (!$run || ($run['item']??null)!=='profile' || ($run['state']??null)!=='done') {
            return $this->json(['error'=>'completed_profile_run_required'],404);
        }
        $diagnostics=$run['diagnostics']??[];
        $modules=$diagnostics['module_registry']['modules']??null;
        if (!is_array($modules) || !array_is_list($modules)) {
            return $this->json(['error'=>'scientific_module_registry_missing'],409);
        }
        $items=[]; $seen=[];
        foreach ($modules as $index=>$module) {
            $key=is_array($module)?($module['id']??null):null;
            if (!is_string($key) || $key==='' || isset($seen[$key])) {
                return $this->json(['error'=>'scientific_module_identity_invalid'],409);
            }
            $seen[$key]=true;
            $items[]=['index'=>$index+1,'item_id'=>$key,'label'=>$module['name']??$key,
                'family'=>$module['family']??null,'status'=>$module['status']??null,
                'source_pointer'=>'/diagnostics/module_registry/modules/'.$index,
                'module'=>$module];
        }
        return $this->json(['schema'=>'ezstudio.profile-scientific-items.r1',
            'run_id'=>$id,'item_total'=>count($items),'items'=>$items,
            'coverage'=>['registry_total'=>count($modules),'returned'=>count($items),
                'complete_for_registry'=>true,'database_exhaustiveness_verified'=>false]]);
    }

    #[Route('/internal/analysis/desktop/profile/ai-requests/maintenance',name:'lab_internal_profile_ai_maintenance',methods:['GET','POST'])]
    public function maintenance(Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        try {
            $mode=$request->isMethod('GET') ? 'status' : (string)($request->toArray()['mode']??'');
            return $this->json($this->queue->maintenance($mode));
        } catch (\Throwable $e) { return $this->json(['ok'=>false,'error'=>$e->getMessage()],409); }
    }

    #[Route('/internal/analysis/desktop/profile/ai-requests/recent', name: 'lab_internal_profile_ai_recent', methods: ['GET'])]
    public function recent(Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $limit = filter_var($request->query->get('limit', 80), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) return $this->json(['error' => 'invalid_limit'], 422);
        return $this->json(['schema' => 'ezstudio.profile-ai-history.v1',
            'jobs' => $this->queue->recent((int)$limit)]);
    }

    #[Route('/internal/analysis/desktop/profile/ai-requests',name:'lab_internal_profile_ai_requests',methods:['GET'])]
    public function pending(Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        return $this->json(['schema'=>'ezstudio.profile-ai-requests.v1','requests'=>$this->queue->pending()]);
    }

    /**
     * Contract #11: immutable, strictly whitelisted business view for one AI job.
     * No engine outputs, scores, diagnostics, scientific settings or audio leave LAB.
     */
    #[Route('/internal/analysis/desktop/profile/ai-requests/{id<\d+>}/context', name:'lab_internal_profile_ai_job_context', methods:['GET'])]
    public function jobContext(Request $request, int $id, \EZSLab\Service\ProfileExternalReviewService $reviews): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $job = $this->queue->contextForJob($id);
        if ($job === null) return $this->json(['error'=>'ai_job_not_found'],404);
        if ($job['run_state'] !== 'done') return $this->json(['error'=>'scientific_run_not_ready'],409);
        if (!in_array($job['job_status'], ['queued','running'], true)) {
            return $this->json(['error'=>'ai_job_not_active'],409);
        }
        try {
            $final = $reviews->assertionsForRun($job['run_id']);
            $title = trim((string)($final['title'] ?? ''));
            $artist = trim((string)($final['artist'] ?? ''));
            if ($title === '' || $artist === '') {
                return $this->json(['error'=>'recording_identity_incomplete'],409);
            }
            $assertions=[];
            foreach (($final['final_assertions'] ?? []) as $item) {
                if (!is_array($item)) continue;
                $idValue = $item['id'] ?? null;
                $dimension = $item['dimension'] ?? null;
                $value = $item['value'] ?? null;
                if (!is_string($idValue) || !is_string($dimension)
                    || !is_scalar($value) || is_bool($value)) continue;
                $assertions[]=['id'=>$idValue,'characteristic'=>$dimension,'value'=>$value];
            }
            if ($assertions === []) return $this->json(['error'=>'final_assertions_absent'],409);
            return $this->json([
                'schema'=>'ezstudio.assistant-final-job.v1',
                'job_id'=>$job['job_id'],
                'run_id'=>$job['run_id'],
                'song_id'=>$job['song_id'],
                'status'=>$job['job_status'],
                'recording'=>[
                    'title'=>$title,'artist'=>$artist,
                    'version'=>null,'track_id'=>(string)$job['song_id'],
                ],
                'assertions'=>$assertions,
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error'=>'final_assertions_unavailable'],409);
        }
    }

    #[Route('/internal/analysis/desktop/profile/ai-requests/{id<\\d+>}/checkpoints',name:'lab_internal_assistant_checkpoint',methods:['GET','POST'])]
    public function checkpoints(Request $request,int $id,\EZSLab\Service\AssistantJobCheckpointService $service): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        try {
            if ($request->isMethod('GET')) {
                return $this->json(['schema'=>'ezstudio.assistant-checkpoint-history.v1','job_id'=>$id,'events'=>$service->history($id)]);
            }
            return $this->json(['ok'=>true,'event'=>$service->save($id,$request->toArray())],201);
        } catch (\InvalidArgumentException $e) {return $this->json(['error'=>$e->getMessage()],422);}
        catch (\Throwable $e) {return $this->json(['error'=>$e->getMessage()],409);}
    }

    #[Route('/internal/analysis/desktop/profile/ai-requests/{id<\d+>}/claim',name:'lab_internal_profile_ai_claim',methods:['POST'])]
    public function claim(Request $request,int $id): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        return $this->json(['claimed'=>$this->queue->claim($id)]);
    }

    /** Cooperative cancellation probe, protected by the existing LAB token. */
    #[Route('/internal/analysis/desktop/profile/ai-requests/{id<\d+>}/state',name:'lab_internal_profile_ai_state',methods:['GET'])]
    public function executionState(Request $request, int $id): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $jobId=filter_var($request->query->get('job_id'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($jobId===false || $jobId===null) return $this->json(['error'=>'invalid_job_id'],422);
        try { return $this->json(['request'=>$this->queue->executionState($id,(int)$jobId)]); }
        catch (\RuntimeException $e) { return $this->json(['error'=>$e->getMessage()],409); }
    }

    /** The worker acknowledges only after its own execution has unwound. */
    #[Route('/internal/analysis/desktop/profile/ai-requests/{id<\d+>}/cancelled',name:'lab_internal_profile_ai_cancelled',methods:['POST'])]
    public function executionCancelled(Request $request, int $id): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        try {
            $payload=$request->toArray();
            $jobId=filter_var($payload['job_id']??null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($jobId===false || $jobId===null) return $this->json(['error'=>'invalid_job_id'],422);
            return $this->json(['ok'=>true,'request'=>$this->queue->acknowledgeCancellation($id,(int)$jobId)]);
        } catch (\Throwable $e) { return $this->json(['error'=>$e->getMessage()],409); }
    }

    #[Route('/internal/analysis/desktop/profile/ai-requests/{id<\d+>}/finish',name:'lab_internal_profile_ai_finish',methods:['POST'])]
    public function finish(Request $request,int $id): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        try {
            $p=$request->toArray();
            return $this->json(['ok'=>true,'request'=>$this->queue->finish($id,(string)($p['status']??''),(string)($p['message']??''),isset($p['job_id'])?(int)$p['job_id']:null)]);
        } catch (\Throwable $e) {return $this->json(['error'=>$e->getMessage()],409);}
    }
}
