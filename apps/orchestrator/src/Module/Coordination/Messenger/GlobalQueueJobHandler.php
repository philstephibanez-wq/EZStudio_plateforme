<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination\Messenger;

use Ezs\Web\Module\Coordination\GlobalQueueLocation;
use PDO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;

/** Grants execution to the owning application; never invokes a scientific engine. */
#[AsMessageHandler]
final class GlobalQueueJobHandler
{
    public function __invoke(QueueJobReference $reference): void
    {
        if ($reference->producer !== 'lab') throw new \RuntimeException('producer_not_implemented');
        $path = GlobalQueueLocation::path();
        $uri = 'file:'.str_replace(['%','?','#'],['%25','%3F','%23'],str_replace('\\','/',$path)).'?mode=ro';
        $db = new PDO('sqlite:'.$uri, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only=ON');
        $stmt = $db->prepare('SELECT id,producer,external_id,kind,state,claim_generation FROM global_queue_jobs WHERE id=?');
        $stmt->execute([$reference->jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($job) || $job['state'] !== 'queued' || $job['producer'] !== $reference->producer
            || $job['kind'] !== 'profile' || (int)$job['claim_generation'] !== $reference->claimGeneration
            || preg_match('/^analysis_job:([1-9][0-9]*)$/D', (string)$job['external_id'], $match) !== 1) {
            throw new \DomainException('job_no_longer_eligible');
        }
        // No user-supplied path, shell or command: only the trusted canonical LAB CLI.
        $lab = 'H:/EZStudio_lab';
        if (!is_file($lab.'/bin/console')) throw new \RuntimeException('lab_console_missing');
        $process = new Process([PHP_BINARY, 'bin/console', 'lab:queue:execute-profile', $match[1]], $lab);
        $process->setTimeout(null);
        // LAB emits a bounded, non-sensitive state/error code. Retain output only
        // in memory so that a failed bridge is diagnosable without showing tokens.
        $process->run();
        if (!$process->isSuccessful()) {
            $result = $process->getOutput() . "\n" . $process->getErrorOutput();
            $reason = 'unclassified';
            if (preg_match('/LAB_GLOBAL_PROFILE_STATE=(waiting_gpu|failed|completed)/', $result, $m) === 1) {
                $reason = 'state_' . $m[1];
            } elseif (preg_match('/LAB_GLOBAL_PROFILE_EXECUTION_FAILED:\s*([A-Za-z0-9_\\]+)/', $result, $m) === 1) {
                $reason = 'exception_' . str_replace('\\', '_', $m[1]);
            } elseif (str_contains($result, 'PROFILE_PYTHON_UNAVAILABLE')) {
                $reason = 'python_unavailable';
            }
            throw new \RuntimeException('lab_executor_failed_rc_'.(string)$process->getExitCode().'_'.$reason);
        }
        // Producer completion/failed proof must have been committed before ack.
        $stmt->execute([$reference->jobId]);
        $after = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($after) || !in_array($after['state'], ['completed','failed'], true)) {
            throw new \RuntimeException('lab_result_not_finalized');
        }
    }
}
