<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

use PDO;

/**
 * Single application-agnostic durable queue kernel.
 * No live worker or HTTP mutation endpoint. Recovery requires a fenced executor attestation.
 */
final class GlobalQueueStore
{
    private PDO $db;
    private string $queuePath;

    public function __construct(string $path)
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException('pdo_sqlite_required');
        }
        $this->queuePath = $path;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('queue_directory_unavailable');
        }
        $this->db = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec("CREATE TABLE IF NOT EXISTS global_queue_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            producer TEXT NOT NULL,
            external_id TEXT NOT NULL,
            idempotency_key TEXT NOT NULL UNIQUE,
            kind TEXT NOT NULL,
            resource_class TEXT NOT NULL CHECK(resource_class IN ('cpu','gpu')),
            priority INTEGER NOT NULL CHECK(priority BETWEEN 0 AND 100),
            state TEXT NOT NULL CHECK(state IN ('queued','running','completed','failed','cancelled')),
            lease_token TEXT,
            lease_until INTEGER,
            claim_generation INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            UNIQUE(producer,external_id)
        )");
        $this->db->exec('CREATE INDEX IF NOT EXISTS global_queue_pick ON global_queue_jobs(state,resource_class,priority DESC,created_at,id)');
        $this->db->exec("CREATE TABLE IF NOT EXISTS global_queue_events (
            event_id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL,
            producer TEXT NOT NULL,
            event_type TEXT NOT NULL CHECK(event_type IN ('progress','heartbeat','warning','error')),
            progress_percent INTEGER CHECK(progress_percent IS NULL OR progress_percent BETWEEN 0 AND 99),
            phase TEXT,
            message TEXT,
            created_at INTEGER NOT NULL,
            FOREIGN KEY(job_id) REFERENCES global_queue_jobs(id)
        )");
        $this->db->exec('CREATE INDEX IF NOT EXISTS global_queue_event_by_producer ON global_queue_events(producer,event_id)');
    }

    /** Enqueue is idempotent for identical immutable technical envelopes. */
    public function enqueue(string $producer, string $externalId, string $key, string $kind, string $resourceClass, int $priority = 50): array
    {
        foreach ([$producer,$externalId,$key,$kind] as $value) {
            if ($value === '' || strlen($value) > 128 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \InvalidArgumentException('invalid_queue_identity');
            }
        }
        if (!in_array($resourceClass, ['cpu','gpu'], true) || $priority < 0 || $priority > 100) {
            throw new \InvalidArgumentException('invalid_queue_capability');
        }
        return $this->transaction(function () use ($producer,$externalId,$key,$kind,$resourceClass,$priority): array {
            $now = time();
            $stmt = $this->db->prepare("INSERT OR IGNORE INTO global_queue_jobs (producer,external_id,idempotency_key,kind,resource_class,priority,state,created_at,updated_at) VALUES (?,?,?,?,?,?,'queued',?,?)");
            $stmt->execute([$producer,$externalId,$key,$kind,$resourceClass,$priority,$now,$now]);
            $row=$this->byKey($key);
            if ($row===null || $row['producer']!==$producer || $row['external_id']!==$externalId || $row['kind']!==$kind || $row['resource_class']!==$resourceClass || (int)$row['priority']!==$priority) {
                throw new \DomainException('queue_identity_collision');
            }
            return $row;
        });
    }

    /** Cancel unclaimed queue requests atomically; running jobs require independent stop proof. */
    public function cancelQueued(int $id, string $producer): array
    {
        if ($id < 1 || $producer === '' || strlen($producer) > 128) {
            throw new \InvalidArgumentException('invalid_cancel_identity');
        }
        return $this->transaction(function () use ($id, $producer): array {
            $job = $this->byId($id);
            if ($job === null || $job['producer'] !== $producer) {
                throw new \DomainException('queue_job_not_owned');
            }
            if ($job['state'] === 'cancelled') return $job;
            if ($job['state'] === 'running') {
                throw new \DomainException('active_executor_stop_attestation_required');
            }
            if ($job['state'] !== 'queued') {
                throw new \DomainException('terminal_job_not_cancellable');
            }
            $now = time();
            $stmt = $this->db->prepare("UPDATE global_queue_jobs SET state='cancelled',updated_at=? WHERE id=? AND producer=? AND state='queued' AND lease_token IS NULL AND lease_until IS NULL");
            $stmt->execute([$now,$id,$producer]);
            if ($stmt->rowCount() !== 1) {
                throw new \DomainException('concurrent_job_claim_or_cancel');
            }
            $event = $this->db->prepare("INSERT INTO global_queue_events(job_id,producer,event_type,progress_percent,phase,message,created_at) VALUES (?,?,'warning',NULL,'cancelled_by_producer','queued_job_cancelled',?)");
            $event->execute([$id,$producer,$now]);
            return $this->byId($id);
        });
    }

    /** Atomic claim; no wall-clock expiration, no stealing a RUNNING job. */
    public function claim(string $resourceClass, int $leaseSeconds = 120, ?int $onlyJobId = null): ?array
    {
        if (!in_array($resourceClass, ['cpu','gpu'],true) || $leaseSeconds < 5 || $leaseSeconds > 3600
            || ($onlyJobId !== null && $onlyJobId < 1)) {
            throw new \InvalidArgumentException('invalid_claim');
        }
        return $this->transaction(function () use ($resourceClass,$leaseSeconds,$onlyJobId): ?array {
            // One active execution globally. Only a separately attested stopped
            // executor may be manually reconciled; time never releases ownership.
            $busy = $this->db->query("SELECT 1 FROM global_queue_jobs WHERE state='running' LIMIT 1");
            if ($busy->fetchColumn() !== false) {
                return null;
            }
            // FIFO globally, without CPU/GPU scheduling or priority inversion.
            // A specialised executor must not bypass the oldest requested job.
            $head = $this->db->query("SELECT id,resource_class FROM global_queue_jobs WHERE state='queued' ORDER BY created_at ASC,id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!is_array($head) || $head['resource_class'] !== $resourceClass
                || ($onlyJobId !== null && (int) $head['id'] !== $onlyJobId)) {
                return null;
            }
            $id = (int) $head['id'];
            $token=bin2hex(random_bytes(32));
            $now=time();
            $u=$this->db->prepare("UPDATE global_queue_jobs SET state='running',lease_token=?,lease_until=NULL,claim_generation=claim_generation+1,updated_at=? WHERE id=? AND state='queued'");
            $u->execute([$token,$now,(int)$id]);
            if ($u->rowCount()!==1) throw new \RuntimeException('claim_conflict');
            return $this->byId((int)$id);
        });
    }

    /** A live lease holder alone may finalize. Result payload remains owned by producer. */
    public function finish(int $id,string $token,bool $success): array
    {
        if ($id<=0 || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new \InvalidArgumentException('invalid_completion');
        return $this->transaction(function () use ($id,$token,$success): array {
            $row=$this->byId($id);
            if ($row===null || $row['state']!=='running' || !hash_equals((string)$row['lease_token'],$token)) {
                throw new \DomainException('lease_not_current');
            }
            $state=$success?'completed':'failed';
            $stmt=$this->db->prepare("UPDATE global_queue_jobs SET state=?,lease_token=NULL,lease_until=NULL,updated_at=? WHERE id=? AND lease_token=? AND state='running'");
            $stmt->execute([$state,time(),$id,$token]);
            if ($stmt->rowCount()!==1) throw new \RuntimeException('completion_conflict');
            return $this->byId($id);
        });
    }

    /** Worker-owned progress or heartbeat; never fabricate a percentage. */
    public function heartbeat(int $id, string $token, int $leaseSeconds = 120, ?int $percent = null, ?string $phase = null, ?string $message = null): array
    {
        if ($id <= 0 || !preg_match('/^[a-f0-9]{64}$/D', $token) || $leaseSeconds < 5 || $leaseSeconds > 3600) {
            throw new \InvalidArgumentException('invalid_heartbeat');
        }
        if ($percent !== null && ($percent < 0 || $percent > 99)) throw new \InvalidArgumentException('invalid_progress');
        if (($phase !== null && (strlen($phase) > 96 || preg_match('/[\x00-\x1f\x7f]/', $phase)))
            || ($message !== null && (strlen($message) > 240 || preg_match('/[\x00-\x1f\x7f]/', $message)))) {
            throw new \InvalidArgumentException('invalid_progress_text');
        }
        return $this->transaction(function () use ($id,$token,$leaseSeconds,$percent,$phase,$message): array {
            $row=$this->byId($id);
            $now=time();
            if ($row===null || $row['state']!=='running' || !hash_equals((string)$row['lease_token'],$token)
               ) throw new \DomainException('lease_not_current');
            if ($percent!==null) {
                $query=$this->db->prepare("SELECT MAX(progress_percent) FROM global_queue_events WHERE job_id=? AND progress_percent IS NOT NULL");
                $query->execute([$id]);
                $previous=$query->fetchColumn();
                if ($previous!==null && $previous!==false && $percent < (int)$previous) throw new \DomainException('progress_regression');
            }
            $up=$this->db->prepare("UPDATE global_queue_jobs SET updated_at=? WHERE id=? AND lease_token=? AND state='running'");
            $up->execute([$now,$id,$token]);
            if ($up->rowCount()!==1) throw new \RuntimeException('heartbeat_conflict');
            $event=$this->db->prepare('INSERT INTO global_queue_events(job_id,producer,event_type,progress_percent,phase,message,created_at) VALUES (?,?,?,?,?,?,?)');
            $event->execute([$id,$row['producer'],$percent===null?'heartbeat':'progress',$percent,$phase,$message,$now]);
            return ['job_id'=>$id,'lease_until'=>null,'event_id'=>(int)$this->db->lastInsertId(),'progress_percent'=>$percent];
        });
    }

    /** Read-only inventory of RUNNING claims, independent of age or deadline. */
    public function runningClaims(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('invalid_running_claim_limit');
        }
        $stmt = $this->db->prepare("SELECT id,producer,external_id,kind,resource_class,claim_generation FROM global_queue_jobs WHERE state='running' ORDER BY created_at ASC,id ASC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Manual incident recovery of a stopped executor, independently attested.
     * There is deliberately NO age/expiry condition; state and identity are decisive.
     * This method must never be called automatically by a polling worker.
     * @param \Closure(array): bool $attestStopped
     */
    public function recoverStopped(int $id, int $expectedGeneration, \Closure $attestStopped): array
    {
        if ($id <= 0 || $expectedGeneration < 1) {
            throw new \InvalidArgumentException('invalid_recovery_identity');
        }
        $candidate = $this->byId($id);
        if ($candidate === null || $candidate['state'] !== 'running'
            || (int)$candidate['claim_generation'] !== $expectedGeneration) {
            throw new \DomainException('claim_not_running_current');
        }
        $proof = [
            'id' => (int)$candidate['id'],
            'producer' => $candidate['producer'],
            'external_id' => $candidate['external_id'],
            'resource_class' => $candidate['resource_class'],
            'claim_generation' => $expectedGeneration,
        ];
        if ($attestStopped($proof) !== true) {
            throw new \DomainException('executor_not_attested_stopped');
        }
        return $this->transaction(function () use ($id, $expectedGeneration, $candidate): array {
            $current = $this->byId($id);
            if ($current === null || $current['state'] !== 'running'
                || (int)$current['claim_generation'] !== $expectedGeneration
                || !hash_equals((string)$candidate['lease_token'], (string)$current['lease_token'])) {
                throw new \DomainException('recovery_generation_changed');
            }
            $now = time();
            $stmt = $this->db->prepare("UPDATE global_queue_jobs SET state='queued',lease_token=NULL,lease_until=NULL,claim_generation=claim_generation+1,updated_at=? WHERE id=? AND state='running' AND claim_generation=? AND lease_token=?");
            $stmt->execute([$now,$id,$expectedGeneration,$candidate['lease_token']]);
            if ($stmt->rowCount() !== 1) throw new \RuntimeException('recovery_conflict');
            $event = $this->db->prepare("INSERT INTO global_queue_events(job_id,producer,event_type,progress_percent,phase,message,created_at) VALUES (?,?,'warning',NULL,'executor_recovery','executor_stopped_attested',?)");
            $event->execute([$id,$current['producer'],$now]);
            return $this->byId($id);
        });
    }

    /** Cursor-based immutable event read, scoped by producer. Never expose lease secrets. */
    public function events(string $producer, int $after = 0, int $limit = 100): array
    {
        if ($producer==='' || strlen($producer)>128 || $after<0 || $limit<1 || $limit>100) {
            throw new \InvalidArgumentException('invalid_event_query');
        }
        $q=$this->db->prepare('SELECT event_id,job_id,event_type,progress_percent,phase,message,created_at FROM global_queue_events WHERE producer=? AND event_id>? ORDER BY event_id ASC LIMIT ?');
        $q->bindValue(1,$producer,PDO::PARAM_STR);
        $q->bindValue(2,$after,PDO::PARAM_INT);
        $q->bindValue(3,$limit,PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function byId(int $id): ?array
    {
        $s=$this->db->prepare('SELECT * FROM global_queue_jobs WHERE id=?');$s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }

    private function byKey(string $key): ?array
    {
        $s=$this->db->prepare('SELECT * FROM global_queue_jobs WHERE idempotency_key=?');$s->execute([$key]);
        return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }

    private function transaction(\Closure $callback): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $value=$callback();$this->db->commit();return $value;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
