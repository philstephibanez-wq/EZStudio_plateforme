<?php

declare(strict_types=1);

namespace EZSLab\Service;

use App\Service\OrchestratorQueueClient;

/** LAB owns its state. This endpoint never retries a job or modifies an expertise's analysis. */
final class LabCancellationRecovery
{
    private const KINDS = [
        'lab:profile'=>['profile'], 'lab:stems'=>['stems'],
        'lab:chords'=>['chords_scientific'], 'lab:lyrics'=>['lyrics','lyrics_scientific'],
    ];

    public function __construct(
        private readonly Database $database,
        private readonly OrchestratorQueueClient $orchestratorQueue,
    ) {}

    public static function validate(array $r, array $proof): array
    {
        $waiting = ($r['attempt'] ?? null) === 0;
        foreach (['global_job_id','local_job_id','run_id'] as $field) {
            if (!is_int($r[$field] ?? null) || $r[$field] < 1) throw new \DomainException('recovery_identity_invalid');
        }
        $source = $r['source'] ?? null;
        $kind = $r['kind'] ?? null;
        if (!is_string($source) || !is_string($kind) || !isset(self::KINDS[$source.':'.$kind])) {
            throw new \DomainException('recovery_kind_unsupported');
        }
        $localKey = $source === 'lab' ? 'analysis_job_id' : 'job_id';
        $runKey = $source === 'lab' ? 'scientific_run_id' : 'run_id';
        if (($proof['queue_id'] ?? null) !== $r['global_job_id']
            || ($proof['attempts'] ?? null) !== $r['attempt']
            || ($waiting ? (($proof['status'] ?? null) !== 'cancelled' || ($proof['waiting_cancel_authorized'] ?? null) !== true) : (!is_int($r['attempt'] ?? null) || $r['attempt'] < 1 || ($proof['status'] ?? null) !== 'cancelling' || ($proof['recovery_authorized'] ?? null) !== true))
            || ($proof['source'] ?? null) !== $source
            || ($proof['job'] ?? null) !== $kind
            || ($proof[$localKey] ?? null) !== $r['local_job_id']
            || ($proof[$runKey] ?? null) !== $r['run_id']) {
            throw new \DomainException('recovery_global_proof_mismatch');
        }
        $song = $r['song_id'] ?? 0;
        if (!is_int($song) || $song < 0 || ($source === 'lab' && $song < 1)
            || ($proof['song_id'] ?? null) !== $song) {
            throw new \DomainException('recovery_song_mismatch');
        }
        return self::KINDS[$source.':'.$kind];
    }

    public function recover(array $r): array
    {
        if (!is_int($r['global_job_id'] ?? null) || $r['global_job_id'] < 1) throw new \DomainException('recovery_identity_invalid');
        $proof = $this->orchestratorQueue->executionProof($r['global_job_id']);
        $kinds = self::validate($r, $proof);
        $pdo = $this->database->pdo();
        // Use the owning LAB database transaction; no access to another application's DB.
        $pdo->beginTransaction();
        try {
            $job = $this->database->analysisJob($r['local_job_id']);
            if (!$job || ($job['dispatch_owner'] ?? null) !== 'lab'
                || !in_array((string)$job['kind'], $kinds, true)
                || (int)$job['scientific_run_id'] !== $r['run_id']
                || ($r['song_id'] > 0 && (int)$job['song_id'] !== $r['song_id'])) {
                throw new \DomainException('recovery_local_identity_mismatch');
            }
            $request = json_decode((string)($job['request_json'] ?? ''), true);
            if (!is_array($request)) throw new \DomainException('recovery_local_request_invalid');
            $receiptId = (int)($request['orchestrator_queue_id'] ?? 0);
            // PROFILE and expertise have a durable admission receipt. Existing STEMS
            // and CHORDS lack this field; their exact local/run/song/kind proof is used.
            if (($receiptId > 0 && $receiptId !== $r['global_job_id'])
                || (($r['kind'] === 'profile') && $receiptId !== $r['global_job_id'])) {
                throw new \DomainException('recovery_local_receipt_mismatch');
            }
            $status = (string)$job['status'];
            if (!in_array($status, $r['attempt'] === 0 ? ['queued','cancelled'] : ['queued','running','cancelling','cancelled'], true)) {
                throw new \DomainException('recovery_local_terminal_conflict');
            }
            if ($r['source'] === 'lab') {
                $st = $pdo->prepare('SELECT state FROM scientific_runs WHERE id=? AND song_id=?');
                $st->execute([$r['run_id'], (int)$job['song_id']]);
                $state = $st->fetchColumn();
                if (!is_string($state) || !in_array($state, ['created','queued','running','cancelled'], true)) {
                    throw new \DomainException('recovery_analysis_state_conflict');
                }
                if ($job['run_id'] !== null) {
                    $st = $pdo->prepare('SELECT status FROM benchmark_runs WHERE id=?');
                    $st->execute([(int)$job['run_id']]);
                    $state = $st->fetchColumn();
                    if (!is_string($state) || !in_array($state, ['created','queued','running','cancelled','error'], true)) {
                        throw new \DomainException('recovery_benchmark_state_conflict');
                    }
                }
                if ($status !== 'cancelled') {
                    $st = $pdo->prepare("UPDATE analysis_jobs SET status='cancelling', error='cancel_requested_by_user', updated_at=? WHERE id=? AND status=? AND dispatch_owner='lab'");
                    $st->execute([gmdate('c'), $r['local_job_id'], $status]);
                    if ($st->rowCount() !== 1) throw new \RuntimeException('recovery_local_cas_failed');
                    $this->database->markAnalysisJobCancelled($r['local_job_id']);
                }
                $st = $pdo->prepare('SELECT state FROM scientific_runs WHERE id=?');
                $st->execute([$r['run_id']]);
                if ($st->fetchColumn() !== 'cancelled') throw new \DomainException('recovery_analysis_cancel_unverified');
            } elseif ($status !== 'cancelled') {
                // The analysis to which the expertise refers is deliberately unchanged.
                $st = $pdo->prepare("UPDATE analysis_jobs SET status='cancelled',progress=0,error='cancelled_by_user',updated_at=? WHERE id=? AND status=? AND dispatch_owner='lab'");
                $st->execute([gmdate('c'), $r['local_job_id'], $status]);
                if ($st->rowCount() !== 1) throw new \RuntimeException('recovery_local_cas_failed');
            }
            $check = $this->database->analysisJob($r['local_job_id']);
            if (!$check || $check['status'] !== 'cancelled') throw new \RuntimeException('recovery_local_cancel_unverified');
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack(); // local transaction, not deployment rollback
            throw $e;
        }
        return ['ok'=>true,'global_job_id'=>$r['global_job_id'],'attempt'=>$r['attempt'],
            'local_job_id'=>$r['local_job_id'],'run_id'=>$r['run_id'],'status'=>'cancelled'];
    }
}
