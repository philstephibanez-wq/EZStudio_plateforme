<?php

declare(strict_types=1);
namespace EZSLab\Service;

use App\Service\OrchestratorQueueClient;
use App\Service\EzsFifoTrace;

/** Canonical analysis_jobs-backed PROFILE AI requests. No sidecar queue. */
final class ProfileAiRequestService
{
    private const KIND = 'assistant.profile.review';
    public function __construct(
        private readonly Database $db,
        private readonly ?LocalProfileQueue $localQueue = null,
        private readonly ?ProfileReportPublication $reports = null,
        private readonly ?OrchestratorQueueClient $orchestratorQueue = null,
    ) {}

    private function pdo(): \PDO { return $this->db->pdo(); }

    private function latest(int $runId): ?array
    {
        $sql = "SELECT id, scientific_run_id AS run_id, status, progress, error AS message, updated_at, request_json
                FROM analysis_jobs WHERE scientific_run_id=? AND kind=? ORDER BY id DESC LIMIT 1";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$runId,self::KIND]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['run_id'] = (int)$row['run_id'];
        $row['job_id'] = (int)$row['id'];
        // EZS_FIFO_F1_PUBLIC_QUEUE_ID: keep local job_id for worker/cancel,
        // expose the confirmed Orchestrator queue_id to the HTTP controller.
        $admission = json_decode((string)($row['request_json'] ?? ''), true);
        $row['queue_id'] = is_array($admission) ? (int)($admission['orchestrator_queue_id'] ?? 0) : 0;
        unset($row['request_json']);
        $row['attempt'] = 0;
        $row['message'] = $row['message'] ?? '';
        // Lecture seule des jalons réellement enregistrés pour cette tentative.
        // Tolérance explicite envers les anciennes bases sans cette table.
        $row['checkpoints'] = [];
        $exists = $this->pdo()->query(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name='assistant_job_checkpoints' LIMIT 1"
        )->fetchColumn();
        if ($exists !== false) {
            $events = $this->pdo()->prepare(
                'SELECT event_key,phase,outcome,created_at FROM assistant_job_checkpoints WHERE job_id=? ORDER BY id ASC LIMIT 100'
            );
            $events->execute([(int)$row['job_id']]);
            $row['checkpoints'] = array_map(static fn(array $event): array => [
                'event_key'=>(string)$event['event_key'],
                'phase'=>(string)$event['phase'],
                'outcome'=>(string)$event['outcome'],
                'created_at'=>(string)$event['created_at'],
            ], $events->fetchAll(\PDO::FETCH_ASSOC));
        }
        return $row;
    }

    public function get(int $runId): ?array { return $this->latest($runId); }

    /** Exact attempt + global identity fence. No mutation of scientific_runs. */
    public function requestCancellation(int $runId,int $localJobId,int $globalJobId): array
    {
        if ($runId<1 || $localJobId<1 || $globalJobId<1) {
            throw new \RuntimeException('expertise_cancel_identity_invalid');
        }
        if ($this->orchestratorQueue===null) {
            throw new \RuntimeException('expertise_orchestrator_client_missing');
        }
        $stmt=$this->pdo()->prepare("SELECT id,status,request_json FROM analysis_jobs WHERE id=? AND scientific_run_id=? AND kind=? AND dispatch_owner='lab'");
        $stmt->execute([$localJobId,$runId,self::KIND]);
        $row=$stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new \RuntimeException('expertise_attempt_not_found');
        $req=json_decode((string)($row['request_json']??''),true);
        if (!is_array($req) || (int)($req['orchestrator_queue_id']??0)!==$globalJobId) {
            throw new \RuntimeException('expertise_global_identity_mismatch');
        }
        if (!in_array((string)$row['status'],['queued','running','cancelling'],true)) {
            throw new \RuntimeException('expertise_job_not_active');
        }
        // Request the authoritative global transition before touching LAB's local worker fence.
        $remote=$this->orchestratorQueue->cancelVerified($globalJobId);
        $remoteState=(string)($remote['status']??'');
        if (!in_array($remoteState,['cancelled','cancelling'],true)) {
            throw new \RuntimeException('expertise_global_cancellation_not_acknowledged');
        }
        $now=gmdate('c');
        $update=$this->pdo()->prepare("UPDATE analysis_jobs SET
            status=CASE WHEN status='queued' AND ?='cancelled' THEN 'cancelled' ELSE 'cancelling' END,
            error='cancel_requested_by_user',updated_at=?
            WHERE id=? AND scientific_run_id=? AND kind=? AND dispatch_owner='lab'
              AND status IN ('queued','running','cancelling')");
        $update->execute([$remoteState,$now,$localJobId,$runId,self::KIND]);
        if ($update->rowCount()!==1) throw new \RuntimeException('expertise_cancel_local_race_reconcile_required');
        EzsFifoTrace::log('EXPERTISE_CANCEL_ACK',['run_id'=>$runId,'local_job_id'=>$localJobId,'global_job_id'=>$globalJobId,'global_state'=>$remoteState]);
        return $this->latest($runId) ?? throw new \RuntimeException('expertise_cancel_state_unavailable');
    }



    public function enqueue(int $runId): array
    {
        EzsFifoTrace::log('EXPERTISE_ENQUEUE',['run_id'=>$runId]);
        // Fail closed: absence of the queue client must never produce a local-only expertise.
        if ($this->orchestratorQueue === null) {
            EzsFifoTrace::log('EXPERTISE_REJECT',['run_id'=>$runId,'reason'=>'expertise_orchestrator_client_missing']);
            throw new \RuntimeException('expertise_orchestrator_client_missing');
        }
        $pdo = $this->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt=$pdo->prepare("SELECT sr.id,sr.song_id,s.title FROM scientific_runs sr JOIN songs s ON s.id=sr.song_id WHERE sr.id=? AND sr.item='profile' AND sr.state='done'");
            $stmt->execute([$runId]);
            $run=$stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$run) throw new \RuntimeException('run_profile_non_termine');
            $current=$this->latest($runId);
            EzsFifoTrace::log('EXPERTISE_LOCAL_STATE',['run_id'=>$runId,'local_job_id'=>(int)($current['job_id']??0),'global_job_id'=>(int)($current['queue_id']??0),'state'=>(string)($current['status']??'none')]);
            // A user-triggered request is NEW: never recycle an older LAB job.
            // An Orchestrator retry uses a separate endpoint, retaining its global ID.
            EzsFifoTrace::log('EXPERTISE_NEW_REQUEST',['run_id'=>$runId,'previous_local_job_id'=>(int)($current['job_id']??0),'previous_global_job_id'=>(int)($current['queue_id']??0)]);
            $now=gmdate('c');
            // Each explicit NEW expertise request owns a fresh local job identity.
            // Do not reset a completed/failed historical job: Orchestrator dedupes
            // by LAB job ID, and reusing a terminal ID makes admission appear to
            // succeed while returning an already-ended global job.
            // An active pending request is handled above, idempotently.
            $req=json_encode(['phase'=>'profile','scientific_run_id'=>$runId,'processor'=>'qwen','schema'=>'ezs.assistant.profile.job.v1'],JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $pdo->prepare('INSERT INTO analysis_jobs(run_id,scientific_run_id,song_id,kind,status,progress,request_json,error,created_at,updated_at) VALUES(NULL,?,?,?,\'queued\',0,?,NULL,?,?)')
                ->execute([$runId,(int)$run['song_id'],self::KIND,$req,$now,$now]);
            $pdo->commit();
            $created = $this->latest($runId) ?? throw new \RuntimeException('ai_job_insert_failed');
            $songTitle = trim((string) ($run['title'] ?? ''));
            try {
                EzsFifoTrace::log('EXPERTISE_DISPATCH',['run_id'=>$runId,'local_job_id'=>(int)$created['job_id'],'source'=>'assistant']);
                $dispatched = $this->orchestratorQueue->dispatch('assistant', [
                    'job' => 'profile.review',
                    'category' => 'Expertise',
                    'label' => $songTitle !== '' ? $songTitle : ('Run #' . $runId),
                    'job_id' => (int) $created['job_id'],
                    'run_id' => $runId,
                ]);
                $orchQueueId = (int) ($dispatched['queue_id'] ?? 0);
                EzsFifoTrace::log('EXPERTISE_ACK',['run_id'=>$runId,'global_job_id'=>$orchQueueId,'ack_status'=>(string)($dispatched['status']??'unknown'),'local_job_id'=>(int)$created['job_id']]);
                // Admission is authoritative only with a real global job ID
                // and an acknowledged state. The global consumer may finish
                // quickly, so an already-ended response is still a valid receipt.
                $globalStatus = (string)($dispatched['status'] ?? '');
                if ($orchQueueId < 1 || !in_array($globalStatus, ['waiting','progressing','ended'], true)) {
                    throw new \RuntimeException('expertise_orchestrator_admission_invalid');
                }
                $reqPatch = json_encode([
                    'phase'=>'profile', 'scientific_run_id'=>$runId,
                    'processor'=>'qwen', 'schema'=>'ezs.assistant.profile.job.v1',
                    'orchestrator_queue_id'=>$orchQueueId,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $patch = $pdo->prepare('UPDATE analysis_jobs SET request_json=? WHERE id=? AND status=?');
                $patch->execute([$reqPatch, (int)$created['job_id'], 'queued']);
                EzsFifoTrace::log('EXPERTISE_PERSIST',['run_id'=>$runId,'local_job_id'=>(int)$created['job_id'],'global_job_id'=>$orchQueueId,'result'=>$patch->rowCount()===1?'ok':'failed']);
                if ($patch->rowCount() !== 1) {
                    throw new \RuntimeException('expertise_admission_identity_lost');
                }
            } catch (\Throwable $admissionError) {
                EzsFifoTrace::log('EXPERTISE_DISPATCH_ERROR',['run_id'=>$runId,'local_job_id'=>(int)$created['job_id'],'exception'=>get_class($admissionError),'reason'=>$admissionError->getMessage()]);
                // The remote request may have succeeded before the connection failed.
                // Quarantine the local job: no legacy FIFO execution or blind retry.
                $quarantine = $pdo->prepare("UPDATE analysis_jobs SET status='error', progress=0, error='expertise_admission_unverified', updated_at=? WHERE id=? AND status='queued'");
                $quarantine->execute([gmdate('c'), (int)$created['job_id']]);
                throw new \RuntimeException('expertise_admission_unverified_reconciliation_required', 0, $admissionError);
            }
            return $this->latest($runId) ?? throw new \RuntimeException('expertise_global_admission_lost');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Read-only canonical AI job history for Orchestrator; no queue migration. */
    public function recent(int $limit = 80): array
    {
        $limit = max(1, min(100, $limit));
        $sql = "SELECT aj.id AS job_id, aj.scientific_run_id AS run_id,
                       aj.status, aj.progress, aj.error AS message,
                       aj.created_at, aj.updated_at,
                       aj.song_id, s.title AS song_title
                FROM analysis_jobs aj
                LEFT JOIN songs s ON s.id = aj.song_id
                WHERE aj.kind = 'assistant.profile.review'
                ORDER BY aj.updated_at DESC, aj.id DESC LIMIT ?";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(function (array $row): array {
            foreach (['job_id', 'run_id', 'progress', 'song_id'] as $key) {
                if ($row[$key] !== null) $row[$key] = (int)$row[$key];
            }
            $row['checkpoints'] = [];
            try {
                $cp = $this->pdo()->prepare('SELECT event_key, phase, outcome, detail_json, created_at FROM assistant_job_checkpoints WHERE job_id = ? ORDER BY id ASC');
                $cp->execute([(int)$row['job_id']]);
                foreach ($cp->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                    $row['checkpoints'][] = ['event_key'=>(string)$c['event_key'],'phase'=>(string)$c['phase'],'outcome'=>(string)$c['outcome'],'detail'=>json_decode((string)$c['detail_json'],true)?:[],'created_at'=>(string)$c['created_at']];
                }
            } catch (\Throwable $e) {
                // Checkpoint history failures are operational errors, never silent success.
                error_log('EZS_PROFILE_RECENT_CHECKPOINT_READ_FAILED:' . get_class($e));
                throw new \RuntimeException('profile_recent_checkpoint_read_failed', 0, $e);
            }
            return $row;
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }


    /** Read-only request identity; never load scientific metrics into this queue service. */
    public function contextForJob(int $jobId): ?array
    {
        if ($jobId < 1) return null;
        $stmt = $this->pdo()->prepare(<<<'SQL'
SELECT aj.id AS job_id, aj.scientific_run_id AS run_id,
       aj.song_id, aj.status, sr.state AS run_state
FROM analysis_jobs aj
JOIN scientific_runs sr ON sr.id=aj.scientific_run_id
WHERE aj.id=? AND aj.kind='assistant.profile.review'
  AND aj.song_id=sr.song_id AND sr.item='profile'
LIMIT 1
SQL);
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'job_id'=>(int)$row['job_id'],
            'run_id'=>(int)$row['run_id'],
            'song_id'=>(int)$row['song_id'],
            'job_status'=>(string)$row['status'],
            'run_state'=>(string)$row['run_state'],
        ];
    }

    private function ensureMaintenanceTable(): void
    {
        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS profile_ai_maintenance (id INTEGER PRIMARY KEY CHECK(id=1), paused INTEGER NOT NULL DEFAULT 0)");
        $this->pdo()->exec("INSERT OR IGNORE INTO profile_ai_maintenance(id,paused) VALUES(1,0)");
    }

    public function maintenance(string $mode): array
    {
        if (!in_array($mode,['status','pause','resume'],true)) throw new \InvalidArgumentException('invalid_maintenance_mode');
        $pdo=$this->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->ensureMaintenanceTable();
            $active=(int)$pdo->query("SELECT COUNT(*) FROM analysis_jobs WHERE kind='assistant.profile.review' AND status='running'")->fetchColumn();
            if ($mode==='pause') $pdo->exec("UPDATE profile_ai_maintenance SET paused=1 WHERE id=1");
            if ($mode==='resume') {
                if ($active>0) throw new \RuntimeException('profile_ai_jobs_active');
                $pdo->exec("UPDATE profile_ai_maintenance SET paused=0 WHERE id=1");
            }
            $paused=(bool)$pdo->query("SELECT paused FROM profile_ai_maintenance WHERE id=1")->fetchColumn();
            $pdo->commit();
            return ['ok'=>true,'maintenance'=>$paused,'active'=>$active>0,'active_count'=>$active];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Exact global one-shot lookup; does not depend on the stale LAB queue head. */
    public function pendingForGlobal(int $runId, int $localJobId, int $globalJobId): array
    {
        if ($runId < 1 || $localJobId < 1 || $globalJobId < 1) {
            throw new \InvalidArgumentException('invalid_global_expertise_identity');
        }
        if ($this->maintenance('status')['maintenance']) { return []; }
        $stmt = $this->pdo()->prepare("SELECT id AS job_id,scientific_run_id AS run_id,status,request_json FROM analysis_jobs WHERE id=? AND scientific_run_id=? AND kind=? AND dispatch_owner='lab' AND status='queued'");
        $stmt->execute([$localJobId, $runId, self::KIND]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) { return []; }
        $receipt = json_decode((string)($row['request_json'] ?? ''), true);
        if (!is_array($receipt) || (int)($receipt['orchestrator_queue_id'] ?? 0) !== $globalJobId) { return []; }
        EzsFifoTrace::log('EXPERTISE_TARGET_READY',['run_id'=>$runId,'local_job_id'=>$localJobId,'global_job_id'=>$globalJobId]);
        return [['job_id'=>$localJobId, 'run_id'=>$runId, 'status'=>'queued']];
    }

    public function pending(): array
    {
        $this->migrateLegacy();
        if ($this->maintenance('status')['maintenance']) return [];
        // Shared FIFO: never advertise an Assistant job before an earlier PROFILE.
        if ((int)$this->pdo()->query(LocalProfileQueue::activeSql())->fetchColumn() > 0) return [];
        $head=$this->pdo()->query(LocalProfileQueue::headSql())->fetch(\PDO::FETCH_ASSOC);
        if (!$head || (string)$head['kind'] !== self::KIND) return [];
        $stmt=$this->pdo()->prepare("SELECT id AS job_id,scientific_run_id AS run_id,status,updated_at FROM analysis_jobs WHERE id=? AND kind=? AND status='queued'");
        $stmt->execute([(int)$head['id'],self::KIND]);
        $rows=$stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(static function(array $r): array {$r['run_id']=(int)$r['run_id'];$r['job_id']=(int)$r['job_id'];return $r;},$rows);
    }

    /** GO Orchestrator only: exact queued attempt, never a local FIFO head claim. */
    public function claim(int $runId, int $jobId, int $orchestratorJobId): bool
    {
        if ($runId < 1 || $jobId < 1 || $orchestratorJobId < 1) {
            throw new \InvalidArgumentException('invalid_orchestrator_go');
        }
        $pdo = $this->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->ensureMaintenanceTable();
            if ((bool)$pdo->query('SELECT paused FROM profile_ai_maintenance WHERE id=1')->fetchColumn()) {
                $pdo->commit(); return false;
            }
            $stmt = $pdo->prepare("SELECT id,request_json FROM analysis_jobs WHERE id=? AND scientific_run_id=? AND kind=? AND dispatch_owner='lab' AND status='queued' LIMIT 1");
            $stmt->execute([$jobId,$runId,self::KIND]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $request = $row ? json_decode((string)$row['request_json'],true) : null;
            if (!is_array($request) || (int)($request['orchestrator_queue_id'] ?? 0) !== $orchestratorJobId) {
                $pdo->commit(); return false;
            }
            $update=$pdo->prepare("UPDATE analysis_jobs SET status='running',progress=1,error=NULL,updated_at=? WHERE id=? AND scientific_run_id=? AND kind=? AND dispatch_owner='lab' AND status='queued'");
            $update->execute([gmdate('c'),$jobId,$runId,self::KIND]);
            $claimed=$update->rowCount()===1;
            $pdo->commit();
            return $claimed;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Historic Assistant compatibility: one atomic claim -> persisted review -> finish.
     * Uses the very same canonical analysis_jobs FIFO, no parallel queue.
     *
     * @param callable():array $persist Must save a completed, sourced revision
     */
    public function completeLegacyReview(int $runId, callable $persist): array
    {
        $pdo = $this->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->ensureMaintenanceTable();
            if ((bool)$pdo->query('SELECT paused FROM profile_ai_maintenance WHERE id=1')->fetchColumn()) {
                throw new \RuntimeException('legacy_assistant_maintenance_paused');
            }
            $head = $pdo->query(LocalProfileQueue::headSql())->fetch(\PDO::FETCH_ASSOC);
            $active = (int)$pdo->query(LocalProfileQueue::activeSql())->fetchColumn();
            if (!$head || (string)$head['kind'] !== self::KIND
                || (int)$head['scientific_run_id'] !== $runId || $active > 0) {
                throw new \RuntimeException('legacy_assistant_fifo_claim_rejected');
            }
            $claim = $pdo->prepare("UPDATE analysis_jobs
                SET status='running',progress=1,error=NULL,updated_at=?
                WHERE id=? AND scientific_run_id=? AND kind=?
                AND status='queued' AND dispatch_owner='lab'");
            $claim->execute([gmdate('c'), (int)$head['id'], $runId, self::KIND]);
            if ($claim->rowCount() !== 1) throw new \RuntimeException('legacy_assistant_claim_lost');

            $revision = $persist();
            if (!is_array($revision) || ($revision['status'] ?? null) !== 'completed'
                || (int)($revision['run_id'] ?? 0) !== $runId) {
                throw new \RuntimeException('legacy_assistant_review_not_persisted');
            }
            $done = $pdo->prepare("UPDATE analysis_jobs
                SET status='completed',progress=100,error=NULL,updated_at=?
                WHERE id=? AND scientific_run_id=? AND kind=? AND status='running'");
            $done->execute([gmdate('c'), (int)$head['id'], $runId, self::KIND]);
            if ($done->rowCount() !== 1) throw new \RuntimeException('legacy_assistant_finish_lost');
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        ($this->reports ?? new ProfileReportPublication())->publish($runId, 'assistant', (int) $revision['id'], $this->db->path());
        return $revision;
    }

    /** Exact-attempt probe: no accidental adoption of a recycled run ID. */
    public function executionState(int $runId,int $jobId): array
    {
        $stmt=$this->pdo()->prepare("SELECT id,scientific_run_id,status,kind,dispatch_owner FROM analysis_jobs WHERE id=? AND scientific_run_id=? AND kind=? AND dispatch_owner='lab' LIMIT 1");
        $stmt->execute([$jobId,$runId,self::KIND]);
        $row=$stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('expertise_job_attempt_not_found');
        return ['job_id'=>(int)$row['id'],'run_id'=>(int)$row['scientific_run_id'],'status'=>(string)$row['status']];
    }

    /** Called only by the worker after all its work for this exact attempt has stopped. */
    public function acknowledgeCancellation(int $runId,int $jobId): array
    {
        $pdo=$this->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $job=$this->executionState($runId,$jobId);
            if ($job['status']==='cancelling') {
                $stmt=$pdo->prepare("UPDATE analysis_jobs SET status='cancelled',progress=0,error='cancelled_by_user',updated_at=? WHERE id=? AND scientific_run_id=? AND kind=? AND dispatch_owner='lab' AND status='cancelling'");
                $stmt->execute([gmdate('c'),$jobId,$runId,self::KIND]);
                if ($stmt->rowCount()!==1) throw new \RuntimeException('expertise_cancel_ack_lost');
            } elseif ($job['status']!=='cancelled') {
                throw new \RuntimeException('expertise_job_not_cancelling');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->executionState($runId,$jobId);
    }

    public function finish(int $runId,string $status,string $message,?int $jobId=null): array
    {
        if (!in_array($status,['completed','error'],true)) throw new \InvalidArgumentException('invalid_status');
        // All patched workers supply the exact job ID. Unfenced legacy calls are
        // refused after a prior cancellation to prevent a late finish crossing attempts.
        if ($jobId===null) {
            $historical=$this->pdo()->prepare("SELECT COUNT(*) FROM analysis_jobs WHERE scientific_run_id=? AND kind=? AND error LIKE 'cancel%'");
            $historical->execute([$runId,self::KIND]);
            if ((int)$historical->fetchColumn()>0) throw new \RuntimeException('unfenced_finish_after_cancellation');
        }
        $sql="UPDATE analysis_jobs SET status=?,progress=?,error=?,updated_at=? WHERE scientific_run_id=? AND kind=? AND dispatch_owner='lab' AND status='running'";
        $args=[$status,$status==='completed'?100:0,mb_substr($message,0,1000),gmdate('c'),$runId,self::KIND];
        if ($jobId!==null) {
            if ($jobId<1) throw new \InvalidArgumentException('invalid_job_id');
            $sql.=' AND id=?';
            $args[]=$jobId;
        }
        $stmt=$this->pdo()->prepare($sql);
        $stmt->execute($args);
        if ($stmt->rowCount()!==1) throw new \RuntimeException('request_not_running');
        $result = $this->latest($runId) ?? throw new \RuntimeException('result_not_found');
        if ($status === 'completed') {
            // A completed job may have no external revision at all (including
            // historical/minimal SQLite schemas). Do not change schema here.
            $tableStmt = $this->pdo()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='profile_external_revisions' LIMIT 1");
            if ($tableStmt->fetchColumn() === false) {
                return $result; // No review persisted: nothing to publish.
            }
            $revisionStmt = $this->pdo()->prepare("SELECT r.id FROM profile_external_revisions r JOIN analysis_jobs aj ON aj.scientific_run_id=r.run_id WHERE aj.id=? AND aj.kind='assistant.profile.review' AND r.updated_at >= aj.created_at ORDER BY r.id DESC LIMIT 1");
            $revisionStmt->execute([(int) $result['job_id']]);
            $revisionId = $revisionStmt->fetchColumn();
            if ($revisionId !== false) {
                // Post-commit summary and raw diagnostic are published together
                // by the Assistant after trace.finish, on MAIN (R4).
            }
        }
        return $result;
    }

    /** Called only after the dedicated old worker has been stopped. */
    public function migrateLegacy(): array
    {
        $pdo=$this->pdo();
        $exists=(bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='profile_ai_requests'")->fetchColumn();
        if (!$exists) return ['legacy'=>0,'migrated'=>0];
        $rows=$pdo->query("SELECT run_id,status FROM profile_ai_requests WHERE status IN ('pending','running','error')")->fetchAll(\PDO::FETCH_ASSOC);
        $migrated=0;
        foreach ($rows as $row) {
            // Retain terminal records and do not mutate the old table. Rows are idempotently imported.
            $runId=(int)$row['run_id'];
            if ($this->get($runId)===null) { $this->enqueue($runId); ++$migrated; }
        }
        return ['legacy'=>count($rows),'migrated'=>$migrated];
    }
}
