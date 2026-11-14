<?php

namespace App\Tests\Controller;

use App\Service\QueueManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class QueueDispatchControllerTest extends WebTestCase
{
    private function purgeTestRows(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            "DELETE FROM job_queue WHERE json_extract(payload, '$.functional_test') = 1"
        );
    }

    public function testFunctionalAgnosticLifecycleProgressAndCrashRecovery(): void
    {
        $client = static::createClient();
        $this->purgeTestRows();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement("UPDATE job_queue SET status = '__test_parked_' || status WHERE status IN ('waiting', 'progressing')");
        try {
        // 1. Validation des erreurs HTTP 400 & 422
        $client->request('POST', '/api/queue/dispatch', [], [], ['CONTENT_TYPE' => 'application/json'], '{invalid');
        $this->assertResponseStatusCodeSame(400);

        $client->request('POST', '/api/queue/dispatch', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'source' => 'unknown_kernel',
            'payload' => ['functional_test' => true],
        ]));
        $this->assertResponseStatusCodeSame(422);

        // 2. Dispatch REST valide depuis lab et assistant -> etat initial 'waiting'
        $client->request('POST', '/api/queue/dispatch', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'source' => 'lab',
            'payload' => ['job' => 'stems', 'functional_test' => true],
        ]));
        $this->assertResponseStatusCodeSame(201);
        $resLab = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertEquals('waiting', $resLab['status'] ?? null);
        $labQueueId = (int) ($resLab['queue_id'] ?? 0);

        $client->request('POST', '/api/queue/dispatch', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'source' => 'assistant',
            'payload' => ['job' => 'profile.direct_research', 'functional_test' => true],
        ]));
        $this->assertResponseStatusCodeSame(201);
        $resAssistant = json_decode((string) $client->getResponse()->getContent(), true);
        $assistantQueueId = (int) ($resAssistant['queue_id'] ?? 0);

        // 3. Claim FIFO -> 'progressing', verrouillage strict (1 seul job progressing a la fois)
        $client->request('POST', '/api/queue/claim');
        $this->assertResponseIsSuccessful();
        $claim1 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertEquals($labQueueId, $claim1['job']['id'] ?? null);
        $this->assertEquals('progressing', $claim1['job']['status'] ?? null);
        $this->assertEquals(0, $claim1['job']['progress'] ?? null);

        // Un second claim pendant qu'un job est progressing doit retourner null
        $client->request('POST', '/api/queue/claim');
        $claimBlocked = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('job', $claimBlocked);
        $this->assertNull($claimBlocked['job']);

        // 4. Remontee d'avancement en temps reel (0..100 %)
        $client->request('POST', '/api/queue/' . $labQueueId . '/progress', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['progress' => 42]));
        $this->assertResponseIsSuccessful();
        $progRes = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertEquals('progressing', $progRes['status'] ?? null);
        $this->assertEquals(42, $progRes['progress'] ?? null);

        // 5. Simulation d'un arret brutal FrankenPHP -> recoverInterruptedJobs() remet en 'waiting'
        /** @var QueueManager $qm */
        $qm = static::getContainer()->get(QueueManager::class);
        $recovered = $qm->recoverInterruptedJobs();
        $this->assertGreaterThanOrEqual(1, $recovered);

        // Reprise automatique du job interrompu (attempts = 2)
        $client->request('POST', '/api/queue/claim');
        $reclaimed = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertEquals($labQueueId, $reclaimed['job']['id'] ?? null);
        $this->assertEquals(2, $reclaimed['job']['attempts'] ?? null);

        // 6. Cloture 'ended' OK puis 'ended' KO avec message d'erreur
        $client->request('POST', '/api/queue/' . $labQueueId . '/complete');
        $completeRes = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertEquals('ended', $completeRes['status'] ?? null);
        $this->assertEquals(100, $completeRes['progress'] ?? null);
        $this->assertFalse($completeRes['has_error'] ?? true);

        $client->request('POST', '/api/queue/claim');
        $claim2 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertEquals($assistantQueueId, $claim2['job']['id'] ?? null);

        $client->request('POST', '/api/queue/' . $assistantQueueId . '/fail', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['error' => 'simulated_actor_error']));
        $failRes = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertEquals('ended', $failRes['status'] ?? null);
        $this->assertTrue($failRes['has_error'] ?? false);
        $this->assertEquals('simulated_actor_error', $failRes['error_message'] ?? null);

        } finally {
            $this->purgeTestRows();
            $em->getConnection()->executeStatement("UPDATE job_queue SET status = REPLACE(status, '__test_parked_', '') WHERE status LIKE '__test_parked_%'");
        }
    }

    public function testJobLifecycleCancellationFsmAndTerminalLock(): void
    {
        $client = static::createClient();
        $this->purgeTestRows();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement("UPDATE job_queue SET status = '__test_parked_' || status WHERE status IN ('waiting', 'progressing', 'cancelling')");
        try {
            // 1. Annulation directe depuis 'waiting' -> 'cancelled'
            $client->request('POST', '/api/queue/dispatch', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'source' => 'lab',
                'payload' => ['job' => 'profile', 'functional_test' => true],
            ]));
            $waitingId = (int) (json_decode((string) $client->getResponse()->getContent(), true)['queue_id'] ?? 0);

            $client->request('POST', '/api/queue/' . $waitingId . '/cancel');
            $cancelWaiting = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertEquals('cancelled', $cancelWaiting['status'] ?? null);

            // Un complete tardif sur un job 'cancelled' ne doit jamais l'ecraser
            $client->request('POST', '/api/queue/' . $waitingId . '/complete');
            $afterComplete = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertEquals('cancelled', $afterComplete['status'] ?? null);

            // 2. Annulation en cours depuis 'progressing' -> 'cancelling' -> 'cancelled'
            $client->request('POST', '/api/queue/dispatch', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'source' => 'assistant',
                'payload' => ['job' => 'profile.expertise', 'functional_test' => true],
            ]));
            $runningId = (int) (json_decode((string) $client->getResponse()->getContent(), true)['queue_id'] ?? 0);

            $client->request('POST', '/api/queue/claim');
            $claimed = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertEquals($runningId, $claimed['job']['id'] ?? null);
            $this->assertEquals('progressing', $claimed['job']['status'] ?? null);

            $client->request('POST', '/api/queue/' . $runningId . '/cancel');
            $cancellingRes = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertEquals('cancelling', $cancellingRes['status'] ?? null);

            // La cloture du worker confirme l'annulation ('cancelled') au lieu de passer a 'ended'
            $client->request('POST', '/api/queue/' . $runningId . '/complete');
            $confirmedCancel = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertEquals('cancelled', $confirmedCancel['status'] ?? null);
        } finally {
            $this->purgeTestRows();
            $em->getConnection()->executeStatement("UPDATE job_queue SET status = REPLACE(status, '__test_parked_', '') WHERE status LIKE '__test_parked_%'");
        }
    }
}