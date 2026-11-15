<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\JobRetryCoordinator;
use App\Service\QueueManager;
use Doctrine\ORM\EntityManagerInterface;
use PDO;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class JobRetrySecurityTest extends WebTestCase
{
    private function createTempLabDb(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ezs_lab_retry_test_' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE scientific_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INTEGER, item TEXT, state TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE analysis_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER, scientific_run_id INTEGER, song_id INTEGER, source_path TEXT, kind TEXT, status TEXT, progress INTEGER, request_json TEXT, error TEXT, created_at TEXT, updated_at TEXT)');
        return $path;
    }

    public function testCsrfRejectsForgedRetryAndDeleteWith403(): void
    {
        $client = static::createClient();
        $client->request('POST', '/jobs/999999/retry');
        $this->assertResponseStatusCodeSame(403);

        $client->request('POST', '/jobs/999999/retry', ['_token' => 'jeton_forge']);
        $this->assertResponseStatusCodeSame(403);

        $client->request('POST', '/jobs/999999/delete');
        $this->assertResponseStatusCodeSame(403);

        $client->request('POST', '/jobs/999999/delete', ['_token' => 'jeton_forge']);
        $this->assertResponseStatusCodeSame(403);
    }
    public function testCoordinatedRetryForLabAndAssistantAndGuards(): void
    {
        static::ensureKernelShutdown();
        static::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('PRAGMA busy_timeout = 5000');
        $qm = static::getContainer()->get(QueueManager::class);
        $coord = static::getContainer()->get(JobRetryCoordinator::class);
        $dbPath = $this->createTempLabDb();
        $pdo = new PDO('sqlite:' . $dbPath);
        $em->getConnection()->executeStatement("UPDATE job_queue SET status = '__test_parked_' || status WHERE status IN ('waiting', 'progressing', 'cancelling')");

        try {
            $pdo->exec("INSERT INTO scientific_runs (id, song_id, item, state) VALUES (10, 501, 'profile', 'failed')");
            $pdo->exec("INSERT INTO analysis_jobs (id, scientific_run_id, song_id, kind, status, progress, error) VALUES (101, 10, 501, 'profile', 'failed', 40, 'boom_lab')");
            $pdo->exec("INSERT INTO analysis_jobs (id, scientific_run_id, song_id, kind, status, progress, error) VALUES (102, 10, 501, 'assistant.profile.review', 'error', 15, 'boom_ai')");
            $pdo->exec("INSERT INTO analysis_jobs (id, scientific_run_id, song_id, kind, status, progress, error) VALUES (103, 10, 501, 'stems', 'completed', 100, NULL)");

            // 1. Relance LAB (profile) : réarme analysis_jobs #101 + scientific_runs #10 + job_queue
            $labJob = $qm->dispatch('lab', ['job' => 'profile', 'analysis_job_id' => 101, 'scientific_run_id' => 10, 'song_id' => 501, 'functional_test' => true]);
            $qm->claimNextWaiting();
            $qm->markFailed($labJob->getId(), 'subprocess_exit_1');
            $resLab = $coord->retry($labJob->getId(), $dbPath);
            $this->assertTrue($resLab['ok']);
            $this->assertSame('waiting', $resLab['job']?->getStatus());
            $this->assertSame('queued', $pdo->query('SELECT status FROM analysis_jobs WHERE id = 101')->fetchColumn());
            $this->assertSame('queued', $pdo->query('SELECT state FROM scientific_runs WHERE id = 10')->fetchColumn());

            // 2. Double clic immédiat : refusé (tache_non_en_erreur)
            $dupClick = $coord->retry($labJob->getId(), $dbPath);
            $this->assertFalse($dupClick['ok']);
            $this->assertSame('tache_non_en_erreur', $dupClick['reason']);

            $qm->claimNextWaiting(); $qm->markCompleted($labJob->getId());

            // 3. Relance Assistant (profile.review) : réarme analysis_jobs #102 + job_queue
            $aiJob = $qm->dispatch('assistant', ['job' => 'profile.review', 'job_id' => 102, 'run_id' => 10, 'functional_test' => true]);
            $qm->claimNextWaiting();
            $qm->markFailed($aiJob->getId(), 'subprocess_exit_2');
            $resAi = $coord->retry($aiJob->getId(), $dbPath);
            $this->assertTrue($resAi['ok']);
            $this->assertSame('queued', $pdo->query('SELECT status FROM analysis_jobs WHERE id = 102')->fetchColumn());

            $qm->claimNextWaiting(); $qm->markCompleted($aiJob->getId());

            // 4. Refus si l'entrée métier locale est déjà completed (#103)
            $doneJob = $qm->dispatch('lab', ['job' => 'stems', 'analysis_job_id' => 103, 'song_id' => 502, 'functional_test' => true]);
            $qm->claimNextWaiting();
            $qm->markFailed($doneJob->getId(), 'subprocess_exit_1');
            $refused = $coord->retry($doneJob->getId(), $dbPath);
            $this->assertFalse($refused['ok']);
            $this->assertSame('etat_metier_non_readmissible_completed', $refused['reason']);
        } finally {
            $em->getConnection()->executeStatement("DELETE FROM job_queue WHERE json_extract(payload, '$.functional_test') = 1");
            $em->getConnection()->executeStatement("UPDATE job_queue SET status = REPLACE(status, '__test_parked_', '') WHERE status LIKE '__test_parked_%'");
            unset($pdo);
            @unlink($dbPath);
        }
    }
}
