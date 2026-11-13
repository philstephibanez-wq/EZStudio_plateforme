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

        $job = $queueManager->dispatch('test_regression_action', ['test_key' => 'test_value']);

        $this->assertNotNull($job->getId(), 'Le job doit posséder un ID généré en BDD.');
        $this->assertEquals('waiting', $job->getStatus(), 'Le statut initial doit être waiting.');
        $this->assertEquals(0, $job->getProgress());
        $this->assertFalse($job->hasError());
        $this->assertEquals('test_regression_action', $job->getSource());
        $this->assertEquals(['test_key' => 'test_value'], $job->getPayload());

        $this->purgeTestRows($em);
    }

    public function testClaimAndLifecycleTransitions(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $this->purgeTestRows($em);

        // Isoler temporairement les jobs reels actifs pendant le test FIFO
        $conn->executeStatement("UPDATE job_queue SET status = '__test_parked_' || status WHERE status IN ('waiting', 'progressing')");

        try {
            /** @var QueueManager $queueManager */
            $queueManager = $container->get(QueueManager::class);

            $first = $queueManager->dispatch('test_unit_lifecycle', ['step' => 1]);
            $second = $queueManager->dispatch('test_unit_lifecycle', ['step' => 2]);

            $claimed1 = $queueManager->claimNextWaiting();
            $this->assertNotNull($claimed1);
            $this->assertEquals($first->getId(), $claimed1->getId());
            $this->assertEquals('progressing', $claimed1->getStatus());
            $this->assertEquals(1, $claimed1->getAttempts());

            $prog = $queueManager->updateProgress((int) $claimed1->getId(), 55);
            $this->assertEquals(55, $prog?->getProgress());

            $completed = $queueManager->markEnded((int) $claimed1->getId(), false, null);
            $this->assertNotNull($completed);
            $this->assertEquals('ended', $completed->getStatus());
            $this->assertEquals(100, $completed->getProgress());
            $this->assertFalse($completed->hasError());

            $claimed2 = $queueManager->claimNextWaiting();
            $this->assertNotNull($claimed2);
            $this->assertEquals($second->getId(), $claimed2->getId());

            $failed = $queueManager->markEnded((int) $claimed2->getId(), true, 'boom');
            $this->assertNotNull($failed);
            $this->assertEquals('ended', $failed->getStatus());
            $this->assertTrue($failed->hasError());
            $this->assertEquals('boom', $failed->getErrorMessage());

            $this->assertNull($queueManager->claimNextWaiting());
        } finally {
            $this->purgeTestRows($em);
            $conn->executeStatement("UPDATE job_queue SET status = REPLACE(status, '__test_parked_', '') WHERE status LIKE '__test_parked_%'");
        }
    }
}