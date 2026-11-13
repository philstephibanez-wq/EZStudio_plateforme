<?php

namespace App\Service;

use App\Entity\JobQueue;
use Doctrine\ORM\EntityManagerInterface;

class QueueManager
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function dispatch(string $jobName, ?array $payload = null): JobQueue
    {
        $job = new JobQueue();
        $job->setJobName($jobName);
        $job->setStatus("pending");
        $job->setPayload($payload ? json_encode($payload) : null);
        $job->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }
}
