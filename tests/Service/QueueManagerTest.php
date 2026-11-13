<?php

namespace App\Tests\Service;

use App\Service\QueueManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class QueueManagerTest extends KernelTestCase
{
    private function purgeTestRows(EntityManagerInterface $em): void
    {
        $em->getConnection()->executeStatement(
            "DELETE FROM job_queue WHERE source IN ('test_regression_action', 'test_unit_lifecycle')"
        );
    }

    public function testDispatchCreatesJobInDatabase(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $this->purgeTestRows($em);

        /** @var QueueManager $queueManager */
        $queueManager = $container->get(QueueManager::class);

        $job = $queueManager->dispatch("test_regression_action", ["test_key" => "test_value"]);

        $this->assertNotNull($job->getId(), "Le job doit posséder un ID généré en BDD.");
        $this->assertEquals("pending", $job->getStatus(), "Le statut initial doit être pending.");
        $this->assertEquals("test_regression_action", $job->getSource(), "La source du job doit correspondre.");
        $this->assertEquals(["test_key" => "test_value"], $job->getPayload(), "Le payload JSON doit être correctement encodé/décodé.");

        $this->purgeTestRows($em);
    }

    public function testClaimAndLifecycleTransitions(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $this->purgeTestRows($em);

        /** @var QueueManager $queueManager */
        $queueManager = $container->get(QueueManager::class);

        $first = $queueManager->dispatch("test_unit_lifecycle", ["step" => 1]);
        $second = $queueManager->dispatch("test_unit_lifecycle", ["step" => 2]);

        $claimed1 = $queueManager->claimNextPending();
        $this->assertNotNull($claimed1);
        $this->assertEquals($first->getId(), $claimed1->getId());
        $this->assertEquals("running", $claimed1->getStatus());

        $completed = $queueManager->markCompleted((int) $claimed1->getId());
        $this->assertNotNull($completed);
        $this->assertEquals("completed", $completed->getStatus());

        $claimed2 = $queueManager->claimNextPending();
        $this->assertNotNull($claimed2);
        $this->assertEquals($second->getId(), $claimed2->getId());

        $failed = $queueManager->markFailed((int) $claimed2->getId());
        $this->assertNotNull($failed);
        $this->assertEquals("failed", $failed->getStatus());

        $this->assertNull($queueManager->claimNextPending());

        $this->purgeTestRows($em);
    }
}