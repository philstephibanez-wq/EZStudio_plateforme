<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\JobQueue;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/** Saga: LAB prepares its state via REST; Orchestrator commits its own queue. */
final class JobRetryCoordinator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueManager $queueManager,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly LabRetryClient $lab,
    ) {}

    public function retry(int $queueJobId): array
    {
        EzsFifoTrace::log('RETRY_REQUEST',['global_job_id'=>$queueJobId]);
        $lock=$this->lockFactory->createLock('job_retry_'.$queueJobId,30.0);
        if (!$lock->acquire()) return ['ok'=>false,'reason'=>'verrou_relance_actif','job'=>null];
        try {
            $this->entityManager->clear();
            $job=$this->entityManager->find(JobQueue::class,$queueJobId);
            if (!$job instanceof JobQueue) return ['ok'=>false,'reason'=>'tache_introuvable','job'=>null];
            // EZS_FIFO_ACTIVE_RETRY_R1B: retry is reserved to the ONE job
            // currently blocking the queue; historical failures cannot re-enter.
            $pauseFile = \App\Service\QueueBarrierPaths::pause(dirname(__DIR__, 2));
            $pauseRaw = is_file($pauseFile) ? @file_get_contents($pauseFile) : false;
            $pause = is_string($pauseRaw) ? json_decode($pauseRaw, true) : null;
            if (!is_array($pause) || ($pause['schema'] ?? null) !== 'ezs.queue.pause_on_ko.v1'
                || ($pause['global_job_id'] ?? null) !== $queueJobId) {
                return ['ok'=>false,'reason'=>'job_non_bloquant_de_la_fifo','job'=>$job];
            }
            if ($job->getStatus()!==JobQueue::STATUS_ENDED || !$job->hasError()) return ['ok'=>false,'reason'=>'tache_non_en_erreur','job'=>$job];
            if ($job->getWorkerPid()!==null && $this->queueManager->isProcessAlive($job->getWorkerPid())) return ['ok'=>false,'reason'=>'processus_encore_actif','job'=>$job];
            $source=$job->getSource();$payload=$job->getPayload();
            $duplicate=$this->queueManager->findActiveDuplicate($source,$payload);
            if ($duplicate instanceof JobQueue && $duplicate->getId()!==$job->getId()) return ['ok'=>false,'reason'=>'tache_identique_deja_active','job'=>$job];
            $type=trim((string)($payload['job']??$payload['phase']??$payload['type']??''));
            if ($source !== 'lab') return ['ok'=>false,'reason'=>'actor_removed','job'=>$job];
            $attempt=(int)$job->getAttempts();
            EzsFifoTrace::log('RETRY_STATE',['global_job_id'=>$queueJobId,'attempt'=>$attempt,'source'=>$source,'state'=>(string)$job->getStatus()]);
            {
                EzsFifoTrace::log('RETRY_PREPARE_START',['global_job_id'=>$queueJobId,'attempt'=>$attempt,'source'=>$source]);
                $prepared=$this->lab->request('prepare',$queueJobId,$attempt,$source,$payload);
                EzsFifoTrace::log('RETRY_PREPARE_RESULT',['global_job_id'=>$queueJobId,'attempt'=>$attempt,'result'=>($prepared['ok']??false)?'ok':'rejected','reason'=>(string)($prepared['reason']??'none')]);
            }
            if (!($prepared['ok']??false)) return ['ok'=>false,'reason'=>(string)($prepared['reason']??'retry_prepare_rejected'),'job'=>$job];
            // EZS_PROFILE_FIFO_RETRY_R1: LAB verified identity without mutating state.
            // No LAB receipt exists for this kind; the global retry is the sole write.
            if ($type === 'profile_chatgpt') {
                if (($prepared['reason'] ?? null) !== 'expertise_retry_readonly_verified') {
                    return ['ok'=>false,'reason'=>'expertise_retry_contract_invalid','job'=>$job];
                }
                try {
                    $retried=$this->queueManager->retry($queueJobId, true);
                    if (!$retried instanceof JobQueue) throw new \RuntimeException('global_queue_retry_rejected');
                    EzsFifoTrace::log('RETRY_QUEUE_COMMIT_OK',['global_job_id'=>$queueJobId,'attempt'=>$attempt]);
                    return ['ok'=>true,'reason'=>'relance_acceptee','job'=>$retried];
                } catch (\Throwable $e) {
                    return ['ok'=>false,'reason'=>'echec_rearmement_central','job'=>$job];
                }
            }
            $reconciled=(bool)($prepared['reconciled']??false);
            try {
                EzsFifoTrace::log('RETRY_QUEUE_COMMIT_START',['global_job_id'=>$queueJobId,'attempt'=>$attempt]);
                $retried=$this->queueManager->retry($queueJobId, true);
                if (!$retried instanceof JobQueue) throw new \RuntimeException('global_queue_retry_rejected');
                EzsFifoTrace::log('RETRY_QUEUE_COMMIT_OK',['global_job_id'=>$queueJobId,'attempt'=>$attempt]);
            } catch (\Throwable $e) {
                {
                    $compensated=(bool)($this->lab->request('abort',$queueJobId,$attempt,$source,$payload)['ok']??false);
                }
                if (!$compensated) {
                    $this->logger->critical('Retry compensation must be reconciled.', ['global_job_id'=>$queueJobId]);
                    return ['ok'=>false,'reason'=>'echec_rearmement_central_compensation_non_confirmee','job'=>$job];
                }
                return ['ok'=>false,'reason'=>'echec_rearmement_central','job'=>$job];
            }
            {
                EzsFifoTrace::log('RETRY_LAB_COMMIT_START',['global_job_id'=>$queueJobId,'attempt'=>$attempt]);
                $confirmed=$this->lab->request('commit',$queueJobId,$attempt,$source,$payload);
                EzsFifoTrace::log('RETRY_LAB_COMMIT_RESULT',['global_job_id'=>$queueJobId,'attempt'=>$attempt,'result'=>($confirmed['ok']??false)?'ok':'rejected','reason'=>(string)($confirmed['reason']??'none')]);
                if (!($confirmed['ok']??false)) {
                    // The global queue has already been committed: never undo LAB here.
                    $this->logger->error('Retry LAB receipt confirmation unavailable.',['global_job_id'=>$queueJobId]);
                    return ['ok'=>true,'reason'=>'relance_acceptee_confirmation_lab_en_attente','job'=>$retried];
                }
            }
            return ['ok'=>true,'reason'=>$reconciled?'relance_reconciliee_idempotente':'relance_acceptee','job'=>$retried];
        } finally {
            $lock->release();
        }
    }

    /** Read-only state for automatic reconciliation: a REST call, never LAB SQLite. */
    public function labState(int $globalJobId,int $attempt,string $source,array $payload): ?bool
    {
        $response=$this->lab->request('state',$globalJobId,$attempt,$source,$payload);
        if (($response['ok']??false)!==true && in_array(($response['reason']??''),['lab_retry_transport_unavailable','lab_retry_token_not_configured'],true)) return null;
        return ($response['ok']??false)===true && ($response['queued']??false)===true;
    }
}
