<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\JobQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

class QueueManager
{
    private $processAliveChecker = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Target('job_lifecycle.state_machine')]
        private readonly WorkflowInterface $jobLifecycleStateMachine,
    ) {}

    public function setProcessAliveChecker(?callable $checker): void
    {
        $this->processAliveChecker = $checker;
    }

    public function dispatch(string $source, array $payload): JobQueue
    {
        // A PROFILE expertise is identified by its LAB job AND scientific run.
        // Deduplication includes terminal jobs: a lost HTTP response must not
        // permit replay of an already completed expertise.
        if ($source === 'assistant' && ($payload['job'] ?? null) === 'profile.review') {
            $jobId = filter_var($payload['job_id'] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]);
            $runId = filter_var($payload['run_id'] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]);
            if ($jobId === false || $runId === false) {
                throw new \InvalidArgumentException('assistant_review_identity_required');
            }
            $conn = $this->entityManager->getConnection();
            // SQLite serializes the insertion. On contention an operation may
            // fail closed; no alternative job is issued on that failure.
            $conn->beginTransaction();
            try {
                $rows = $conn->fetchAllAssociative(<<<'SQL'
SELECT id,payload FROM job_queue
WHERE source='assistant'
  AND json_valid(payload)=1
  AND json_extract(payload, '$.job')='profile.review'
  AND CAST(json_extract(payload, '$.job_id') AS INTEGER)=:job_id
ORDER BY id ASC LIMIT 2
SQL, ['job_id' => (int) $jobId]);
                if (count($rows) > 1) {
                    throw new \RuntimeException('assistant_review_duplicate_history_conflict');
                }
                if ($rows !== []) {
                    $recorded = json_decode((string) $rows[0]['payload'], true);
                    if (!is_array($recorded) || (int) ($recorded['run_id'] ?? 0) !== (int) $runId) {
                        throw new \RuntimeException('assistant_review_identity_conflict');
                    }
                    $this->entityManager->clear();
                    $existing = $this->entityManager->find(JobQueue::class, (int) $rows[0]['id']);
                    if (!$existing instanceof JobQueue) {
                        throw new \RuntimeException('assistant_review_duplicate_identity_lost');
                    }
                    $conn->commit();
                    return $existing;
                }
                $job = new JobQueue($source, $payload);
                $this->entityManager->persist($job);
                $this->entityManager->flush();
                $conn->commit();
                return $job;
            } catch (\Throwable $e) {
                if ($conn->isTransactionActive()) { $conn->rollBack(); }
                throw $e;
            }
        }

        $existing = $this->findActiveDuplicate($source, $payload);
        if ($existing instanceof JobQueue) { return $existing; }
        $job = new JobQueue($source, $payload);
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        return $job;
    }

    public function findActiveDuplicate(string $source, array $payload): ?JobQueue
    {
        $jobType = trim((string) ($payload['job'] ?? $payload['phase'] ?? $payload['type'] ?? ''));
        $songId = $payload['song_id'] ?? null;
        if ($jobType === '' || $songId === null || $songId === '' || !is_scalar($songId)) { return null; }
        $conn = $this->entityManager->getConnection();
        $id = $conn->fetchOne(
            "SELECT id FROM job_queue WHERE source = :source AND status IN ('waiting', 'progressing', 'cancelling') AND COALESCE(json_extract(payload, '$.job'), json_extract(payload, '$.phase'), json_extract(payload, '$.type')) = :job_type AND CAST(json_extract(payload, '$.song_id') AS TEXT) = :song_id ORDER BY id ASC LIMIT 1",
            ['source' => $source, 'job_type' => $jobType, 'song_id' => (string) $songId]
        );
        if ($id === false || $id === null) { return null; }
        $this->entityManager->clear();
        $found = $this->entityManager->find(JobQueue::class, (int) $id);
        return $found instanceof JobQueue ? $found : null;
    }
    public function attachWorkerPid(int $id, int $pid): ?JobQueue
    {
        if ($pid <= 0) { return null; }
        $this->entityManager->clear();
        $job = $this->entityManager->find(JobQueue::class, $id);
        if (!$job instanceof JobQueue || $job->isTerminal()) { return $job instanceof JobQueue ? $job : null; }
        $job->setWorkerPid($pid);
        $this->entityManager->flush();
        return $job;
    }

    public function claimNextWaiting(): ?JobQueue
    {
        // EZS_QUEUE_STOP_ON_KO_R1: persistent pause is authoritative for GO.
        // This is checked before each claim, including after an EZS restart.
        // The global consumer lock prevents a concurrent pause/claim on MAIN.
        $pausePath = dirname(__DIR__, 2) . '/var/orchestrator_queue_paused_on_error.json';
        // EZS_FIFO_ACTIVE_RETRY_R1B: while blocked, ONLY the exact failed job
        // may be re-executed after its explicit manual retry has set waiting.
        $blockedId = null;
        if (is_file($pausePath)) {
            $rawPause = @file_get_contents($pausePath);
            $pause = is_string($rawPause) ? json_decode($rawPause, true) : null;
            if (!is_array($pause) || ($pause['schema'] ?? null) !== 'ezs.queue.pause_on_ko.v1'
                || !is_int($pause['global_job_id'] ?? null) || $pause['global_job_id'] <= 0) {
                return null; // unreadable barrier: fail closed
            }
            $blockedId = $pause['global_job_id'];
        }
        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();
        try {
            $activeCount = (int) $conn->fetchOne("SELECT COUNT(*) FROM job_queue WHERE status IN ('progressing', 'cancelling')");
            if ($activeCount > 0) { $conn->commit(); return null; }
            $id = $blockedId !== null
                ? $conn->fetchOne("SELECT id FROM job_queue WHERE id = :id AND status = 'waiting' LIMIT 1", ['id' => $blockedId])
                : $conn->fetchOne("SELECT id FROM job_queue WHERE status = 'waiting' ORDER BY created_at ASC, id ASC LIMIT 1");
            if ($id === false || $id === null) { $conn->commit(); return null; }
            $this->entityManager->clear();
            $job = $this->entityManager->find(JobQueue::class, (int) $id);
            if (!$job instanceof JobQueue || !$this->jobLifecycleStateMachine->can($job, 'start')) { $conn->commit(); return null; }
            $this->jobLifecycleStateMachine->apply($job, 'start');
            $job->setProgress(0)->setWorkerPid(null)->incrementAttempts();
            $updated = $conn->executeStatement(
                "UPDATE job_queue SET status = :status, progress = 0, worker_pid = NULL, attempts = :attempts, updated_at = :now WHERE id = :id AND status = 'waiting'",
                ['status' => $job->getStatus(), 'attempts' => $job->getAttempts(), 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => (int) $id]
            );
            $conn->commit();
            return $updated === 1 ? $job : null;
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) { $conn->rollBack(); }
            throw $e;
        }
    }
    public function claimNextPending(): ?JobQueue { return $this->claimNextWaiting(); }

    public function updateProgress(int $id, int $progress): ?JobQueue
    {
        $this->entityManager->clear();
        $job = $this->entityManager->find(JobQueue::class, $id);
        if (!$job instanceof JobQueue) { return null; }
        if ($job->getStatus() !== JobQueue::STATUS_PROGRESSING) { return $job; }
        $job->setProgress($progress);
        $this->entityManager->flush();
        return $job;
    }

    public function requestCancel(int $id): ?JobQueue
    {
        $this->entityManager->clear();
        $job = $this->entityManager->find(JobQueue::class, $id);
        if (!$job instanceof JobQueue) { return null; }
        if ($job->isTerminal() || $job->getStatus() === JobQueue::STATUS_CANCELLING) { return $job; }
        if ($this->jobLifecycleStateMachine->can($job, 'cancel')) {
            $this->jobLifecycleStateMachine->apply($job, 'cancel');
            $job->setHasError(false)->setWorkerPid(null)->setErrorMessage('AnnulÃ©');
            $this->entityManager->flush();
            return $job;
        }
        if ($this->jobLifecycleStateMachine->can($job, 'request_cancel')) {
            $this->jobLifecycleStateMachine->apply($job, 'request_cancel');
            $job->setHasError(false)->setErrorMessage('Annulation demandÃ©e');
            $this->entityManager->flush();
            return $job;
        }
        return $job;
    }
    public function confirmCancel(int $id): ?JobQueue
    {
        $this->entityManager->clear();
        $job = $this->entityManager->find(JobQueue::class, $id);
        if (!$job instanceof JobQueue) { return null; }
        $pid = $job->getWorkerPid();
        if ($pid !== null && $pid > 0 && $this->isProcessAlive($pid)) { return $job; }
        if ($this->jobLifecycleStateMachine->can($job, 'confirm_cancel')) {
            $this->jobLifecycleStateMachine->apply($job, 'confirm_cancel');
            $job->setHasError(false)->setWorkerPid(null)->setErrorMessage('AnnulÃ©');
            $this->entityManager->flush();
        }
        return $job;
    }
    public function markEnded(int $id, bool $hasError = false, ?string $errorMessage = null): ?JobQueue
    {
        $this->entityManager->clear();
        $job = $this->entityManager->find(JobQueue::class, $id);
        if (!$job instanceof JobQueue) { return null; }
        if ($job->isTerminal()) { return $job; }
        if ($job->getStatus() === JobQueue::STATUS_CANCELLING && $this->jobLifecycleStateMachine->can($job, 'confirm_cancel')) {
            $this->jobLifecycleStateMachine->apply($job, 'confirm_cancel');
            $job->setHasError(false)->setWorkerPid(null)->setErrorMessage('AnnulÃ©');
            $this->entityManager->flush();
            return $job;
        }
        $transition = $hasError ? 'fail' : 'complete';
        if (!$this->jobLifecycleStateMachine->can($job, $transition)) { return $job; }
        $this->jobLifecycleStateMachine->apply($job, $transition);
        $job->setHasError($hasError)->setWorkerPid(null);
        if ($hasError) { $job->setErrorMessage($errorMessage); } else { $job->setProgress(100)->setErrorMessage(null); }
        $this->entityManager->flush();
        return $job;
    }

    public function markCompletedIfActive(int $id): ?JobQueue { $this->entityManager->clear(); $job = $this->entityManager->find(JobQueue::class, $id); if (!$job instanceof JobQueue) { return null; } return $job->isTerminal() ? $job : $this->markEnded($id, false, null); }
    public function markCompleted(int $id): ?JobQueue { return $this->markEnded($id, false, null); }
    public function markFailed(int $id, ?string $errorMessage = null): ?JobQueue { return $this->markEnded($id, true, $errorMessage); }
    public function retry(int $id): ?JobQueue
    {
        $conn = $this->entityManager->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $updated = $conn->executeStatement(
            "UPDATE job_queue SET status = 'waiting', progress = 0, has_error = 0, error_message = NULL, worker_pid = NULL, updated_at = :now WHERE id = :id AND status = 'ended' AND has_error = 1",
            ['now' => $now, 'id' => $id]
        );
        $this->entityManager->clear();
        return $updated === 1 ? $this->entityManager->find(JobQueue::class, $id) : null;
    }

    public function delete(int $id): bool
    {
        $conn = $this->entityManager->getConnection();
        $deleted = $conn->executeStatement(
            "DELETE FROM job_queue WHERE id = :id AND status IN ('waiting', 'cancelled', 'ended')",
            ['id' => $id]
        );
        $this->entityManager->clear();
        return $deleted === 1;
    }

    public function recoverInterruptedJobs(int $staleTimeoutSeconds = 0): int
    {
        $conn = $this->entityManager->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $sql = "SELECT id, status, worker_pid FROM job_queue WHERE status IN ('progressing', 'cancelling')";
        $params = [];
        if ($staleTimeoutSeconds > 0) {
            $sql .= " AND COALESCE(updated_at, created_at) <= :threshold";
            $params['threshold'] = (new \DateTimeImmutable(sprintf('-%d seconds', $staleTimeoutSeconds)))->format('Y-m-d H:i:s');
        }
        $recovered = 0;
        foreach ($conn->fetchAllAssociative($sql, $params) as $row) {
            $id = (int) $row['id'];
            $status = (string) $row['status'];
            $pid = isset($row['worker_pid']) && $row['worker_pid'] !== null ? (int) $row['worker_pid'] : null;
            if ($pid !== null && $pid > 0 && $this->isProcessAlive($pid)) { continue; }
            if ($status === JobQueue::STATUS_CANCELLING) {
                $recovered += (int) $conn->executeStatement("UPDATE job_queue SET status = 'cancelled', worker_pid = NULL, has_error = 0, error_message = 'AnnulÃ©', updated_at = :now WHERE id = :id AND status = 'cancelling'", ['now' => $now, 'id' => $id]);
                continue;
            }
            $recovered += (int) $conn->executeStatement("UPDATE job_queue SET status = 'waiting', progress = 0, worker_pid = NULL, updated_at = :now WHERE id = :id AND status = 'progressing'", ['now' => $now, 'id' => $id]);
        }
        $this->entityManager->clear();
        return $recovered;
    }

    public function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) { return false; }
        if ($this->processAliveChecker !== null) { return (bool) ($this->processAliveChecker)($pid); }
        if (PHP_OS_FAMILY === 'Windows') {
            $output = @shell_exec(sprintf('tasklist /FI "PID eq %d" /FO CSV /NH 2>NUL', $pid));
            return is_string($output) && trim($output) !== '' && str_contains($output, sprintf('"%d"', $pid));
        }
        if (is_dir('/proc/' . $pid)) { return true; }
        return function_exists('posix_kill') && @posix_kill($pid, 0);
    }
}