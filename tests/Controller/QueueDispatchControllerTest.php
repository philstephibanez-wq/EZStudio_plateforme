<?php

namespace App\Tests\Controller;

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

    public function testFunctionalDispatchClaimAndCompleteFlow(): void
    {
        $client = static::createClient();
        $this->purgeTestRows();

        // 1. Validation des erreurs HTTP 400 & 422
        $client->request('POST', '/api/queue/dispatch', [], [], ['CONTENT_TYPE' => 'application/json'], '{invalid');
        $this->assertResponseStatusCodeSame(400);

        $client->request('POST', '/api/queue/dispatch', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'source' => 'unknown_kernel',
            'payload' => ['functional_test' => true],
        ]));
        $this->assertResponseStatusCodeSame(422);

        // 2. Dispatch REST valide depuis lab (stems) et assistant (profile.direct_research)
        $client->request('POST', '/api/queue/dispatch', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'source' => 'lab',
            'payload' => ['job' => 'stems', 'functional_test' => true],
        ]));
        $this->assertResponseStatusCodeSame(201);
        $resLab = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($resLab['ok'] ?? false);
        $labQueueId = (int) ($resLab['queue_id'] ?? 0);
        $this->assertGreaterThan(0, $labQueueId);

        $client->request('POST', '/api/queue/dispatch', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'source' => 'assistant',
            'payload' => ['job' => 'profile.direct_research', 'functional_test' => true],
        ]));
        $this->assertResponseStatusCodeSame(201);
        $resAssistant = json_decode((string) $client->getResponse()->getContent(), true);
        $assistantQueueId = (int) ($resAssistant['queue_id'] ?? 0);
        $this->assertGreaterThan($labQueueId, $assistantQueueId);

        // 3. Claim FIFO puis clôture complete / fail via REST
        $client->request('POST', '/api/queue/claim');
        $this->assertResponseIsSuccessful();
        $claim1 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertEquals($labQueueId, $claim1['job']['id'] ?? null);
        $this->assertEquals('running', $claim1['job']['status'] ?? null);

        $client->request('POST', '/api/queue/' . $labQueueId . '/complete');
        $this->assertResponseIsSuccessful();
        $completeRes = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertEquals('completed', $completeRes['status'] ?? null);

        $client->request('POST', '/api/queue/claim');
        $this->assertResponseIsSuccessful();
        $claim2 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertEquals($assistantQueueId, $claim2['job']['id'] ?? null);

        $client->request('POST', '/api/queue/' . $assistantQueueId . '/fail');
        $this->assertResponseIsSuccessful();
        $failRes = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertEquals('failed', $failRes['status'] ?? null);

        $this->purgeTestRows();
    }
}