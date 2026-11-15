<?php
declare(strict_types=1);
namespace App\Service;
use App\Entity\JobQueue;
use Doctrine\ORM\EntityManagerInterface;
use PDO;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Throwable;
final class JobRetryCoordinator {
    private const RETRYABLE_STATES = ['failed', 'error', 'aborted', 'stalled'];
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly QueueManager $queueManager, private readonly LockFactory $lockFactory, private readonly LoggerInterface $logger, #[Autowire("%kernel.project_dir%/apps/ezs_lab/data/benchmark.sqlite")] private readonly string $labDatabasePath) {}
    public function retry(int $queueJobId, ?string $overrideLabDbPath = null): array {
        $lock = $this->lockFactory->createLock("job_retry_" . $queueJobId, 30.0);
        if (!$lock->acquire()) { return ["ok" => false, "reason" => "verrou_relance_actif", "job" => null]; }
        try {
            $this->entityManager->clear();
            $job = $this->entityManager->find(JobQueue::class, $queueJobId);
            if (!$job instanceof JobQueue) { return ["ok" => false, "reason" => "tache_introuvable", "job" => null]; }
            if ($job->getStatus() !== JobQueue::STATUS_ENDED || !$job->hasError()) { return ["ok" => false, "reason" => "tache_non_en_erreur", "job" => $job]; }
            if ($job->getWorkerPid() !== null && $this->queueManager->isProcessAlive($job->getWorkerPid())) { return ["ok" => false, "reason" => "processus_encore_actif", "job" => $job]; }
            $source = $job->getSource(); $payload = $job->getPayload();
            $dup = $this->queueManager->findActiveDuplicate($source, $payload);
            if ($dup instanceof JobQueue && $dup->getId() !== $job->getId()) { return ["ok" => false, "reason" => "tache_identique_deja_active", "job" => $job]; }
            $dbPath = $overrideLabDbPath ?? $this->labDatabasePath;
            $local = $this->rearmLocalActorState($source, $payload, $dbPath);
            if (!$local["ok"]) { return ["ok" => false, "reason" => $local["reason"], "job" => $job]; }
            try {
                $retried = $this->queueManager->retry($queueJobId);
                if (!$retried instanceof JobQueue) { $this->compensateLocalActorState($local["snapshot"], $dbPath, "null"); return ["ok" => false, "reason" => "echec_rearmement_central", "job" => $job]; }
            } catch (Throwable $e) { $this->compensateLocalActorState($local["snapshot"], $dbPath, $e->getMessage()); return ["ok" => false, "reason" => "echec_rearmement_central", "job" => $job]; }
            return ["ok" => true, "reason" => "relance_acceptee", "job" => $retried];
        } finally { $lock->release(); }
    }
    private function rearmLocalActorState(string $source, array $payload, string $dbPath): array {
        $jobType = trim((string) ($payload["job"] ?? $payload["phase"] ?? $payload["type"] ?? ""));
        $validKind = match ($source) { "lab" => in_array($jobType, ["profile", "stems", "chords"], true), "assistant" => in_array($jobType, ["profile.review", "profile.direct_research"], true) || $jobType === "", "studio" => in_array($jobType, ["stems", "chords"], true), default => false };
        if (!$validKind) { return ["ok" => false, "reason" => "couple_source_kind_invalide", "snapshot" => null]; }
        $localJobId = $source === "lab" ? (int) ($payload["analysis_job_id"] ?? $payload["job_id"] ?? 0) : (int) ($payload["job_id"] ?? 0);
        if ($source === "assistant" && !in_array($jobType, ["profile.review", "profile.direct_research"], true) && $localJobId <= 0) { return ["ok" => true, "reason" => "sans_etat_local_requis", "snapshot" => null]; }
        if ($localJobId <= 0) { return ["ok" => false, "reason" => "identifiant_metier_absent", "snapshot" => null]; }
        if (!is_file($dbPath)) { return ["ok" => false, "reason" => "base_metier_indisponible", "snapshot" => null]; }
        $pdo = new PDO("sqlite:" . $dbPath); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->exec("BEGIN IMMEDIATE");
        try {
            $stmt = $pdo->prepare("SELECT * FROM analysis_jobs WHERE id = ?"); $stmt->execute([$localJobId]); $jobRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($jobRow)) { $pdo->commit(); return ["ok" => false, "reason" => "tache_metier_introuvable", "snapshot" => null]; }
            $localStatus = (string) ($jobRow["status"] ?? "");
            if (!in_array($localStatus, self::RETRYABLE_STATES, true)) { $pdo->commit(); return ["ok" => false, "reason" => "etat_metier_non_readmissible_" . $localStatus, "snapshot" => null]; }
            $runId = (int) ($jobRow["scientific_run_id"] ?? ($payload["scientific_run_id"] ?? ($payload["run_id"] ?? 0)));
            if ($source === "lab" && $runId <= 0) { $pdo->commit(); return ["ok" => false, "reason" => "scientific_run_id_manquant", "snapshot" => null]; }
            $runRow = null;
            if ($runId > 0) {
                $rs = $pdo->prepare("SELECT * FROM scientific_runs WHERE id = ?"); $rs->execute([$runId]); $fr = $rs->fetch(PDO::FETCH_ASSOC);
                if (is_array($fr)) { $runRow = $fr; $rst = (string) ($runRow["state"] ?? ""); if ($source === "lab" && !in_array($rst, self::RETRYABLE_STATES, true)) { $pdo->commit(); return ["ok" => false, "reason" => "etat_run_non_readmissible_" . $rst, "snapshot" => null]; } } elseif ($source === "lab") { $pdo->commit(); return ["ok" => false, "reason" => "scientific_run_introuvable", "snapshot" => null]; }
            }
            $snapshot = ["analysis_job" => $jobRow, "scientific_run" => $runRow]; $now = gmdate("c"); $ph = implode(", ", array_fill(0, count(self::RETRYABLE_STATES), "?"));
            $upd = $pdo->prepare("UPDATE analysis_jobs SET status = 'queued', progress = 0, error = NULL, updated_at = ? WHERE id = ? AND status IN ($ph)");
            $upd->execute([$now, $localJobId, ...self::RETRYABLE_STATES]);
            if ($upd->rowCount() !== 1) { $pdo->commit(); return ["ok" => false, "reason" => "conflit_rearmement_metier", "snapshot" => null]; }
            if ($source === "lab" && $runRow !== null) { $pdo->prepare("UPDATE scientific_runs SET state = 'queued', updated_at = ? WHERE id = ? AND state IN ($ph)")->execute([$now, $runId, ...self::RETRYABLE_STATES]); }
            if ($source === "assistant" && $jobType === "profile.direct_research") { $req = json_decode((string) ($jobRow["request_json"] ?? "{}"), true); if (is_array($req)) { unset($req["intermediate_error"], $req["partial_research_state"]); $req["rearmed_at"] = $now; $pdo->prepare("UPDATE analysis_jobs SET request_json = ? WHERE id = ?")->execute([json_encode($req, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $localJobId]); } }
            $pdo->commit(); return ["ok" => true, "reason" => "etat_metier_rearme", "snapshot" => $snapshot];
        } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } return ["ok" => false, "reason" => "erreur_base_metier", "snapshot" => null]; }
    }
    private function compensateLocalActorState(?array $snapshot, string $dbPath, string $cause): void {
        if ($snapshot === null || !is_file($dbPath)) { return; }
        $this->logger->error("Rollback compensatoire SQLite suite a echec QueueManager::retry().", ["cause" => $cause]);
        try {
            $pdo = new PDO("sqlite:" . $dbPath); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->exec("BEGIN IMMEDIATE");
            if (is_array($snapshot["analysis_job"] ?? null)) { $j = $snapshot["analysis_job"]; $pdo->prepare("UPDATE analysis_jobs SET status = ?, progress = ?, error = ?, request_json = ?, updated_at = ? WHERE id = ?")->execute([$j["status"], $j["progress"], $j["error"], $j["request_json"] ?? null, $j["updated_at"], $j["id"]]); }
            if (is_array($snapshot["scientific_run"] ?? null)) { $r = $snapshot["scientific_run"]; $pdo->prepare("UPDATE scientific_runs SET state = ?, updated_at = ? WHERE id = ?")->execute([$r["state"], $r["updated_at"], $r["id"]]); }
            $pdo->commit();
        } catch (Throwable $ce) { if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); } $this->logger->critical("Echec critique rollback SQLite.", ["error" => $ce->getMessage()]); }
    }
}
