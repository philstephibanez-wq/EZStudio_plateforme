<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\JobQueue;
use Doctrine\ORM\EntityManagerInterface;

class QueueManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function dispatch(string $source, array $payload): JobQueue
    {
        $job = new JobQueue($source, $payload);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    public function claimNextWaiting(): ?JobQueue
    {
        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();
        try {
            $activeCount = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM job_queue WHERE status = 'progressing'"
            );
            if ($activeCount > 0) {
                $conn->commit();
                return null;
            }

            $id = $conn->fetchOne(
                "SELECT id FROM job_queue WHERE status = 'waiting' ORDER BY created_at ASC, id ASC LIMIT 1"
            );
            if ($id === false || $id === null) {
                $conn->commit();
                return null;
            }

            $updated = $conn->executeStatement(
                "UPDATE job_queue
                 SET status = 'progressing',
                     progress = 0,
                     attempts = COALESCE(attempts, 0) + 1,
                     updated_at = :now
                 WHERE id = :id AND status = 'waiting'",
                ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => (int) $id]
            );
            $conn->commit();

            if ($updated !== 1) {
                return null;
            }

            $this->entityManager->clear();
            return $this->entityManager->find(JobQueue::class, (int) $id);
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public function claimNextPending(): ?JobQueue
    {
        return $this->claimNextWaiting();
    }

    public function updateProgress(int $id, int $progress): ?JobQueue
    {
        $job = $this->entityManager->find(JobQueue::class, $id);
        if (!$job instanceof JobQueue) {
            return null;
        }
        if ($job->getStatus() !== JobQueue::STATUS_PROGRESSING) {
            return $job;
        }

        $job->setProgress($progress);
        $this->entityManager->flush();

        return $job;
    }

    public function markEnded(int $id, bool $hasError = false, ?string $errorMessage = null): ?JobQueue
    {
        $job = $this->entityManager->find(JobQueue::class, $id);
        if (!$job instanceof JobQueue) {
            return null;
        }

        $job->setStatus(JobQueue::STATUS_ENDED);
        $job->setHasError($hasError);
        if ($hasError) {
            $job->setErrorMessage($errorMessage);
        } else {
            $job->setProgress(100);
            $job->setErrorMessage(null);
        }

        $this->entityManager->flush();

        return $job;
    }

    public function markCompleted(int $id): ?JobQueue
    {
        return $this->markEnded($id, false, null);
    }

    public function markFailed(int $id, ?string $errorMessage = null): ?JobQueue
    {
        return $this->markEnded($id, true, $errorMessage);
    }

    public function recoverInterruptedJobs(int $staleTimeoutSeconds = 0): int
    {
        $conn = $this->entityManager->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($staleTimeoutSeconds <= 0) {
            $count = $conn->executeStatement(
                "UPDATE job_queue
                 SET status = 'waiting',
                     progress = 0,
                     updated_at = :now
                 WHERE status = 'progressing'",
                ['now' => $now]
            );
            $this->entityManager->clear();
            return (int) $count;
        }

        $threshold = (new \DateTimeImmutable(sprintf('-%d seconds', $staleTimeoutSeconds)))->format('Y-m-d H:i:s');
        $count = $conn->executeStatement(
            "UPDATE job_queue
             SET status = 'waiting',
                 progress = 0,
                 updated_at = :now
             WHERE status = 'progressing'
               AND COALESCE(updated_at, created_at) <= :threshold",
            ['now' => $now, 'threshold' => $threshold]
        );
        $this->entityManager->clear();

        return (int) $count;
    }
}