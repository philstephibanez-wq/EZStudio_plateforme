<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\JobQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

class QueueManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Target('job_lifecycle.state_machine')]
        private readonly WorkflowInterface $jobLifecycleStateMachine,
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
                "SELECT COUNT(*) FROM job_queue WHERE status IN ('progressing', 'cancelling')"
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

            $this->entityManager->clear();
            $job = $this->entityManager->find(JobQueue::class, (int) $id);
            if (!$job instanceof JobQueue || !$this->jobLifecycleStateMachine->can($job, 'start')) {
                $conn->commit();
                return null;
            }

            $this->jobLifecycleStateMachine->apply($job, 'start');
            $job->setProgress(0);
            $job->incrementAttempts();
            $updated = $conn->executeStatement(
                "UPDATE job_queue
                 SET status = :status,
                     progress = 0,
                     attempts = :attempts,
                     updated_at = :now
                 WHERE id = :id AND status = 'waiting'",
                [
                    'status' => $job->getStatus(),
                    'attempts' => $job->getAttempts(),
                    'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'id' => (int) $id,
                ]
            );
            $conn->commit();
            if ($updated !== 1) {
                return null;
            }

            return $job;
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
        $this->entityManager->clear();
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

    public function requestCancel(int $id): ?JobQueue
    {
        $this->entityManager->clear();
        $job = $this->entityManager->find(JobQueue::class, $id);
        if (!$job instanceof JobQueue) {
            return null;
        }
        if ($job->isTerminal() || $job->getStatus() === JobQueue::STATUS_CANCELLING) {
            return $job;
        }
        if ($this->jobLifecycleStateMachine->can($job, 'cancel')) {
            $this->jobLifecycleStateMachine->apply($job, 'cancel');
            $job->setHasError(false);
            $job->setErrorMessage('Annulé');
            $this->entityManager->flush();
            return $job;
        }
        if ($this->jobLifecycleStateMachine->can($job, 'request_cancel')) {
            $this->jobLifecycleStateMachine->apply($job, 'request_cancel');
            $job->setHasError(false);
            $job->setErrorMessage('Annulation demandée');
            $this->entityManager->flush();
            return $job;
        }
        return $job;
    }

    public function confirmCancel(int $id): ?JobQueue
    {
        $this->entityManager->clear();
        $job = $this->entityManager->find(JobQueue::class, $id);
        if (!$job instanceof JobQueue) {
            return null;
        }
        if ($this->jobLifecycleStateMachine->can($job, 'confirm_cancel')) {
            $this->jobLifecycleStateMachine->apply($job, 'confirm_cancel');
            $job->setHasError(false);
            $job->setErrorMessage('Annulé');
            $this->entityManager->flush();
        }
        return $job;
    }

    public function markEnded(int $id, bool $hasError = false, ?string $errorMessage = null): ?JobQueue
    {
        $this->entityManager->clear();
        $job = $this->entityManager->find(JobQueue::class, $id);
        if (!$job instanceof JobQueue) {
            return null;
        }
        if ($job->isTerminal()) {
            return $job;
        }
        if ($job->getStatus() === JobQueue::STATUS_CANCELLING && $this->jobLifecycleStateMachine->can($job, 'confirm_cancel')) {
            $this->jobLifecycleStateMachine->apply($job, 'confirm_cancel');
            $job->setHasError(false);
            $job->setErrorMessage('Annulé');
            $this->entityManager->flush();
            return $job;
        }
        $transition = $hasError ? 'fail' : 'complete';
        if (!$this->jobLifecycleStateMachine->can($job, $transition)) {
            return $job;
        }
        $this->jobLifecycleStateMachine->apply($job, $transition);
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

    public function markCompletedIfActive(int $id): ?JobQueue
    {
        $this->entityManager->clear();
        $this->entityManager->clear();
        $job = $this->entityManager->find(JobQueue::class, $id);
        if (!$job instanceof JobQueue) {
            return null;
        }
        if ($job->isTerminal()) {
            return $job;
        }
        return $this->markEnded($id, false, null);
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
                 WHERE status IN ('progressing', 'cancelling')",
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
             WHERE status IN ('progressing', 'cancelling')
               AND COALESCE(updated_at, created_at) <= :threshold",
            ['now' => $now, 'threshold' => $threshold]
        );
        $this->entityManager->clear();

        return (int) $count;
    }
}