<?php

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

    public function claimNextPending(): ?JobQueue
    {
        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();
        try {
            $id = $conn->fetchOne(
                "SELECT id FROM job_queue WHERE status = 'pending' ORDER BY created_at ASC, id ASC LIMIT 1"
            );
            if ($id === false || $id === null) {
                $conn->commit();
                return null;
            }

            $updated = $conn->executeStatement(
                "UPDATE job_queue SET status = 'running', updated_at = :now WHERE id = :id AND status = 'pending'",
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

    public function markCompleted(int $id): ?JobQueue
    {
        return $this->transition($id, 'completed');
    }

    public function markFailed(int $id): ?JobQueue
    {
        return $this->transition($id, 'failed');
    }

    private function transition(int $id, string $status): ?JobQueue
    {
        $job = $this->entityManager->find(JobQueue::class, $id);
        if (!$job instanceof JobQueue) {
            return null;
        }
        $job->setStatus($status);
        $this->entityManager->flush();

        return $job;
    }
}
