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

    public function dispatch(string $source, array $payload): JobQueue
    {
        // On respecte le constructeur de JobQueue qui attend (string $source, array $payload)
        $job = new JobQueue($source, $payload);

        // Le statut et la date createdAt sont déjà initialisés dans le constructeur de l'entité

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }
}
