<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\JobQueue;
use Doctrine\ORM\EntityManagerInterface;
use PDO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class JobRetryCoordinator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueManager $queueManager,
        #[Autowire('%kernel.project_dir%/apps/ezs_lab/data/benchmark.sqlite')]
        private readonly string $labDatabasePath,
    ) {}

    /** @return array{ok: bool, reason: string, job: ?JobQueue} */
    public function retry(int $queueJobId, ?string $overrideLabDbPath = null): array
    {
        $this->entityManager->clear();
        $job = $this->entityManager->find(JobQueue::class, $queueJobId);
        if (!$job instanceof JobQueue) {
            return ['ok' => false, 'reason' => 'tache_introuvable', 'job' => null];
        }
        if ($job->getStatus() !== JobQueue::STATUS_ENDED || !$job->hasError()) {
            return ['ok' => false, 'reason' => 'tache_non_en_erreur', 'job' => $job];
        }
        if ($job->getWorkerPid() !== null && $this->queueManager->isProcessAlive($job->getWorkerPid())) {
            return ['ok' => false, 'reason' => 'processus_encore_actif', 'job' => $job];
        }
        $source = $job->getSource();
        $payload = $job->getPayload();
        $duplicate = $this->queueManager->findActiveDuplicate($source, $payload);
        if ($duplicate instanceof JobQueue && $duplicate->getId() !== $job->getId()) {
            return ['ok' => false, 'reason' => 'tache_identique_deja_active', 'job' => $job];
        }
        $dbPath = $overrideLabDbPath ?? $this->labDatabasePath;
        $localCheck = $this->rearmLocalActorState($source, $payload, $dbPath);
        if (!$localCheck['ok']) {
            return ['ok' => false, 'reason' => $localCheck['reason'], 'job' => $job];
        }
        $retried = $this->queueManager->retry($queueJobId);
        if (!$retried instanceof JobQueue) {
            return ['ok' => false, 'reason' => 'echec_rearmement_central', 'job' => $job];
        }
        return ['ok' => true, 'reason' => 'relance_acceptee', 'job' => $retried];
    }
    /** @param array<string, mixed> $payload
     *  @return array{ok: bool, reason: string} */
    private function rearmLocalActorState(string $source, array $payload, string $dbPath): array
    {
        $jobType = trim((string) ($payload['job'] ?? $payload['phase'] ?? $payload['type'] ?? ''));
        $localJobId = $source === 'lab'
            ? (int) ($payload['analysis_job_id'] ?? $payload['job_id'] ?? 0)
            : (int) ($payload['job_id'] ?? 0);
        if ($source === 'assistant' && $jobType !== 'profile.review' && $localJobId <= 0) {
            return ['ok' => true, 'reason' => 'sans_etat_local_requis'];
        }
        if ($localJobId <= 0) {
            return ['ok' => false, 'reason' => 'identifiant_metier_absent'];
        }
        if (!is_file($dbPath)) {
            return ['ok' => false, 'reason' => 'base_metier_indisponible'];
        }
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $pdo->prepare('SELECT id, status, scientific_run_id FROM analysis_jobs WHERE id = ?');
            $stmt->execute([$localJobId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $pdo->commit();
                return ['ok' => false, 'reason' => 'tache_metier_introuvable'];
            }
            $localStatus = (string) ($row['status'] ?? '');
            if (in_array($localStatus, ['completed', 'cancelled', 'running', 'cancelling'], true)) {
                $pdo->commit();
                return ['ok' => false, 'reason' => 'etat_metier_non_readmissible_' . $localStatus];
            }
            $now = gmdate('c');
            $upd = $pdo->prepare("UPDATE analysis_jobs SET status = 'queued', progress = 0, error = NULL, updated_at = ? WHERE id = ? AND status NOT IN ('completed', 'cancelled', 'running', 'cancelling')");
            $upd->execute([$now, $localJobId]);
            if ($upd->rowCount() !== 1) {
                $pdo->commit();
                return ['ok' => false, 'reason' => 'conflit_rearmement_metier'];
            }
            $runId = (int) ($row['scientific_run_id'] ?? ($payload['scientific_run_id'] ?? 0));
            if ($source === 'lab' && $runId > 0) {
                $pdo->prepare("UPDATE scientific_runs SET state = 'queued', updated_at = ? WHERE id = ? AND state IN ('failed', 'error', 'running')")
                    ->execute([$now, $runId]);
            }
            $pdo->commit();
            return ['ok' => true, 'reason' => 'etat_metier_rearme'];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'reason' => 'erreur_base_metier'];
        }
    }
}
