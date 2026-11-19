<?php

declare(strict_types=1);

namespace EZSLab\Service;

use PDO;

/** LAB is the sole authority for its analysis_jobs and scientific_runs. */
final class LabRetryService
{
    private const RETRYABLE = ['failed', 'error', 'aborted', 'stalled'];
    private const KINDS = [
        'lab:profile' => ['profile'],
        'lab:stems' => ['stems_scientific', 'stems'],
        'lab:chords' => ['chords_scientific', 'chords'],
        'lab:lyrics' => ['lyrics_scientific', 'lyrics'],
        'assistant:profile.review' => ['assistant.profile.review'],
        'assistant:stems.review' => ['assistant.stems.review'],
        'assistant:chords.review' => ['assistant.chords.review'],
        'assistant:lyrics.review' => ['assistant.lyrics.review'],
    ];

    public function __construct(private readonly Database $database) {}

    /** No database paths, engine output, audio, or source SQL is returned over REST. */
    public function prepare(array $request): array
    {
        $identity = $this->validate($request);
        $pdo = $this->database->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->ensureProtocol($pdo);
            $existing = $this->receipt($pdo, $identity['global_job_id'], $identity['attempt']);
            if ($existing !== null) {
                if ((int)$existing['lab_job_id'] !== $identity['lab_job_id']) {
                    throw new \DomainException('retry_receipt_identity_conflict');
                }
                if ($existing['state'] === 'aborted') {
                    $pdo->prepare('DELETE FROM ezs_retry_receipts WHERE global_job_id=? AND attempt=?')->execute([$identity['global_job_id'],$identity['attempt']]);
                } elseif ($existing['state'] === 'prepared' || $existing['state'] === 'committed') {
                    $pdo->commit();
                    return ['ok'=>true, 'reconciled'=>true, 'reason'=>'etat_metier_deja_rearme'];
                } else {
                    throw new \DomainException('retry_receipt_not_active');
                }
            }
            $job = $this->get($pdo, 'SELECT * FROM analysis_jobs WHERE id=?', [$identity['lab_job_id']]);
            if ($job === null) throw new \DomainException('tache_metier_introuvable');
            if (!in_array((string)$job['kind'], $identity['expected'], true)) throw new \DomainException('incoherence_kind_metier');
            if ($identity['song_id'] > 0 && (int)$job['song_id'] !== $identity['song_id']) throw new \DomainException('incoherence_song_id');
            if ($identity['run_id'] > 0 && (int)$job['scientific_run_id'] > 0 && (int)$job['scientific_run_id'] !== $identity['run_id']) throw new \DomainException('incoherence_scientific_run_id');
            $req = json_decode((string)($job['request_json'] ?? '{}'), true);
            if (is_array($req)) {
                foreach (['engine','engine_profile','preset'] as $name) {
                    $a = trim((string)($identity['payload'][$name] ?? ''));
                    $b = trim((string)($req[$name] ?? ''));
                    if ($a !== '' && $b !== '' && $a !== $b) throw new \DomainException('incoherence_moteur_ou_preset');
                }
            }
            $owner = (string)($job['dispatch_owner'] ?? 'lab');
            if ($owner === 'orchestrator') {
                $outbox = $this->get($pdo, 'SELECT state,global_job_id FROM global_queue_admission_outbox WHERE job_id=?', [$identity['lab_job_id']]);
                if (!$outbox || $outbox['state'] !== 'admitted' || (int)$outbox['global_job_id'] !== $identity['global_job_id']) {
                    throw new \DomainException('global_job_identity_conflict');
                }
            } elseif ($owner !== 'lab') {
                throw new \DomainException('dispatch_owner_invalide');
            }
            $runId = (int)($job['scientific_run_id'] ?? 0);
            if ($identity['source'] === 'lab' && $runId < 1) throw new \DomainException('scientific_run_id_manquant');
            $run = $runId > 0 ? $this->get($pdo, 'SELECT * FROM scientific_runs WHERE id=?', [$runId]) : null;
            if ($identity['source'] === 'lab' && $run === null) throw new \DomainException('scientific_run_introuvable');
            $already = $job['status'] === 'queued' && (int)$job['progress'] === 0 && $job['error'] === null;
            if (!$already && !in_array($job['status'], self::RETRYABLE, true)) throw new \DomainException('etat_metier_non_readmissible_'.$job['status']);
            if ($run !== null && $identity['source'] === 'lab' && !in_array($run['state'], [...self::RETRYABLE, 'queued'], true)) {
                throw new \DomainException('etat_run_non_readmissible_'.$run['state']);
            }
            $pdo->prepare('INSERT INTO ezs_retry_receipts(global_job_id,attempt,lab_job_id,state,original_job,original_run,created_at) VALUES(?,?,?,?,?,?,?)')
                ->execute([$identity['global_job_id'],$identity['attempt'],$identity['lab_job_id'],'prepared',json_encode($job,JSON_THROW_ON_ERROR),$run ? json_encode($run,JSON_THROW_ON_ERROR) : null,gmdate('c')]);
            if (!$already) {
                $ph = implode(',',array_fill(0,count(self::RETRYABLE),'?'));
                $update = $pdo->prepare("UPDATE analysis_jobs SET status='queued',progress=0,error=NULL,updated_at=? WHERE id=? AND status IN ($ph)");
                $update->execute([gmdate('c'),$identity['lab_job_id'],...self::RETRYABLE]);
                if ($update->rowCount() !== 1) throw new \DomainException('conflit_rearmement_metier');
            }
            if ($identity['source'] === 'lab' && $run !== null && in_array($run['state'],self::RETRYABLE,true)) {
                $pdo->prepare("UPDATE scientific_runs SET state='queued',finished_at=NULL WHERE id=? AND state=?")
                    ->execute([$runId,$run['state']]);
            }
            $pdo->commit();
            return ['ok'=>true,'reconciled'=>$already,'reason'=>$already?'etat_metier_deja_rearme':'etat_metier_rearme'];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function finish(array $request, bool $abort): array
    {
        $identity = $this->validate($request);
        $pdo = $this->database->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->ensureProtocol($pdo);
            $receipt = $this->receipt($pdo,$identity['global_job_id'],$identity['attempt']);
            if (!$receipt || (int)$receipt['lab_job_id'] !== $identity['lab_job_id']) throw new \DomainException('retry_receipt_missing');
            if ($receipt['state'] === 'committed' && !$abort || $receipt['state'] === 'aborted' && $abort) {
                $pdo->commit(); return ['ok'=>true,'reconciled'=>true];
            }
            if ($receipt['state'] !== 'prepared') throw new \DomainException('retry_receipt_state_conflict');
            if ($abort) {
                $job = $this->get($pdo,'SELECT * FROM analysis_jobs WHERE id=?',[$identity['lab_job_id']]);
                if (!$job || $job['status'] !== 'queued' || (int)$job['progress'] !== 0 || $job['error'] !== null) {
                    throw new \DomainException('retry_abort_local_state_changed');
                }
                $before = json_decode($receipt['original_job'],true,flags:JSON_THROW_ON_ERROR);
                $originalRun = $receipt['original_run'] === null ? null : json_decode($receipt['original_run'],true,flags:JSON_THROW_ON_ERROR);
                $pdo->prepare("UPDATE ezs_retry_receipts SET state='aborting' WHERE global_job_id=? AND attempt=?")
                    ->execute([$identity['global_job_id'],$identity['attempt']]);
                $pdo->prepare('UPDATE analysis_jobs SET status=?,progress=?,error=?,updated_at=? WHERE id=?')
                    ->execute([$before['status'],$before['progress'],$before['error'],$before['updated_at'],$identity['lab_job_id']]);
                if ($originalRun !== null) {
                    $pdo->prepare('UPDATE scientific_runs SET state=?,finished_at=? WHERE id=? AND state=?')
                        ->execute([$originalRun['state'],$originalRun['finished_at'],(int)$originalRun['id'],'queued']);
                }
                $target = 'aborted';
            } else {
                $target = 'committed';
            }
            $pdo->prepare('UPDATE ezs_retry_receipts SET state=? WHERE global_job_id=? AND attempt=?')
                ->execute([$target,$identity['global_job_id'],$identity['attempt']]);
            $pdo->commit();
            return ['ok'=>true,'reconciled'=>false];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function state(array $request): array
    {
        $identity = $this->validate($request);
        $pdo = $this->database->pdo();
        $job = $this->get($pdo,'SELECT id,kind,song_id,scientific_run_id,status,progress,error,dispatch_owner FROM analysis_jobs WHERE id=?',[$identity['lab_job_id']]);
        if (!$job || !in_array($job['kind'],$identity['expected'],true)
            || $identity['song_id'] > 0 && (int)$job['song_id'] !== $identity['song_id']
            || $identity['run_id'] > 0 && (int)$job['scientific_run_id'] > 0 && (int)$job['scientific_run_id'] !== $identity['run_id']) {
            throw new \DomainException('retry_state_identity_conflict');
        }
        if (($job['dispatch_owner'] ?? 'lab') === 'orchestrator') {
            $o = $this->get($pdo,'SELECT global_job_id,state FROM global_queue_admission_outbox WHERE job_id=?',[$identity['lab_job_id']]);
            if (!$o || $o['state'] !== 'admitted' || (int)$o['global_job_id'] !== $identity['global_job_id']) throw new \DomainException('global_job_identity_conflict');
        }
        return ['ok'=>true,'queued'=>$job['status']==='queued' && (int)$job['progress']===0 && $job['error']===null];
    }

    private function validate(array $request): array
    {
        $global = filter_var($request['global_job_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $attempt = filter_var($request['attempt']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        $source = $request['source']??null;
        $payload = $request['payload']??null;
        if ($global === false || $global === null || $attempt === false || $attempt === null || !is_string($source) || !is_array($payload)) throw new \InvalidArgumentException('retry_request_invalid');
        $type = trim((string)($payload['job']??$payload['phase']??$payload['type']??''));
        $expected = self::KINDS[$source.':'.$type]??null;
        if ($expected===null) throw new \InvalidArgumentException('couple_source_kind_invalide');
        $id = $source==='lab' ? (int)($payload['analysis_job_id']??$payload['job_id']??0) : (int)($payload['job_id']??0);
        if ($id<1) throw new \InvalidArgumentException('identifiant_metier_absent');
        return ['global_job_id'=>(int)$global,'attempt'=>(int)$attempt,'lab_job_id'=>$id,'expected'=>$expected,'source'=>$source,'payload'=>$payload,
            'run_id'=>(int)($payload['scientific_run_id']??$payload['benchmark_run_id']??$payload['run_id']??0),'song_id'=>(int)($payload['song_id']??0)];
    }

    private function get(PDO $pdo,string $sql,array $args): ?array
    {
        $st=$pdo->prepare($sql);$st->execute($args);$v=$st->fetch(PDO::FETCH_ASSOC);
        return is_array($v)?$v:null;
    }

    private function receipt(PDO $pdo,int $global,int $attempt): ?array
    {
        return $this->get($pdo,'SELECT * FROM ezs_retry_receipts WHERE global_job_id=? AND attempt=?',[$global,$attempt]);
    }

    /** One-time transactional schema migration. Preserve all existing global queue fences. */
    private function ensureProtocol(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ezs_retry_receipts (
            global_job_id INTEGER NOT NULL, attempt INTEGER NOT NULL,lab_job_id INTEGER NOT NULL,
            state TEXT NOT NULL CHECK(state IN ('prepared','committed','aborting','aborted')),
            original_job TEXT NOT NULL,original_run TEXT,created_at TEXT NOT NULL,
            PRIMARY KEY(global_job_id,attempt))");
        $st=$pdo->query("SELECT sql FROM sqlite_master WHERE type='trigger' AND name='trg_global_queue_admission_fence_status'");
        $old=$st->fetchColumn();
        if (!is_string($old) || !str_contains($old,'global_queue_dispatch_fenced')) {
            throw new \RuntimeException('retry_protocol_existing_fence_unrecognized');
        }
        if (str_contains($old,'ezs_retry_receipts')) return;
        $expectedOriginal = <<<'ORIGINAL'
CREATE TRIGGER IF NOT EXISTS trg_global_queue_admission_fence_status
BEFORE UPDATE OF status ON analysis_jobs
WHEN OLD.dispatch_owner='orchestrator' AND NEW.status <> OLD.status
 AND NOT (
     OLD.status='queued' AND NEW.status IN ('failed','cancelled')
     AND EXISTS (
        SELECT 1 FROM global_queue_terminal_receipts proof
        JOIN global_queue_admission_outbox o ON o.job_id=proof.job_id
        WHERE proof.job_id=OLD.id AND o.state='admitted'
          AND o.global_job_id=proof.global_job_id
          AND proof.terminal_state=NEW.status
     )
 )
BEGIN
    SELECT RAISE(ABORT,'global_queue_dispatch_fenced');
END
ORIGINAL;
        // SQLite removes the optional IF NOT EXISTS clause from sqlite_master.sql.
        // Normalize only that syntactic distinction, whitespace and terminal ';'.
        // Any substantive change to the existing fence still fails closed.
        $canonical = static function (string $sql): string {
            $sql = trim($sql);
            $sql = (string)preg_replace('/\ACREATE\s+TRIGGER\s+IF\s+NOT\s+EXISTS\s+/i', 'CREATE TRIGGER ', $sql);
            $sql = rtrim($sql, " \t\r\n;");
            return strtolower((string)preg_replace('/\s+/', '', $sql));
        };
        if ($canonical($old) !== $canonical($expectedOriginal)) {
            throw new \RuntimeException('retry_protocol_existing_fence_drift');
        }
        // Do not permit a retried global job to become unowned: the receipt and
        // the admission outbox must both identify the exact global queue ID.
        $pdo->exec('DROP TRIGGER trg_global_queue_admission_fence_status');
        $pdo->exec(<<<'SQL'
CREATE TRIGGER trg_global_queue_admission_fence_status
BEFORE UPDATE OF status ON analysis_jobs
WHEN OLD.dispatch_owner='orchestrator' AND NEW.status <> OLD.status
 AND NOT (
     (OLD.status='queued' AND NEW.status IN ('failed','cancelled')
       AND EXISTS (SELECT 1 FROM global_queue_terminal_receipts proof
         JOIN global_queue_admission_outbox o ON o.job_id=proof.job_id
         WHERE proof.job_id=OLD.id AND o.state='admitted'
           AND o.global_job_id=proof.global_job_id AND proof.terminal_state=NEW.status))
     OR (OLD.status IN ('failed','error','aborted','stalled') AND NEW.status='queued'
       AND EXISTS (SELECT 1 FROM ezs_retry_receipts rr
         JOIN global_queue_admission_outbox o ON o.job_id=rr.lab_job_id
         WHERE rr.lab_job_id=OLD.id AND rr.state='prepared'
           AND o.state='admitted' AND o.global_job_id=rr.global_job_id))
     OR (OLD.status='queued' AND NEW.status IN ('failed','error','aborted','stalled')
       AND EXISTS (SELECT 1 FROM ezs_retry_receipts rr
         JOIN global_queue_admission_outbox o ON o.job_id=rr.lab_job_id
         WHERE rr.lab_job_id=OLD.id AND rr.state='aborting'
           AND o.state='admitted' AND o.global_job_id=rr.global_job_id))
 )
BEGIN SELECT RAISE(ABORT,'global_queue_dispatch_fenced'); END;
SQL);
    }
}
