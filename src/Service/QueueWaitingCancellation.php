<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\JobQueue;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A cancelled global row is a durable receipt until LAB acknowledges it. */
final class QueueWaitingCancellation
{
    public const PENDING = 'cancel_pending_lab_ack';
    public function __construct(private readonly QueueManager $manager, private readonly HttpClientInterface $http) {}

    public function reconcile(): bool
    {
        foreach ($this->manager->pendingWaitingCancellations() as $id) {
            try {
                $job = $this->manager->findForStoppedCancellation($id);
                if (!$job instanceof JobQueue || $job->getStatus() !== 'cancelled' || $job->getAttempts() !== 0 || $job->getWorkerPid() !== null || $job->getErrorMessage() !== self::PENDING) return false;
                $identity = QueueCancellationRecovery::identity($job);
                if ($identity === null) return false;
                $root = dirname(__DIR__, 2);
                $token = trim((string)($_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN'] ?? $_SERVER['EZSTUDIO_ANALYSIS_WORKER_TOKEN'] ?? getenv('EZSTUDIO_ANALYSIS_WORKER_TOKEN') ?: ''));
                if ($token === '') $token = trim((string)($_ENV['EZSTUDIOLAB_ASSISTANT_TOKEN'] ?? $_SERVER['EZSTUDIOLAB_ASSISTANT_TOKEN'] ?? getenv('EZSTUDIOLAB_ASSISTANT_TOKEN') ?: ''));
                if ($token === '') foreach (@file($root.'/apps/ezs_lab/.env.local', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (preg_match('/^\s*EZSTUDIO_ANALYSIS_WORKER_TOKEN\s*=(.*)$/D',$line,$m)) { $token=trim(trim($m[1]),"\"'"); break; }
                }
                if ($token === '') return false;
                $base = (string)($_ENV['EZSTUDIOLAB_ASSISTANT_BASE_URL'] ?? $_SERVER['EZSTUDIOLAB_ASSISTANT_BASE_URL'] ?? getenv('EZSTUDIOLAB_ASSISTANT_BASE_URL') ?: 'http://lab.ezstudio.local:8000');
                $response = $this->http->request('POST',rtrim($base,'/').'/internal/analysis/desktop/jobs/recovery/cancel',[
                    'headers'=>['X-EZScore-Analysis-Token'=>$token], 'json'=>$identity+['global_job_id'=>$id,'attempt'=>0], 'timeout'=>8.0,'max_duration'=>12.0]);
                if ($response->getStatusCode() !== 200 || !QueueCancellationRecovery::validAck($response->toArray(false),$id,0,$identity)) return false;
                if (!$this->manager->ackWaitingCancellation($id)) return false;
                EzsFifoTrace::log('EZS_WAITING_CANCEL_ACK',['global_job_id'=>$id]);
            } catch (\Throwable $e) { EzsFifoTrace::log('EZS_WAITING_CANCEL_BLOCKED',['global_job_id'=>$id,'exception'=>get_class($e)]); return false; }
        }
        return true;
    }
}
