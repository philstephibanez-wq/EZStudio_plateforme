<?php
declare(strict_types=1);
namespace App\Tests\Controller;
use App\Service\JobRetryCoordinator;
use App\Service\QueueActionCsrfManager;
use App\Service\QueueManager;
use Doctrine\ORM\EntityManagerInterface;
use PDO;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Security\Core\User\InMemoryUser;
final class JobRetrySecurityTest extends WebTestCase {
    private function createTempLabDb(): string {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "ezs_lab_retry_test_" . bin2hex(random_bytes(4)) . ".sqlite";
        $pdo = new PDO("sqlite:" . $path); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE scientific_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INTEGER, item TEXT, state TEXT, updated_at TEXT)");
        $pdo->exec("CREATE TABLE analysis_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER, scientific_run_id INTEGER, song_id INTEGER, source_path TEXT, kind TEXT, status TEXT, progress INTEGER, request_json TEXT, error TEXT, created_at TEXT, updated_at TEXT)");
        return $path;
    }
    public function testUnauthorizedAndForbiddenAccessReturns401Or403EvenWithValidCsrf(): void {
        $client = static::createClient();
        $client->request("POST", "/jobs/999999/retry"); $this->assertResponseStatusCodeSame(401);
        $client->request("POST", "/jobs/999999/delete"); $this->assertResponseStatusCodeSame(401);
        $client->request("POST", "/jobs/999999/retry", [], [], ["PHP_AUTH_USER" => "viewer", "PHP_AUTH_PW" => "viewer_pass"]);
        $this->assertResponseStatusCodeSame(403);
        $client->request("POST", "/jobs/999999/delete", [], [], ["PHP_AUTH_USER" => "viewer", "PHP_AUTH_PW" => "viewer_pass"]);
        $this->assertResponseStatusCodeSame(403);
    }
    public function testSagaRollbackLockWhitelistAndDirectResearch(): void {
        static::ensureKernelShutdown(); static::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $qm = static::getContainer()->get(QueueManager::class);
        $lf = static::getContainer()->get(LockFactory::class);
        $coord = static::getContainer()->get(JobRetryCoordinator::class);
        $dbPath = $this->createTempLabDb(); $pdo = new PDO("sqlite:" . $dbPath);
        try {
            $pdo->exec("INSERT INTO scientific_runs (id, song_id, item, state, updated_at) VALUES (20, 601, 'profile', 'failed', '2026-11-15T08:00:00Z')");
            $pdo->exec("INSERT INTO analysis_jobs (id, scientific_run_id, song_id, kind, status, progress, request_json, error, updated_at) VALUES (201, 20, 601, 'profile', 'failed', 40, '{}', 'boom_saga', '2026-11-15T08:00:00Z')");
            $pdo->exec("INSERT INTO analysis_jobs (id, scientific_run_id, song_id, kind, status, progress, request_json, error, updated_at) VALUES (202, 20, 601, 'profile.direct_research', 'aborted', 25, '{\"intermediate_error\":\"step2\",\"partial_research_state\":\"partial\"}', 'err_dr', '2026-11-15T08:00:00Z')");
            $pdo->exec("INSERT INTO analysis_jobs (id, scientific_run_id, song_id, kind, status, progress, request_json, error, updated_at) VALUES (203, 20, 601, 'stems', 'cancelled', 10, '{}', 'user_cancel', '2026-11-15T08:00:00Z')");
            $jLock = $qm->dispatch("lab", ["job" => "profile", "analysis_job_id" => 201, "scientific_run_id" => 20, "functional_test" => true]);
            $qm->claimNextWaiting(); $qm->markFailed($jLock->getId(), "err");
            $lock = $lf->createLock("job_retry_" . $jLock->getId(), 30.0); $this->assertTrue($lock->acquire());
            try { $rLock = $coord->retry($jLock->getId(), $dbPath); $this->assertFalse($rLock["ok"]); $this->assertSame("verrou_relance_actif", $rLock["reason"]); } finally { $lock->release(); }
            $jCancel = $qm->dispatch("lab", ["job" => "stems", "analysis_job_id" => 203, "scientific_run_id" => 20, "functional_test" => true]);
            $qm->claimNextWaiting(); $qm->markFailed($jCancel->getId(), "err");
            $rCancel = $coord->retry($jCancel->getId(), $dbPath); $this->assertFalse($rCancel["ok"]); $this->assertSame("etat_metier_non_readmissible_cancelled", $rCancel["reason"]);
            $jDr = $qm->dispatch("assistant", ["job" => "profile.direct_research", "job_id" => 202, "run_id" => 20, "functional_test" => true]);
            $qm->claimNextWaiting(); $qm->markFailed($jDr->getId(), "err");
            $rDr = $coord->retry($jDr->getId(), $dbPath); $this->assertTrue($rDr["ok"]);
            $drRow = $pdo->query("SELECT status, request_json FROM analysis_jobs WHERE id = 202")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame("queued", $drRow["status"]); $this->assertStringNotContainsString("intermediate_error", (string) $drRow["request_json"]);
            $failingQm = $this->createStub(QueueManager::class); $failingQm->method("isProcessAlive")->willReturn(false); $failingQm->method("findActiveDuplicate")->willReturn(null); $failingQm->method("retry")->willThrowException(new \RuntimeException("broker_down"));
            $sagaCoord = new JobRetryCoordinator($em, $failingQm, $lf, new NullLogger(), $dbPath);
            $rSaga = $sagaCoord->retry($jLock->getId(), $dbPath); $this->assertFalse($rSaga["ok"]); $this->assertSame("echec_rearmement_central", $rSaga["reason"]);
            $restoredJob = $pdo->query("SELECT status, progress, error FROM analysis_jobs WHERE id = 201")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame("failed", $restoredJob["status"]); $this->assertSame(40, (int) $restoredJob["progress"]); $this->assertSame("boom_saga", $restoredJob["error"]);
            $this->assertSame("failed", $pdo->query("SELECT state FROM scientific_runs WHERE id = 20")->fetchColumn());
        } finally { $em->getConnection()->executeStatement("DELETE FROM job_queue"); unset($pdo); @unlink($dbPath); }
    }
    public function testEndToEndRecetteFourJobTypesAndArtifactRecovery(): void {
        static::ensureKernelShutdown(); static::bootKernel();
        $c = static::getContainer(); $em = $c->get(EntityManagerInterface::class); $qm = $c->get(QueueManager::class); $coord = $c->get(JobRetryCoordinator::class);
        $db = $this->createTempLabDb(); $sb = new PDO("sqlite:" . $db);
        $art = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "ezs_recette_" . bin2hex(random_bytes(4)); mkdir($art, 0777, true); $logs = [];
        $log = static function (string $m) use (&$logs): void { $logs[] = "[" . gmdate("Y-m-d\TH:i:s\(\Z\)") . "] " . $m; };
        try {
            $sb->exec("INSERT INTO scientific_runs (id, song_id, item, state, updated_at) VALUES (901, 77, 'profile', 'failed', 'now')");
            $sb->exec("INSERT INTO analysis_jobs (id, scientific_run_id, song_id, kind, status, progress, request_json, error, updated_at) VALUES (911, 901, 77, 'profile', 'failed', 45, '{\"song_id\":77}', 'sigkill_137', 'now'), (912, 901, 77, 'assistant.profile.review', 'error', 20, '{\"run_id\":901}', 'timeout', 'now'), (913, 901, 77, 'profile.direct_research', 'aborted', 35, '{\"run_id\":901,\"intermediate_error\":\"step2\",\"partial_research_state\":\"partial\"}', 'aborted', 'now'), (914, 901, 77, 'stems', 'failed', 60, '{\"song_id\":77}', 'demucs_crash', 'now'), (915, 901, 77, 'chords', 'stalled', 80, '{\"song_id\":77}', 'madmom_hang', 'now')");
            $part = $art . DIRECTORY_SEPARATOR . "song_77_vocals.wav.part"; file_put_contents($part, "PARTIAL_WAV");
            $cases = [["1. PROFILE (LAB)", "lab", ["job" => "profile", "analysis_job_id" => 911, "scientific_run_id" => 901, "song_id" => 77], 911], ["2a. Assistant review", "assistant", ["job" => "profile.review", "job_id" => 912, "run_id" => 901], 912], ["2b. Assistant direct (profile.direct_research)", "assistant", ["job" => "profile.direct_research", "job_id" => 913, "run_id" => 901], 913], ["3. STEMS", "studio", ["job" => "stems", "job_id" => 914, "song_id" => 77], 914], ["4. CHORDS", "studio", ["job" => "chords", "job_id" => 915, "song_id" => 77], 915]];
            foreach ($cases as [$lbl, $src, $pay, $lid]) {
                $qj = $qm->dispatch($src, $pay); $qm->claimNextWaiting(); $qm->markFailed($qj->getId(), "worker_crash");
                $log("[" . $lbl . "] Crash worker simule sur central_job=#" . $qj->getId());
                $res = $coord->retry($qj->getId(), $db); $this->assertTrue($res["ok"], $lbl);
                $log("[" . $lbl . "] Relance coordonnee OK -> status=" . $res["job"]->getStatus() . ", analysis_jobs#" . $lid . "=queued");
                $cl = $qm->claimNextWaiting(); $qm->updateProgress($cl->getId(), 50);
                if ($lid === 914 && is_file($part)) { @unlink($part); file_put_contents($art . DIRECTORY_SEPARATOR . "song_77_vocals.wav", "RIFF_VALID"); $log("[3. STEMS] Artefact partiel purge et remplace."); }
                $sb->exec("UPDATE analysis_jobs SET status='completed', progress=100 WHERE id=" . $lid);
                if ($src === "lab") { $sb->exec("UPDATE scientific_runs SET state='completed' WHERE id=901"); }
                $qm->markCompleted($cl->getId());
                $this->assertSame("completed", $sb->query("SELECT status FROM analysis_jobs WHERE id=" . $lid)->fetchColumn());
                $log("[" . $lbl . "] Reprise worker terminee -> central_job=#" . $cl->getId() . "=ended (attempts=" . $cl->getAttempts() . "), scientific_run#901 conserve.");
            }
            $this->assertSame("completed", $sb->query("SELECT state FROM scientific_runs WHERE id=901")->fetchColumn());
            $md = "# Preuve de Recette Bout en Bout - Issue #2\n\n- **Date :** " . gmdate("Y-m-d H:i:s \U\T\C") . "\n- **Isolation :** `var/orchestrator_test.sqlite` (0 residu en base prod)\n\n## Journal brut\n\n```text\n" . implode("\n", $logs) . "\n```\n";
            file_put_contents(dirname(__DIR__, 2) . "/docs/RECETTE_ISSUE_2_E2E.md", $md);
        } finally { $em->getConnection()->executeStatement("DELETE FROM job_queue"); unset($sb); @unlink($db); @unlink($art . DIRECTORY_SEPARATOR . "song_77_vocals.wav"); @rmdir($art); }
    }
}
