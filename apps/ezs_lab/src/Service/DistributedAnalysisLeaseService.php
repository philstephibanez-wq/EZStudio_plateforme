<?php

declare(strict_types=1);

namespace EZSLab\Service;

use PDO;

final class DistributedAnalysisLeaseService
{
    private const PROTOCOL = 'ezstudio.distributed.v1';
    private const WORKER_STALE_SECONDS = 120;

    public function __construct(private readonly Database $db) {}

    private function ensureSchema(): void
    {
        $this->db->pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS analysis_workers (
    worker_id TEXT PRIMARY KEY,
    protocol TEXT NOT NULL,
    capabilities_json TEXT NOT NULL,
    state TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    last_seen_epoch INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_analysis_workers_seen
ON analysis_workers(last_seen_epoch);

CREATE TABLE IF NOT EXISTS analysis_job_leases (
    job_id INTEGER PRIMARY KEY,
    worker_id TEXT NOT NULL,
    lease_token TEXT NOT NULL UNIQUE,
    acquired_at TEXT NOT NULL,
    heartbeat_at TEXT NOT NULL,
    expires_at_epoch INTEGER NOT NULL,
    FOREIGN KEY(job_id) REFERENCES analysis_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY(worker_id) REFERENCES analysis_workers(worker_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_analysis_job_leases_expiry
ON analysis_job_leases(expires_at_epoch);
CREATE INDEX IF NOT EXISTS idx_analysis_job_leases_worker
ON analysis_job_leases(worker_id);
SQL);
    }

    private function normalizeWorkerId(string $workerId): string
    {
        $workerId = trim($workerId);
        if (
            $workerId === ''
            || strlen($workerId) > 128
            || preg_match('/^[A-Za-z0-9._:-]+$/', $workerId) !== 1
        ) {
            throw new \RuntimeException('invalid_worker_id');
        }
        return $workerId;
    }

    public function heartbeatWorker(
        string $workerId,
        array $capabilities,
        string $state = 'idle'
    ): array {
        $this->ensureSchema();
        $workerId = $this->normalizeWorkerId($workerId);
        $protocol = trim((string)($capabilities['protocol'] ?? ''));
        if ($protocol !== self::PROTOCOL) {
            throw new \RuntimeException('unsupported_worker_protocol');
        }

        $state = trim($state) !== '' ? trim($state) : 'idle';
        $now = gmdate('c');
        $epoch = time();

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO analysis_workers(
                worker_id,protocol,capabilities_json,state,last_seen_at,last_seen_epoch
             ) VALUES(?,?,?,?,?,?)
             ON CONFLICT(worker_id)
             DO UPDATE SET
                protocol=excluded.protocol,
                capabilities_json=excluded.capabilities_json,
                state=excluded.state,
                last_seen_at=excluded.last_seen_at,
                last_seen_epoch=excluded.last_seen_epoch'
        );
        $stmt->execute([
            $workerId,
            $protocol,
            json_encode(
                $capabilities,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            $state,
            $now,
            $epoch,
        ]);

        return [
            'schema' => 'ezstudio.distributed.worker-heartbeat.v1',
            'worker_id' => $workerId,
            'protocol' => $protocol,
            'state' => $state,
            'server_time' => $now,
        ];
    }

    private function worker(string $workerId): array
    {
        $this->ensureSchema();
        $workerId = $this->normalizeWorkerId($workerId);

        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM analysis_workers WHERE worker_id=?'
        );
        $stmt->execute([$workerId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('worker_not_registered');
        }

        if ((int)$row['last_seen_epoch'] < time() - self::WORKER_STALE_SECONDS) {
            throw new \RuntimeException('worker_heartbeat_stale');
        }

        $capabilities = json_decode((string)$row['capabilities_json'], true);
        if (!is_array($capabilities)) {
            throw new \RuntimeException('worker_capabilities_invalid');
        }
        $row['capabilities'] = $capabilities;
        return $row;
    }

    public function workersSnapshot(): array
    {
        $this->ensureSchema();
        $staleBefore = time() - self::WORKER_STALE_SECONDS;

        $rows = $this->db->pdo()->query(
            'SELECT worker_id,protocol,capabilities_json,state,last_seen_at,last_seen_epoch
             FROM analysis_workers
             ORDER BY worker_id'
        )->fetchAll();

        $workers = [];
        foreach ($rows as $row) {
            $capabilities = json_decode((string)$row['capabilities_json'], true);
            if (!is_array($capabilities)) {
                $capabilities = [];
            }

            $lastSeenEpoch = (int)$row['last_seen_epoch'];
            $workers[] = [
                'worker_id' => (string)$row['worker_id'],
                'protocol' => (string)$row['protocol'],
                'state' => (string)$row['state'],
                'last_seen_at' => (string)$row['last_seen_at'],
                'last_seen_epoch' => $lastSeenEpoch,
                'online' => $lastSeenEpoch >= $staleBefore,
                'capabilities' => $capabilities,
            ];
        }

        return $workers;
    }
    public function jobsSnapshot(?int $songId = null): array
    {
        $this->ensureSchema();
        $workers = $this->workersSnapshot();

        $sql = "SELECT j.*,s.title,s.artist,
                       l.worker_id AS lease_worker_id,
                       l.heartbeat_at AS lease_heartbeat_at,
                       l.expires_at_epoch AS lease_expires_at_epoch
                FROM analysis_jobs j
                JOIN songs s ON s.id=j.song_id
                LEFT JOIN analysis_job_leases l ON l.job_id=j.id";
        $params = [];
        if ($songId !== null) {
            $sql .= ' WHERE j.song_id=?';
            $params[] = $songId;
        }
        $sql .= ' ORDER BY j.id DESC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $jobs = [];
        foreach ($stmt->fetchAll() as $row) {
            $spec = $this->distributedSpec($row);
            if (!$spec['eligible']) {
                continue;
            }

            $compatibleWorkers = [];
            foreach ($workers as $worker) {
                if (!($worker['online'] ?? false)) {
                    continue;
                }
                $compat = $this->compatibility($row, $worker);
                if ($compat['eligible']) {
                    $compatibleWorkers[] = (string)$worker['worker_id'];
                }
            }

            $expiresAt = $row['lease_expires_at_epoch'] !== null
                ? (int)$row['lease_expires_at_epoch']
                : null;

            $jobs[] = [
                'job_id' => (int)$row['id'],
                'song_id' => (int)$row['song_id'],
                'title' => (string)$row['title'],
                'artist' => (string)$row['artist'],
                'kind' => (string)$row['kind'],
                'status' => (string)$row['status'],
                'progress' => (int)$row['progress'],
                'compatible_workers' => $compatibleWorkers,
                'requirements' => $spec['requirements'],
                'lease' => [
                    'worker_id' => $row['lease_worker_id'] !== null
                        ? (string)$row['lease_worker_id']
                        : null,
                    'heartbeat_at' => $row['lease_heartbeat_at'] !== null
                        ? (string)$row['lease_heartbeat_at']
                        : null,
                    'expires_at_epoch' => $expiresAt,
                    'active' => $expiresAt !== null && $expiresAt > time(),
                ],
            ];
        }

        return $jobs;
    }

    private function distributedSpec(array $job): array
    {
        $request = json_decode((string)($job['request_json'] ?? ''), true);
        if (!is_array($request)) {
            return ['eligible' => false, 'reasons' => ['invalid_job_request']];
        }

        $distributed = $request['distributed'] ?? null;
        if (!is_array($distributed) || ($distributed['enabled'] ?? false) !== true) {
            return ['eligible' => false, 'reasons' => ['distributed_not_enabled']];
        }

        if ((string)($distributed['input_mode'] ?? '') !== 'shared_path') {
            return ['eligible' => false, 'reasons' => ['shared_input_contract_required']];
        }

        $requirements = $distributed['requirements'] ?? null;
        if (!is_array($requirements)) {
            return ['eligible' => false, 'reasons' => ['distributed_requirements_missing']];
        }

        return [
            'eligible' => true,
            'reasons' => [],
            'requirements' => $requirements,
            'distributed' => $distributed,
        ];
    }

    private function compatibility(array $job, array $worker): array
    {
        $spec = $this->distributedSpec($job);
        if (!$spec['eligible']) {
            return $spec;
        }

        $cap = $worker['capabilities'];
        $req = $spec['requirements'];
        $reasons = [];

        $protocol = (string)($req['protocol'] ?? self::PROTOCOL);
        if ($protocol !== self::PROTOCOL) {
            $reasons[] = 'job_protocol_unsupported';
        }
        if ((string)($worker['protocol'] ?? '') !== $protocol) {
            $reasons[] = 'worker_protocol_mismatch';
        }

        $requiresCuda = (bool)($req['requires_cuda'] ?? false);
        if ($requiresCuda && !((bool)($cap['cuda_available'] ?? false))) {
            $reasons[] = 'cuda_required';
        }

        $minVram = max(0, (int)($req['min_vram_mb'] ?? 0));
        $maxVram = max(0, (int)($cap['max_vram_mb'] ?? 0));
        if ($minVram > $maxVram) {
            $reasons[] = 'insufficient_vram';
        }

        $minRam = max(0, (int)($req['min_ram_mb'] ?? 0));
        $ram = max(0, (int)($cap['ram_total_mb'] ?? 0));
        if ($minRam > $ram) {
            $reasons[] = 'insufficient_ram';
        }

        $allowedWorkers = $spec['distributed']['allowed_worker_ids'] ?? [];
        if (is_array($allowedWorkers) && $allowedWorkers !== []) {
            $allowedWorkers = array_map('strval', $allowedWorkers);
            if (!in_array((string)$worker['worker_id'], $allowedWorkers, true)) {
                $reasons[] = 'worker_not_allowed';
            }
        }

        $requiredModels = $req['required_models'] ?? [];
        if (!is_array($requiredModels)) {
            $reasons[] = 'required_models_invalid';
        } elseif ($requiredModels !== []) {
            $workerModels = $cap['models'] ?? null;
            if (!is_array($workerModels)) {
                $reasons[] = 'worker_model_inventory_missing';
            } else {
                $available = array_fill_keys(array_map('strval', $workerModels), true);
                foreach ($requiredModels as $model) {
                    if (!isset($available[(string)$model])) {
                        $reasons[] = 'required_model_missing:'.(string)$model;
                    }
                }
            }
        }

        return [
            'eligible' => $reasons === [],
            'reasons' => $reasons,
            'requirements' => $req,
            'distributed' => $spec['distributed'],
        ];
    }

    public function reconcileExpiredLeases(): array
    {
        $this->ensureSchema();
        $pdo = $this->db->pdo();
        $nowEpoch = time();
        $now = gmdate('c');

        $stmt = $pdo->prepare(
            'SELECT l.job_id,j.status
             FROM analysis_job_leases l
             JOIN analysis_jobs j ON j.id=l.job_id
             WHERE l.expires_at_epoch <= ?
                OR j.status IN (\'completed\',\'failed\',\'cancelled\')
             ORDER BY l.job_id'
        );
        $stmt->execute([$nowEpoch]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return [];
        }

        $cancelAfterCommit = [];
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach ($rows as $row) {
                $jobId = (int)$row['job_id'];
                $status = (string)$row['status'];

                if ($status === 'running') {
                    $pdo->prepare(
                        "UPDATE analysis_jobs
                         SET status='queued',
                             progress=0,
                             error='distributed_lease_expired_requeued',
                             updated_at=?
                         WHERE id=? AND status='running'"
                    )->execute([$now, $jobId]);
                } elseif ($status === 'cancelling') {
                    $cancelAfterCommit[] = $jobId;
                }

                $pdo->prepare(
                    'DELETE FROM analysis_job_leases WHERE job_id=?'
                )->execute([$jobId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach ($cancelAfterCommit as $jobId) {
            try {
                $this->db->markAnalysisJobCancelled($jobId);
            } catch (\RuntimeException) {
                // A concurrent terminal transition already won.
            }
        }

        return $rows;
    }

    private function queuedRows(): array
    {
        return $this->db->pdo()->query(
            "SELECT j.*,s.title,s.artist
             FROM analysis_jobs j
             JOIN songs s ON s.id=j.song_id
             WHERE j.status='queued'
               AND j.dispatch_owner='lab'
               AND NOT EXISTS (
                   SELECT 1
                   FROM analysis_resource_locks l
                   WHERE l.song_id=j.song_id
                     AND l.expires_at_epoch > CAST(strftime('%s','now') AS INTEGER)
                     AND l.phase = CASE
                         WHEN j.kind IN ('benchmark','chords','chords_scientific') THEN 'chords'
                         ELSE j.kind
                     END
               )
             ORDER BY j.created_at ASC,j.id ASC"
        )->fetchAll();
    }

    public function eligibleJobs(string $workerId, int $limit = 100): array
    {
        $this->reconcileExpiredLeases();
        $worker = $this->worker($workerId);
        $jobs = [];

        $effectiveLimit = max(1, min(500, $limit));
        foreach ($this->queuedRows() as $row) {
            $compat = $this->compatibility($row, $worker);
            if (!$compat['eligible']) {
                continue;
            }
            $jobs[] = [
                'job_id' => (int)$row['id'],
                'kind' => (string)$row['kind'],
                'song_id' => (int)$row['song_id'],
                'title' => (string)$row['title'],
                'artist' => (string)$row['artist'],
                'priority' => 50,
                'requirements' => $compat['requirements'],
            ];

            if (count($jobs) >= $effectiveLimit) {
                break;
            }
        }

        return $jobs;
    }


    private function withDistributedSharedPath(array $context): array
    {
        $paths = $context['paths'] ?? null;
        if (!is_array($paths)) {
            throw new \RuntimeException('distributed_context_paths_missing');
        }

        $source = trim((string)($paths['source'] ?? ''));
        if ($source === '') {
            throw new \RuntimeException('distributed_source_path_missing');
        }

        $projectRoot = dirname(__DIR__, 2);
        $importsRoot = $projectRoot
            .DIRECTORY_SEPARATOR.'data'
            .DIRECTORY_SEPARATOR.'imports';

        $sourceNormalized = str_replace('\\', '/', $source);
        $importsNormalized = rtrim(
            str_replace('\\', '/', $importsRoot),
            '/'
        );
        $prefix = $importsNormalized.'/';

        if (!str_starts_with(
            strtolower($sourceNormalized),
            strtolower($prefix)
        )) {
            throw new \RuntimeException('distributed_source_outside_imports');
        }

        $relative = substr($sourceNormalized, strlen($prefix));
        if ($relative === false || trim($relative, '/') === '') {
            throw new \RuntimeException(
                'distributed_source_relative_path_missing'
            );
        }

        $uncRoot = trim((string)(
            getenv('EZSTUDIO_DISTRIBUTED_IMPORTS_UNC_ROOT')
            ?: '\\\\EZSTUDIO-MAIN\\EZStudioLab-Imports'
        ));
        if (!str_starts_with($uncRoot, '\\\\')) {
            throw new \RuntimeException(
                'distributed_imports_unc_root_invalid'
            );
        }

        $sharedSource = rtrim($uncRoot, '\\/')
            .'\\'.str_replace('/', '\\', ltrim($relative, '/'));

        $context['paths']['shared_source'] = $sharedSource;
        return $context;
    }

    public function claim(
        int $jobId,
        string $workerId,
        int $leaseSeconds = 60
    ): array {
        $this->ensureSchema();
        $this->reconcileExpiredLeases();

        $worker = $this->worker($workerId);
        $workerId = (string)$worker['worker_id'];
        $leaseSeconds = max(30, min(300, $leaseSeconds));
        $pdo = $this->db->pdo();

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $pdo->prepare(
                "SELECT j.*,s.title,s.artist
                 FROM analysis_jobs j
                 JOIN songs s ON s.id=j.song_id
                 WHERE j.id=?"
            );
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();
            if (!$job) {
                throw new \RuntimeException('job_not_found');
            }
            if ((string)$job['status'] !== 'queued') {
                throw new \RuntimeException('job_not_queued');
            }
            if ((string)$job['dispatch_owner'] !== 'lab') {
                throw new \RuntimeException('global_queue_dispatch_fenced');
            }

            $compat = $this->compatibility($job, $worker);
            if (!$compat['eligible']) {
                throw new \RuntimeException(
                    'job_not_compatible:'.implode(',', $compat['reasons'])
                );
            }

            $lock = $pdo->prepare(
                "SELECT 1
                 FROM analysis_resource_locks l
                 WHERE l.song_id=?
                   AND l.expires_at_epoch > CAST(strftime('%s','now') AS INTEGER)
                   AND l.phase = CASE
                       WHEN ? IN ('benchmark','chords','chords_scientific') THEN 'chords'
                       ELSE ?
                   END
                 LIMIT 1"
            );
            $lock->execute([
                (int)$job['song_id'],
                (string)$job['kind'],
                (string)$job['kind'],
            ]);
            if ($lock->fetchColumn()) {
                throw new \RuntimeException('analysis_phase_locked');
            }

            $token = bin2hex(random_bytes(24));
            $now = gmdate('c');
            $expires = time() + $leaseSeconds;

            $update = $pdo->prepare(
                "UPDATE analysis_jobs
                 SET status='running',progress=1,updated_at=?,error=NULL
                 WHERE id=? AND status='queued'"
            );
            $update->execute([$now, $jobId]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('claim_lost_race');
            }

            if ($job['run_id'] !== null) {
                $pdo->prepare(
                    "UPDATE benchmark_runs
                     SET status='running',progress=1,updated_at=?,error=NULL
                     WHERE id=?"
                )->execute([$now, (int)$job['run_id']]);
            }

            $pdo->prepare(
                'INSERT INTO analysis_job_leases(
                    job_id,worker_id,lease_token,acquired_at,heartbeat_at,expires_at_epoch
                 ) VALUES(?,?,?,?,?,?)'
            )->execute([
                $jobId,
                $workerId,
                $token,
                $now,
                $now,
                $expires,
            ]);

            $pdo->commit();

            $context = $this->withDistributedSharedPath($this->db->analysisJobContext($jobId));
            $context['lease'] = [
                'worker_id' => $workerId,
                'lease_token' => $token,
                'expires_at_epoch' => $expires,
                'lease_seconds' => $leaseSeconds,
            ];
            return $context;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function assertLease(
        int $jobId,
        string $workerId,
        string $leaseToken
    ): array {
        $this->ensureSchema();
        $workerId = $this->normalizeWorkerId($workerId);
        $leaseToken = trim($leaseToken);
        if ($leaseToken === '') {
            throw new \RuntimeException('lease_token_required');
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM analysis_job_leases WHERE job_id=?'
        );
        $stmt->execute([$jobId]);
        $lease = $stmt->fetch();
        if (!$lease) {
            throw new \RuntimeException('lease_not_found');
        }
        if ((string)$lease['worker_id'] !== $workerId) {
            throw new \RuntimeException('lease_worker_mismatch');
        }
        if (!hash_equals((string)$lease['lease_token'], $leaseToken)) {
            throw new \RuntimeException('lease_token_mismatch');
        }
        if ((int)$lease['expires_at_epoch'] <= time()) {
            $this->reconcileExpiredLeases();
            throw new \RuntimeException('lease_expired');
        }

        return $lease;
    }

    public function renewLease(
        int $jobId,
        string $workerId,
        string $leaseToken,
        int $leaseSeconds = 60
    ): array {
        $lease = $this->assertLease($jobId, $workerId, $leaseToken);
        $leaseSeconds = max(30, min(300, $leaseSeconds));
        $expires = time() + $leaseSeconds;
        $now = gmdate('c');

        $this->db->pdo()->prepare(
            'UPDATE analysis_job_leases
             SET heartbeat_at=?,expires_at_epoch=?
             WHERE job_id=? AND worker_id=?'
        )->execute([$now, $expires, $jobId, $workerId]);

        return [
            'job_id' => $jobId,
            'worker_id' => $workerId,
            'status' => 'lease_renewed',
            'expires_at_epoch' => $expires,
        ];
    }

    public function context(
        int $jobId,
        string $workerId,
        string $leaseToken
    ): array {
        $lease = $this->assertLease($jobId, $workerId, $leaseToken);
        $context = $this->withDistributedSharedPath($this->db->analysisJobContext($jobId));
        $context['lease'] = [
            'worker_id' => (string)$lease['worker_id'],
            'expires_at_epoch' => (int)$lease['expires_at_epoch'],
        ];
        return $context;
    }

    public function releaseLease(
        int $jobId,
        string $workerId,
        string $leaseToken
    ): void {
        $this->assertLease($jobId, $workerId, $leaseToken);
        $this->db->pdo()->prepare(
            'DELETE FROM analysis_job_leases
             WHERE job_id=? AND worker_id=?'
        )->execute([$jobId, $workerId]);
    }
}
