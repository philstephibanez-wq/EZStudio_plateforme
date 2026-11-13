<?php
namespace App\Tests\Service;

use App\Service\QueueManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class QueueManagerTest extends KernelTestCase
{
    public function testDispatchCreatesJobInDatabase(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var QueueManager $queueManager */
        $queueManager = $container->get(QueueManager::class);

        $job = $queueManager->dispatch("test_regression_action", ["test_key" => "test_value"]);

        $this->assertNotNull($job->getId(), "Le job doit posséder un ID généré en BDD.");
        $this->assertEquals("pending", $job->getStatus(), "Le statut initial doit être pending.");
        $this->assertEquals("test_regression_action", $job->getSource(), "La source du job doit correspondre.");
        $this->assertEquals(["test_key" => "test_value"], $job->getPayload(), "Le payload JSON doit être correctement encodé/décodé.");
    }
}
