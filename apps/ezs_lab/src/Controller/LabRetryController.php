<?php

declare(strict_types=1);

namespace EZSLab\Controller;

use EZSLab\Service\LabAnalysisTokenGuard;
use EZSLab\Service\LabRetryService;
use App\Service\EzsFifoTrace;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Private service-to-service contract; no UI routes. */
final class LabRetryController
{
    public function __construct(private readonly LabAnalysisTokenGuard $guard,private readonly LabRetryService $retry) {}

    #[Route('/internal/analysis/desktop/jobs/retry/{action}',name:'lab_internal_job_retry_protocol',requirements:['action'=>'prepare|commit|abort|state'],methods:['POST'])]
    public function handle(Request $request,string $action): JsonResponse
    {
        EzsFifoTrace::log('LAB_RETRY_RECEIVED',['action'=>$action]);
        $this->guard->assertAuthorized($request);
        try {
            $body=$request->toArray();
            EzsFifoTrace::log('LAB_RETRY_IDENTITY',['action'=>$action,'global_job_id'=>(int)($body['global_job_id']??0),'attempt'=>(int)($body['attempt']??0),'source'=>(string)($body['source']??'unknown')]);
            $result=match($action) {
                'prepare'=>$this->retry->prepare($body),
                'commit'=>$this->retry->finish($body,false),
                'abort'=>$this->retry->finish($body,true),
                'state'=>$this->retry->state($body),
            };
            EzsFifoTrace::log('LAB_RETRY_RESULT',['action'=>$action,'global_job_id'=>(int)($body['global_job_id']??0),'result'=>($result['ok']??false)?'ok':'rejected','reason'=>(string)($result['reason']??'none')]);
            return new JsonResponse(['schema'=>'ezstudio.lab-job-retry.v1']+$result,200);
        } catch (\InvalidArgumentException $e) {
            EzsFifoTrace::log('LAB_RETRY_REJECT',['action'=>$action,'http_status'=>422,'reason'=>$e->getMessage()]);
            return new JsonResponse(['schema'=>'ezstudio.lab-job-retry.v1','ok'=>false,'reason'=>$e->getMessage()],422);
        } catch (\DomainException $e) {
            EzsFifoTrace::log('LAB_RETRY_REJECT',['action'=>$action,'http_status'=>409,'reason'=>$e->getMessage()]);
            return new JsonResponse(['schema'=>'ezstudio.lab-job-retry.v1','ok'=>false,'reason'=>$e->getMessage()],409);
        } catch (\Throwable $e) {
            EzsFifoTrace::log('LAB_RETRY_REJECT',['action'=>$action,'http_status'=>503,'exception'=>get_class($e)]);
            // Never return paths, SQL, or tokens in an error response.
            return new JsonResponse(['schema'=>'ezstudio.lab-job-retry.v1','ok'=>false,'reason'=>'lab_retry_internal_error'],503);
        }
    }
}
