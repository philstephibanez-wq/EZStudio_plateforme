<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\JobQueue;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Recovery of explicitly cancelled, independently stopped local jobs.
 * Invoked only by the unique FIFO consumer while holding its existing lock.
 * A receipt is an operator authorization, not a timeout or an automatic retry.
 */
final class QueueCancellationRecovery
{
    private const SCHEMA = 'ezs.queue.stopped-cancellation.v1';
    private ?string $lastDiagnostic = null;

    public function __construct(
        private readonly QueueManager $queueManager,
        private readonly HttpClientInterface $httpClient,
        private readonly QueueRecoveryProcessGuard $processGuard,
    ) {}

    public static function accepts(array $receipt, int $id, int $attempt): bool
    {
        return ($receipt['schema'] ?? null) === self::SCHEMA
            && ($receipt['global_job_id'] ?? null) === $id
            && ($receipt['attempt'] ?? null) === $attempt
            && ($receipt['operator_confirmed_stopped'] ?? null) === true;
    }

    public static function authorizedForJob(string $root, JobQueue $job): bool
    {
        try {
            $raw = @file_get_contents(\App\Service\QueueBarrierPaths::stoppedCancellation($root));
            if (!is_string($raw)) return false;
            $receipt = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $identity = self::identity($job);
            return is_array($receipt) && $identity !== null
                && self::accepts($receipt, (int)$job->getId(), $job->getAttempts())
                && self::matchesIdentity($receipt, $identity);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function matchesIdentity(array $receipt, array $identity): bool
    {
        foreach (['source','kind','local_job_id','run_id'] as $field) {
            if (!array_key_exists($field, $receipt) || $receipt[$field] !== ($identity[$field] ?? null)) return false;
        }
        return true;
    }

    /** @return array{source:string,kind:string,local_job_id:int,run_id:int,song_id:int}|null */
    public static function identity(JobQueue $job): ?array
    {
        $source = $job->getSource();
        $p = $job->getPayload();
        $kind = (string) ($p['job'] ?? '');
        if (($source === 'lab' && !in_array($kind, ['profile','stems','chords','lyrics'], true))
            || !in_array($source, ['lab'], true)) {
            return null;
        }
        $local = (int) ($source === 'lab' ? ($p['analysis_job_id'] ?? 0) : ($p['job_id'] ?? 0));
        $run = (int) ($source === 'lab' ? ($p['scientific_run_id'] ?? 0) : ($p['run_id'] ?? 0));
        $song = (int) ($p['song_id'] ?? 0);
        if ($local < 1 || $run < 1 || ($source === 'lab' && $song < 1)) return null;
        return ['source'=>$source, 'kind'=>$kind, 'local_job_id'=>$local, 'run_id'=>$run, 'song_id'=>$song];
    }

    /** False means the FIFO must not make a new claim until reconciliation succeeds. */
    public function reconcile(string $platformRoot): bool
    {
        $path = \App\Service\QueueBarrierPaths::stoppedCancellation($platformRoot);
        if (!is_file($path)) return true;
        try {
            $raw = file_get_contents($path);
            $r = is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : null;
            if (!is_array($r) || !is_int($r['global_job_id'] ?? null) || !is_int($r['attempt'] ?? null)
                || $r['global_job_id'] < 1 || $r['attempt'] < 1
                || !self::accepts($r, $r['global_job_id'], $r['attempt'])) {
                return $this->blocked('receipt_invalid', 0);
            }
            $id = $r['global_job_id'];
            $job = $this->queueManager->findForStoppedCancellation($id);
            if (!$job instanceof JobQueue || !self::accepts($r, $id, $job->getAttempts())) {
                return $this->blocked('receipt_job_or_attempt_mismatch', $id);
            }
            $identity = self::identity($job);
            if ($identity === null || !self::matchesIdentity($r, $identity)) return $this->blocked('receipt_identity_mismatch', $id);
            if ($job->getStatus() !== JobQueue::STATUS_CANCELLING) {
                // A crash after the global commit but before receipt deletion is harmless.
                if ($job->getStatus() !== JobQueue::STATUS_CANCELLED || ($r['lab_acknowledged'] ?? null) !== true) {
                    return $this->blocked('job_not_cancelling', $id);
                }
                return $this->consumeReceipt($path, $id);
            }
            if (!$this->queueManager->isOnlyActiveForStoppedCancellation($id)) {
                return $this->blocked('active_slot_conflict', $id);
            }
            // Recheck local process absence even after the explicit offline attestation.
            // An unavailable or ambiguous observation never proves an executor stopped.
            $pid = $job->getWorkerPid();
            if (!$this->processGuard->confirms($pid)) {
                return $this->blocked('worker_absence_not_confirmed', $id);
            }
            $token = self::labToken($platformRoot);
            if ($token === '') return $this->blocked('lab_token_unavailable', $id);
            $base = trim((string) ($_ENV['EZSTUDIO_LAB_BASE_URL'] ?? $_SERVER['EZSTUDIO_LAB_BASE_URL'] ?? getenv('EZSTUDIO_LAB_BASE_URL') ?: 'http://lab.ezstudio.local:8000'));
            $response = $this->httpClient->request('POST', rtrim($base,'/').'/internal/analysis/desktop/jobs/recovery/cancel', [
                'headers'=>['X-EZScore-Analysis-Token'=>$token, 'Accept'=>'application/json'],
                'json'=>$identity + ['global_job_id'=>$id,'attempt'=>$job->getAttempts()],
                'timeout'=>8.0, 'max_duration'=>12.0,
            ]);
            $code = $response->getStatusCode();
            $data = $response->toArray(false);
            if ($code !== 200 || !self::validAck($data, $id, $job->getAttempts(), $identity)) {
                return $this->blocked('lab_ack_unverified_http_'.$code, $id);
            }
            // The acknowledgement is durable and idempotent on LAB. Check again before
            // releasing the global slot. Never overwrite an unexpected state/attempt/PID.
            if (!$this->processGuard->confirms($pid)) return $this->blocked('worker_recheck_failed', $id);
            if (($r['lab_acknowledged'] ?? null) !== true) {
                $r['lab_acknowledged'] = true;
                if (!$this->persistAcknowledgement($path, $r)) return $this->blocked('lab_ack_persist_failed', $id);
            }
            $terminal = $this->queueManager->confirmStoppedCancellation($id, $job->getAttempts(), $pid);
            if (!$terminal instanceof JobQueue || $terminal->getStatus() !== JobQueue::STATUS_CANCELLED) {
                return $this->blocked('global_terminal_unverified', $id);
            }
            return $this->consumeReceipt($path, $id);
        } catch (\Throwable $e) {
            return $this->blocked('reconciliation_exception_'.get_class($e), 0);
        }
    }

    public static function validAck(array $data, int $id, int $attempt, array $identity): bool
    {
        return ($data['schema'] ?? null) === 'ezs.lab.cancellation-recovery.v1'
            && ($data['ok'] ?? null) === true
            && ($data['global_job_id'] ?? null) === $id
            && ($data['attempt'] ?? null) === $attempt
            && ($data['local_job_id'] ?? null) === $identity['local_job_id']
            && ($data['run_id'] ?? null) === $identity['run_id']
            && ($data['status'] ?? null) === 'cancelled';
    }

    private function persistAcknowledgement(string $path, array $receipt): bool
    {
        $tmp = $path.'.'.getmypid().'.tmp';
        if (is_file($tmp)) return false;
        $bytes = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $handle = @fopen($tmp, 'xb');
        if (!is_resource($handle)) return false;
        try {
            $ok = fwrite($handle, $bytes) === strlen($bytes) && fflush($handle);
            if ($ok && function_exists('fsync')) $ok = fsync($handle);
        } finally {
            fclose($handle);
        }
        if (!$ok || !@rename($tmp, $path)) { @unlink($tmp); return false; }
        return true;
    }

    private function consumeReceipt(string $path, int $id): bool
    {
        if (!@unlink($path)) return $this->blocked('receipt_remove_failed', $id);
        EzsFifoTrace::log('EZS_STOPPED_CANCEL_RECOVERED', ['global_job_id'=>$id]);
        $this->lastDiagnostic = null;
        return true;
    }

    private function blocked(string $reason, int $id): bool
    {
        $key = $id.':'.$reason;
        if ($this->lastDiagnostic !== $key) {
            EzsFifoTrace::log('EZS_STOPPED_CANCEL_BLOCKED', ['global_job_id'=>$id,'reason'=>$reason]);
            $this->lastDiagnostic = $key;
        }
        return false;
    }

    private static function labToken(string $root): string
    {
        $token = trim((string) ($_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN'] ?? $_SERVER['EZSTUDIO_ANALYSIS_WORKER_TOKEN'] ?? getenv('EZSTUDIO_ANALYSIS_WORKER_TOKEN') ?: ''));
        if ($token !== '') return $token;
        $path = $root.'/apps/ezs_lab/.env.local';
        if (!is_file($path)) return '';
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^\s*EZSTUDIO_ANALYSIS_WORKER_TOKEN\s*=(.*)$/D', $line, $m)) {
                return trim(trim($m[1]), "\"'");
            }
        }
        return '';
    }
}
