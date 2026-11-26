<?php

declare(strict_types=1);

namespace EZSLab\Controller;

use EZSLab\Service\LabAnalysisTokenGuard;
use EZSLab\Service\LabCancellationRecovery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class LabCancellationRecoveryController
{
    public function __construct(private readonly LabAnalysisTokenGuard $guard, private readonly LabCancellationRecovery $recovery) {}

    #[Route('/internal/analysis/desktop/jobs/recovery/cancel', name: 'lab_internal_cancel_recovery', methods: ['POST'])]
    public function recover(Request $request): JsonResponse
    {
        $this->guard->assertAuthorized($request);
        $schema = ['schema'=>'ezs.lab.cancellation-recovery.v1'];
        try {
            return new JsonResponse($schema + $this->recovery->recover($request->toArray()));
        } catch (\DomainException) {
            return new JsonResponse($schema + ['ok'=>false,'reason'=>'recovery_identity_or_state_conflict'], 409);
        } catch (\Throwable) {
            return new JsonResponse($schema + ['ok'=>false,'reason'=>'recovery_unavailable'], 503);
        }
    }
}
