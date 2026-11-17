<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\QueueManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class QueueDispatchControllerTest extends WebTestCase
{
    private function authServer(): array
    {
        $token = (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: 'ezs_test_internal_queue_token_2026');
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EZS_QUEUE_TOKEN' => $token];
    }

    private function purgeTestRows(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement("DELETE FROM job_queue WHERE json_extract(payload, '$.functional_test') = 1");
    }

    private function parkExistingActiveRows(EntityManagerInterface $em): void
    {
        $em->getConnection()->executeStatement("UPDATE job_queue SET status = '__test_parked_' || status WHERE status IN ('waiting', 'progressing', 'cancelling')");
    }

    private function restoreParkedRows(EntityManagerInterface $em): void
    {
        $em->getConnection()->executeStatement("UPDATE job_queue SET status = REPLACE(status, '__test_parked_', '') WHERE status LIKE '__test_parked_%'");
    }

    public function testRestSecurityRejectsUnauthorized(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/queue/dispatch', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['source' => 'lab', 'payload' => ['job' => 'stems', 'functional_test' => true]]));
        $this->assertResponseStatusCodeSame(401);

        $client->request('POST', '/api/queue/claim', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EZS_QUEUE_TOKEN' => 'invalid_token']);
        $this->assertResponseStatusCodeSame(401);
    }
    public function testFunctionalAgnosticLifecycleProgressAndPidGuardedRecovery(): void
    {
        $client = static::createClient();
        $headers = $this->authServer();
        $this->purgeTestRows();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->parkExistingActiveRows($em);

        try {
            $client->request('POST', '/api/queue/dispatch', [], [], $headers, '{invalid');
            $this->assertResponseStatusCodeSame(400);

            $client->request('POST', '/api/queue/dispatch', [], [], $headers, json_encode(['source' => 'unknown_kernel', 'payload' => ['functional_test' => true]]));
            $this->assertResponseStatusCodeSame(422);

            $client->request('POST', '/api/queue/dispatch', [], [], $headers, json_encode(['source' => 'lab', 'payload' => ['job' => 'stems', 'song_id' => 99001, 'functional_test' => true]]));
            $this->assertResponseStatusCodeSame(201);
            $resLab = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertEquals('waiting', $resLab['status'] ?? null);
            $this->assertFalse($resLab['deduplicated'] ?? true);
            $labQueueId = (int) ($resLab['queue_id'] ?? 0);

            // Double dispatch STEMS sur song_id=99001 -> dédupliqué (HTTP 200, même queue_id)
            $client->request('POST', '/api/queue/dispatch', [], [], $headers, json_encode(['source' => 'lab', 'payload' => ['job' => 'stems', 'song_id' => 99001, 'functional_test' => true]]));
            $this->assertResponseStatusCodeSame(200);
            $resLabDup = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertEquals($labQueueId, (int) ($resLabDup['queue_id'] ?? 0));
            $this->assertTrue($resLabDup['deduplicated'] ?? false);

            // Dispatch CHORDS sur song_id=99001 -> job distinct (HTTP 201) puis dédupliqué (HTTP 200)
            $client->request('POST', '/api/queue/dispatch', [], [], $headers, json_encode(['source' => 'lab', 'payload' => ['job' => 'chords', 'song_id' => 99001, 'functional_test' => true]]));
            $this->assertResponseStatusCodeSame(201);
            $chordsQueueId = (int) (json_decode((string) $client->getResponse()->getContent(), true)['queue_id'] ?? 0);
            $this->assertNotEquals($labQueueId, $chordsQueueId);

            // Claim + attachement PID + garde recoverInterruptedJobs()
            $client->request('POST', '/api/queue/claim', [], [], $headers);
            $claim1 = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertEquals($labQueueId, $claim1['job']['id'] ?? null);

            $client->request('POST', '/api/queue/' . $labQueueId . '/attach-pid', [], [], $headers, json_encode(['pid' => 45678]));
            $this->assertResponseIsSuccessful();

            $qm = static::getContainer()->get(QueueManager::class);
            $qm->setProcessAliveChecker(static fn(int $pid): bool => $pid === 45678);
            $this->assertSame(0, $qm->recoverInterruptedJobs());

            $qm->setProcessAliveChecker(static fn(int $pid): bool => false);
            $this->assertSame(1, $qm->recoverInterruptedJobs());

            $client->request('POST', '/api/queue/claim', [], [], $headers);
            $client->request('POST', '/api/queue/' . $labQueueId . '/complete', [], [], $headers);
            $this->assertEquals('ended', json_decode((string) $client->getResponse()->getContent(), true)['status'] ?? null);

            $client->request('POST', '/api/queue/claim', [], [], $headers);
            $client->request('POST', '/api/queue/' . $chordsQueueId . '/fail', [], [], $headers, json_encode(['error' => 'simulated_actor_error']));
            $failRes = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertEquals('ended', $failRes['status'] ?? null);
            $this->assertTrue($failRes['has_error'] ?? false);
        } finally {
            $this->purgeTestRows();
            $this->restoreParkedRows($em);
        }
    }

    public function testAssistantAutomateDispatchProgressAndSubprocessEnvContract(): void
    {
        $client = static::createClient();
        $headers = $this->authServer();
        $this->purgeTestRows();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->parkExistingActiveRows($em);

        try {
            $client->request('POST', '/api/queue/dispatch', [], [], $headers, json_encode([
                'source' => 'assistant',
                'payload' => [
                    'job' => 'profile.review',
                    'category' => 'Expertise',
                    'label' => 'Automate Regression Guard',
                    'job_id' => 99331,
                    'run_id' => 99291,
                    'functional_test' => true,
                ],
            ]));
            $this->assertResponseStatusCodeSame(201);
            $res = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertEquals('waiting', $res['status'] ?? null);
            $queueId = (int) ($res['queue_id'] ?? 0);
            $this->assertGreaterThan(0, $queueId);

            $client->request('POST', '/api/queue/claim', [], [], $headers);
            $claim = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertEquals($queueId, $claim['job']['id'] ?? null);

            $client->request('POST', '/api/queue/' . $queueId . '/progress', [], [], $headers, json_encode(['progress' => 55]));
            $this->assertResponseIsSuccessful();
            $prog = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame(55, (int) ($prog['progress'] ?? 0));

            $client->request('POST', '/api/queue/' . $queueId . '/complete', [], [], $headers);
            $this->assertResponseIsSuccessful();

            $cmdFile = dirname(__DIR__, 2) . '/apps/orchestrator/src/Command/QueueConsumeCommand.php';
            $cmdSource = (string) file_get_contents($cmdFile);
            $this->assertStringContainsString("'EZSTUDIOLAB_ASSISTANT_BASE_URL'", $cmdSource);
            $this->assertStringContainsString('lab.ezstudio.local:8000', $cmdSource);
            $this->assertStringContainsString("'EZSTUDIOLAB_ASSISTANT_TOKEN'", $cmdSource);
        } finally {
            $this->purgeTestRows();
            $this->restoreParkedRows($em);
        }
    }
}
