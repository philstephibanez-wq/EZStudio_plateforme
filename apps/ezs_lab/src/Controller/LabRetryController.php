<?php

declare(strict_types=1);

namespace EZSLab\Controller;

use EZSLab\Service\LabAnalysisTokenGuard;
use EZSLab\Service\LabRetryService;
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
        $this->guard->assertAuthorized($request);
        try {
            $body=$request->toArray();
            $result=match($action) {
                'prepare'=>$this->retry->prepare($body),
                'commit'=>$this->retry->finish($body,false),
                'abort'=>$this->retry->finish($body,true),
                'state'=>$this->retry->state($body),
            };
            return new JsonResponse(['schema'=>'ezstudio.lab-job-retry.v1']+$result,200);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['ok'=>false,'reason'=>$e->getMessage()],422);
        } catch (\DomainException $e) {
            return new JsonResponse(['ok'=>false,'reason'=>$e->getMessage()],409);
        } catch (\Throwable $e) {
            // Never return paths, SQL, or tokens in an error response.
            return new JsonResponse(['ok'=>false,'reason'=>'lab_retry_internal_error'],503);
        }
    }
}
