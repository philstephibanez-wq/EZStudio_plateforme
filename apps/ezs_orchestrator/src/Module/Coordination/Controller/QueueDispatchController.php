<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Controller;

use App\Entity\JobQueue;
use App\Service\QueueManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class QueueDispatchController extends AbstractController
{
    private function denyIfUnauthorized(Request $request): ?JsonResponse
    {
        $expected = trim((string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: ''));
        if ($expected === '') { return null; }
        $provided = trim((string) $request->headers->get('X-EZS-Queue-Token', ''));
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return $this->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        return null;
    }

    #[Route('/api/queue/dispatch', name: 'orchestrator_queue_dispatch', methods: ['POST'])]
    public function dispatch(Request $request, QueueManager $queueManager): JsonResponse
    {
        \App\Service\EzsFifoTrace::log('E2E_DISPATCH_HTTP_RECEIVED_R2',['source'=>'orchestrator']);
        if ($denied = $this->denyIfUnauthorized($request)) { \App\Service\EzsFifoTrace::log('E2E_DISPATCH_REJECT_R2',['source'=>'orchestrator','reason'=>'unauthorized']); return $denied; }
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) { \App\Service\EzsFifoTrace::log('E2E_DISPATCH_REJECT_R2',['source'=>'orchestrator','reason'=>'invalid_json']); return $this->json(['ok' => false, 'error' => 'invalid_json'], 400); }
        $source = trim((string) ($data['source'] ?? ''));
        $payload = $data['payload'] ?? null;
        if (!in_array($source, ['lab', 'assistant'], true) || !is_array($payload)) {
            \App\Service\EzsFifoTrace::log('E2E_DISPATCH_REJECT_R2',['source'=>'orchestrator','reason'=>'invalid_dispatch_envelope']);
            return $this->json(['ok' => false, 'error' => 'invalid_dispatch_envelope'], 422);
        }
        \App\Service\EzsFifoTrace::log('E2E_DISPATCH_VALIDATED_R2',['source'=>$source,'local_job_id'=>(int)($payload['job_id']??$payload['analysis_job_id']??0),'run_id'=>(int)($payload['run_id']??0)]);
        try {
            if (\App\Service\ProfileExpertiseAdmission::blocksKind($payload['job'] ?? $payload['type'] ?? $payload['phase'] ?? null)) {
                // A successful suspended dispatch is necessarily an exact existing PROFILE receipt.
                $job = $queueManager->dispatch($source, $payload);
                $duplicate = $job;
            } else {
                $duplicate = $queueManager->findActiveDuplicate($source, $payload);
                $job = $duplicate ?? $queueManager->dispatch($source, $payload);
            }
        } catch (\Throwable $e) {
            if ($e instanceof \DomainException && $e->getMessage() === \App\Service\ProfileExpertiseAdmission::ERROR) {
                \App\Service\EzsFifoTrace::log('E2E_DISPATCH_REJECT_R2',['source'=>$source,'reason'=>$e->getMessage()]);
                return $this->json(['ok'=>false,'error'=>$e->getMessage()],409);
            }
            \App\Service\EzsFifoTrace::log('E2E_DISPATCH_EXCEPTION_R2',['source'=>$source,'exception'=>get_class($e)]);
            throw $e;
        }
        \App\Service\EzsFifoTrace::log('E2E_DISPATCH_ACK_R2',['source'=>$source,'global_job_id'=>(int)$job->getId(),'local_job_id'=>(int)($payload['job_id']??$payload['analysis_job_id']??0),'state'=>(string)$job->getStatus(),'created'=>$duplicate===null]);
        if ($duplicate === null) { $this->wakeConsumer(); }
        return $this->json([
            'ok' => true,
            'queue_id' => $job->getId(),
            'source' => $job->getSource(),
            'status' => $job->getStatus(),
            'progress' => $job->getProgress(),
            'deduplicated' => $duplicate !== null,
            'created_at' => $job->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $duplicate !== null ? 200 : 201);
    }

    // Only the owning application may assert that a global job may run.
    #[Route('/api/queue/{id}/execution-proof', name: 'orchestrator_queue_execution_proof', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function executionProof(int $id, Request $request, \Doctrine\ORM\EntityManagerInterface $entityManager): JsonResponse
    {
        if ($denied = $this->denyIfUnauthorized($request)) { return $denied; }
        $entityManager->clear();
        $job = $entityManager->find(JobQueue::class, $id);
        if (!$job instanceof JobQueue) {
            \App\Service\EzsFifoTrace::log('E2E_GO_PROOF_NOT_FOUND',['global_job_id'=>$id]);
            return $this->json(['ok'=>false,'error'=>'global_job_not_found'],404);
        }
        $payload = $job->getPayload();
        if (!is_array($payload)) { $payload = []; }
        $proof = [
            'queue_id'=>$job->getId(), 'source'=>$job->getSource(),
            'status'=>$job->getStatus(), 'job'=>(string)($payload['job']??''),
            'analysis_job_id'=>(int)($payload['analysis_job_id']??0),
            'job_id'=>(int)($payload['job_id']??0),
            'run_id'=>(int)($payload['run_id']??0),
            'attempts'=>$job->getAttempts(),
            'waiting_cancel_authorized'=>$job->getStatus() === JobQueue::STATUS_CANCELLED && $job->getAttempts() === 0 && $job->getWorkerPid() === null && $job->getErrorMessage() === \App\Service\QueueWaitingCancellation::PENDING,
            'recovery_authorized'=>$job->getStatus() === JobQueue::STATUS_CANCELLING
                && \App\Service\QueueCancellationRecovery::authorizedForJob(dirname(__DIR__, 6), $job),
            'scientific_run_id'=>(int)($payload['scientific_run_id']??0),
            'song_id'=>(int)($payload['song_id']??0),
        ];
        \App\Service\EzsFifoTrace::log('E2E_GO_PROOF',['global_job_id'=>$id,'source'=>$proof['source'],'status'=>$proof['status'],'local_job_id'=>$proof['analysis_job_id']]);
        return $this->json(['ok'=>true,'proof'=>$proof]);
    }

    #[Route('/api/queue/claim', name: 'orchestrator_queue_claim', methods: ['POST'])]
    public function claim(Request $request, QueueManager $queueManager): JsonResponse
    {
        if ($denied = $this->denyIfUnauthorized($request)) { return $denied; }
        $job = $queueManager->claimNextWaiting();
        if ($job === null) { return $this->json(['ok' => true, 'job' => null]); }
        return $this->json([
            'ok' => true,
            'job' => [
                'id' => $job->getId(),
                'source' => $job->getSource(),
                'status' => $job->getStatus(),
                'progress' => $job->getProgress(),
                'attempts' => $job->getAttempts(),
                'worker_pid' => $job->getWorkerPid(),
                'payload' => $job->getPayload(),
                'created_at' => $job->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }
    #[Route('/api/queue/{id}/attach-pid', name: 'orchestrator_queue_attach_pid', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function attachPid(int $id, Request $request, QueueManager $queueManager): JsonResponse
    {
        if ($denied = $this->denyIfUnauthorized($request)) { return $denied; }
        $data = json_decode($request->getContent(), true);
        $pid = is_array($data) && isset($data['pid']) && is_numeric($data['pid']) ? (int) $data['pid'] : 0;
        if ($pid <= 0) { return $this->json(['ok' => false, 'error' => 'invalid_worker_pid'], 422); }
        $job = $queueManager->attachWorkerPid($id, $pid);
        if ($job === null) { return $this->json(['ok' => false, 'error' => 'job_not_found'], 404); }
        return $this->json(['ok' => true, 'queue_id' => $job->getId(), 'status' => $job->getStatus(), 'worker_pid' => $job->getWorkerPid()]);
    }

    #[Route('/api/queue/{id}/progress', name: 'orchestrator_queue_progress', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function progress(int $id, Request $request, QueueManager $queueManager): JsonResponse
    {
        if ($denied = $this->denyIfUnauthorized($request)) { return $denied; }
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['progress']) || !is_numeric($data['progress'])) { return $this->json(['ok' => false, 'error' => 'invalid_progress_payload'], 422); }
        $job = $queueManager->updateProgress($id, (int) $data['progress']);
        if ($job === null) { return $this->json(['ok' => false, 'error' => 'job_not_found'], 404); }
        return $this->json(['ok' => true, 'queue_id' => $job->getId(), 'status' => $job->getStatus(), 'progress' => $job->getProgress(), 'cancelling' => $job->getStatus() === JobQueue::STATUS_CANCELLING]);
    }

    #[Route('/api/queue/{id}/complete', name: 'orchestrator_queue_complete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function complete(int $id, Request $request, QueueManager $queueManager): JsonResponse
    {
        if ($denied = $this->denyIfUnauthorized($request)) { return $denied; }
        $job = $queueManager->markEnded($id, false, null);
        if ($job === null) { return $this->json(['ok' => false, 'error' => 'job_not_found'], 404); }
        return $this->json(['ok' => true, 'queue_id' => $job->getId(), 'status' => $job->getStatus(), 'progress' => $job->getProgress(), 'has_error' => $job->hasError()]);
    }

    #[Route('/api/queue/{id}/fail', name: 'orchestrator_queue_fail', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function fail(int $id, Request $request, QueueManager $queueManager): JsonResponse
    {
        if ($denied = $this->denyIfUnauthorized($request)) { return $denied; }
        $data = json_decode($request->getContent(), true);
        $errorMessage = is_array($data) && isset($data['error']) ? trim((string) $data['error']) : null;
        if ($errorMessage === '') { $errorMessage = null; }
        $job = $queueManager->markEnded($id, true, $errorMessage);
        if ($job === null) { return $this->json(['ok' => false, 'error' => 'job_not_found'], 404); }
        return $this->json(['ok' => true, 'queue_id' => $job->getId(), 'status' => $job->getStatus(), 'progress' => $job->getProgress(), 'has_error' => $job->hasError(), 'error_message' => $job->getErrorMessage()]);
    }

    #[Route('/api/queue/{id}/cancel', name: 'orchestrator_queue_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id, Request $request, QueueManager $queueManager): JsonResponse
    {
        if ($denied = $this->denyIfUnauthorized($request)) { return $denied; }
        $job = $queueManager->requestCancel($id);
        if ($job === null) { return $this->json(['ok' => false, 'error' => 'job_not_found'], 404); }
        return $this->json(['ok' => true, 'queue_id' => $job->getId(), 'status' => $job->getStatus(), 'progress' => $job->getProgress(), 'has_error' => $job->hasError(), 'error_message' => $job->getErrorMessage()]);
    }

    private function wakeConsumer(): void
    {
        if ($this->getParameter('kernel.environment') === 'test' || ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? '') === 'test') { return; }
        $platformRoot = str_replace('/', '\\', dirname(__DIR__, 5));
        $php = is_file('H:\\PHP\\php-8.5-x64\\php.exe') ? 'H:\\PHP\\php-8.5-x64\\php.exe' : 'php';
        $cmd = sprintf('cmd /c "cd /d %s && set APP_ID=orchestrator && start "" /B "%s" bin\\console orchestrator:queue:consume --drain"', $platformRoot, $php);
        $handle = @popen($cmd, 'r');
        if (is_resource($handle)) { @pclose($handle); }
    }
}