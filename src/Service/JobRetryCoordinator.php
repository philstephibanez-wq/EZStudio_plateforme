<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\JobQueue;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire('%kernel.project_dir%/apps/ezs_assistant/runtime/state/profile-direct')]
        private readonly string $directResearchStateDir = '',
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
            if ($job->getStatus()!==JobQueue::STATUS_ENDED || !$job->hasError()) return ['ok'=>false,'reason'=>'tache_non_en_erreur','job'=>$job];
            if ($job->getWorkerPid()!==null && $this->queueManager->isProcessAlive($job->getWorkerPid())) return ['ok'=>false,'reason'=>'processus_encore_actif','job'=>$job];
            $source=$job->getSource();$payload=$job->getPayload();
            $duplicate=$this->queueManager->findActiveDuplicate($source,$payload);
            if ($duplicate instanceof JobQueue && $duplicate->getId()!==$job->getId()) return ['ok'=>false,'reason'=>'tache_identique_deja_active','job'=>$job];
            $type=trim((string)($payload['job']??$payload['phase']??$payload['type']??''));
            $direct=$source==='assistant' && $type==='profile.direct_research';
            $attempt=(int)$job->getAttempts();
            EzsFifoTrace::log('RETRY_STATE',['global_job_id'=>$queueJobId,'attempt'=>$attempt,'source'=>$source,'state'=>(string)$job->getStatus()]);
            if ($direct) {
                $prepared=$this->rearmDirectResearchState($payload,$this->directResearchStateDir);
            } else {
                EzsFifoTrace::log('RETRY_PREPARE_START',['global_job_id'=>$queueJobId,'attempt'=>$attempt,'source'=>$source]);
                $prepared=$this->lab->request('prepare',$queueJobId,$attempt,$source,$payload);
                EzsFifoTrace::log('RETRY_PREPARE_RESULT',['global_job_id'=>$queueJobId,'attempt'=>$attempt,'result'=>($prepared['ok']??false)?'ok':'rejected','reason'=>(string)($prepared['reason']??'none')]);
            }
            if (!($prepared['ok']??false)) return ['ok'=>false,'reason'=>(string)($prepared['reason']??'retry_prepare_rejected'),'job'=>$job];
            $reconciled=(bool)($prepared['reconciled']??false);
            try {
                EzsFifoTrace::log('RETRY_QUEUE_COMMIT_START',['global_job_id'=>$queueJobId,'attempt'=>$attempt]);
                $retried=$this->queueManager->retry($queueJobId);
                if (!$retried instanceof JobQueue) throw new \RuntimeException('global_queue_retry_rejected');
                EzsFifoTrace::log('RETRY_QUEUE_COMMIT_OK',['global_job_id'=>$queueJobId,'attempt'=>$attempt]);
            } catch (\Throwable $e) {
                if ($direct) {
                    $compensated=$this->compensateDirectResearch($prepared['snapshot']??null);
                } else {
                    $compensated=(bool)($this->lab->request('abort',$queueJobId,$attempt,$source,$payload)['ok']??false);
                }
                if (!$compensated) {
                    $this->logger->critical('Retry compensation must be reconciled.', ['global_job_id'=>$queueJobId]);
                    return ['ok'=>false,'reason'=>'echec_rearmement_central_compensation_non_confirmee','job'=>$job];
                }
                return ['ok'=>false,'reason'=>'echec_rearmement_central','job'=>$job];
            }
            if (!$direct) {
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

    private function rearmDirectResearchState(array $payload, string $stateDir): array { $jid = (string) ($payload["job_id"] ?? ""); if (!preg_match('/^[a-f0-9]{32}$/', $jid) || $stateDir === "") { return ["ok" => false, "reason" => "identifiant_direct_research_invalide", "snapshot" => null, "reconciled" => false]; } $req = $stateDir . "/" . $jid . ".request.json"; $res = $stateDir . "/" . $jid . ".result.json"; if (!is_file($req)) { return ["ok" => false, "reason" => "requete_direct_research_introuvable", "snapshot" => null, "reconciled" => false]; } $prev = is_file($res) ? (string) file_get_contents($res) : null; $cur = $prev !== null ? json_decode($prev, true) : null; $st = is_array($cur) ? (string) ($cur["state"] ?? "") : ""; if (in_array($st, ["running", "completed"], true)) { return ["ok" => false, "reason" => "etat_direct_research_non_readmissible_" . $st, "snapshot" => null, "reconciled" => false]; } if ($st === "queued") { return ["ok" => true, "reason" => "direct_research_deja_rearme", "snapshot" => ["direct_file" => $res, "direct_prev" => $prev], "reconciled" => true]; } $tmp = $res . ".tmp"; file_put_contents($tmp, (string) json_encode(["ok" => true, "state" => "queued", "job_id" => $jid], JSON_UNESCAPED_UNICODE)); @rename($tmp, $res); return ["ok" => true, "reason" => "direct_research_rearme", "snapshot" => ["direct_file" => $res, "direct_prev" => $prev], "reconciled" => false]; }

    private function compensateDirectResearch(?array $snapshot): bool
    {
        if ($snapshot===null || !isset($snapshot['direct_file'])) return false;
        $path=(string)$snapshot['direct_file'];
        if ($snapshot['direct_prev']===null) return !is_file($path) || @unlink($path);
        return file_put_contents($path,(string)$snapshot['direct_prev'])!==false;
    }
}
