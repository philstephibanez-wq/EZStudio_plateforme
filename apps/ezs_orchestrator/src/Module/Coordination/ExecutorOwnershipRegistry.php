<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

use PDO;

/** Persist immutable claim-to-process identity in the canonical queue database. */
final class ExecutorOwnershipRegistry
{
    private PDO $db;

    public function __construct(string $queueDatabasePath)
    {
        if (!extension_loaded('pdo_sqlite') || !is_file($queueDatabasePath)) {
            throw new \RuntimeException('canonical_queue_database_required');
        }
        $this->db = new PDO('sqlite:'.$queueDatabasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec("CREATE TABLE IF NOT EXISTS global_queue_executor_ownership (
            job_id INTEGER NOT NULL,
            claim_generation INTEGER NOT NULL,
            node TEXT NOT NULL,
            pid INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            instance_id TEXT NOT NULL,
            registered_at INTEGER NOT NULL,
            PRIMARY KEY(job_id,claim_generation),
            FOREIGN KEY(job_id) REFERENCES global_queue_jobs(id)
        )");
    }

    /** Only the current claim holder can register an immutable identity; no expiry. */
    public function register(int $jobId, int $generation, string $leaseToken, string $node, int $pid, string $createdAt, string $instanceId): array
    {
        if ($jobId <= 0 || $generation <= 0 || $pid <= 0 || !preg_match('/^[a-f0-9]{64}$/D', $leaseToken)) {
            throw new \InvalidArgumentException('invalid_executor_claim');
        }
        foreach ([$node, $createdAt, $instanceId] as $value) {
            if ($value === '' || strlen($value) > 128 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \InvalidArgumentException('invalid_executor_identity');
            }
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->db->prepare("SELECT state,lease_token,claim_generation FROM global_queue_jobs WHERE id=?");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job || $job['state'] !== 'running' || (int)$job['claim_generation'] !== $generation
                || !hash_equals((string)$job['lease_token'], $leaseToken)) {
                throw new \DomainException('executor_claim_not_current');
            }
            $existing = $this->forClaim($jobId, $generation);
            if ($existing !== null) {
                if ($existing['node'] !== $node || (int)$existing['pid'] !== $pid
                    || $existing['created_at'] !== $createdAt || $existing['instance_id'] !== $instanceId) {
                    throw new \DomainException('executor_identity_collision');
                }
                $this->db->commit();
                return $existing;
            }
            $insert = $this->db->prepare('INSERT INTO global_queue_executor_ownership(job_id,claim_generation,node,pid,created_at,instance_id,registered_at) VALUES (?,?,?,?,?,?,?)');
            $insert->execute([$jobId,$generation,$node,$pid,$createdAt,$instanceId,time()]);
            $result = $this->forClaim($jobId, $generation);
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Retains historical generations. No token is exposed. */
    public function forClaim(int $jobId, int $generation): ?array
    {
        if ($jobId <= 0 || $generation <= 0) {
            throw new \InvalidArgumentException('invalid_executor_lookup');
        }
        $stmt = $this->db->prepare('SELECT job_id AS id,claim_generation,node,pid,created_at,instance_id,registered_at FROM global_queue_executor_ownership WHERE job_id=? AND claim_generation=?');
        $stmt->execute([$jobId,$generation]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
