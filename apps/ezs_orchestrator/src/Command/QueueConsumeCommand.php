<?php

declare(strict_types=1);

namespace Ezs\Web\Command;

use App\Entity\JobQueue;
use App\Service\QueueManager;
use App\Service\EzsFifoTrace;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'orchestrator:queue:consume',
    description: 'Ordonnanceur global agnostique : FIFO unique (pending/running/completed/failed).'
)]
final class QueueConsumeCommand extends Command
{
    public function __construct(
        private readonly QueueManager $queueManager,
        private readonly \App\Service\QueueCancellationRecovery $cancellationRecovery,
        private readonly \App\Service\QueueWaitingCancellation $waitingCancellation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('drain', null, InputOption::VALUE_NONE, 'Dépile tous les jobs en attente puis quitte')
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Reste actif en boucle de scrutation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->getApplication()?->getKernel()?->getEnvironment() === 'test' || \App\Service\QueueBarrierPaths::isTest()) {
            $output->writeln('TEST_CONSUMER_DISABLED: use isolated service tests');
            return Command::FAILURE;
        }
        $platformRoot = dirname(__DIR__, 4);
        $lockFile = $platformRoot . '/var/orchestrator_queue_consumer' . (($this->getApplication()?->getKernel()?->getEnvironment() === 'test') ? '_test' : '') . '.lock';
        if (!is_dir(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0775, true);
        }

        $lock = fopen($lockFile, 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('
<
comment>Consumer already running.
<
/comment>');
            return Command::SUCCESS;
        }

        $loop = (bool) $input->getOption('loop');
        $drain = (bool) $input->getOption('drain');

        try {
            // Explicitly stopped cancellations are reconciled under the unique consumer lock.
            // No interrupted job is automatically retried.
            do {
                if (!$this->cancellationRecovery->reconcile($platformRoot)) {
                    if (!$loop) return Command::FAILURE;
                    usleep(2_000_000);
                    continue;
                }
                if (!$this->waitingCancellation->reconcile()) {
                    if (!$loop) return Command::FAILURE;
                    usleep(2_000_000);
                    continue;
                }
                $job = $this->queueManager->claimNextWaiting();
                if ($job === null) {
                    if ($loop) {
                        usleep(500_000);
                        continue;
                    }
                    break;
                }

                $id = (int) $job->getId();
                $source = $job->getSource();
                $identity = $job->getPayload();
                EzsFifoTrace::log('E2E_GLOBAL_CLAIM', [
                    'global_job_id'=>$id,'source'=>$source,'attempt'=>(int)$job->getAttempts(),
                    'run_id'=>(int)($identity['scientific_run_id'] ?? $identity['run_id'] ?? 0),
                    'local_job_id'=>(int)($identity['analysis_job_id'] ?? $identity['job_id'] ?? 0),
                    'phase'=>(string)($identity['job'] ?? 'unknown')
                ]);
                $output->writeln(sprintf('
<
info>[Job #%d]
<
/info> progressing (actor=%s, attempt=%d)', $id, $source, $job->getAttempts()));

                try {
                    EzsFifoTrace::log('E2E_DISPATCH_START',['global_job_id'=>$id,'source'=>$source]);
                    $this->dispatchToActor($job, $platformRoot, $output);
                    EzsFifoTrace::log('E2E_DISPATCH_RETURN',['global_job_id'=>$id,'source'=>$source]);
                    $terminal = $this->queueManager->markCompletedIfActive($id);
                    if (!$terminal instanceof JobQueue || !$terminal->isTerminal()) {
                        throw new \RuntimeException('queue_success_terminal_commit_missing');
                    }
                    if ($terminal->hasError()) {
                        // A worker may already have finalized a failed job via REST.
                        // Never overwrite or announce that failure as a success.
                        throw new \RuntimeException('queue_worker_already_failed');
                    }
                    // EZS_FIFO_ACTIVE_RETRY_R1B: a successful retry releases the
                    // exact blocker. The next normal claim remains FIFO ordered.
                    $pausePath = \App\Service\QueueBarrierPaths::pause($platformRoot);
                    if (is_file($pausePath)) {
                        $pauseRaw = @file_get_contents($pausePath);
                        $pause = is_string($pauseRaw) ? json_decode($pauseRaw, true) : null;
                        if (!is_array($pause) || ($pause['schema'] ?? null) !== 'ezs.queue.pause_on_ko.v1'
                            || !is_int($pause['global_job_id'] ?? null)) {
                            throw new \RuntimeException('queue_pause_invalid_after_retry');
                        }
                        if ($pause['global_job_id'] === $id && !@unlink($pausePath)) {
                            throw new \RuntimeException('queue_pause_release_failed');
                        }
                    }
                    EzsFifoTrace::log('E2E_GLOBAL_TERMINAL',['global_job_id'=>$id,'source'=>$source,'result'=>'completed']);
                    $output->writeln(sprintf('
<
info>[Job #%d]
<
/info> ended (OK)', $id));
                } catch (\Throwable $e) {
                    $code = preg_match('/^[A-Za-z0-9_.:-]{1,95}$/D',$e->getMessage()) ? $e->getMessage() : 'exception_message_redacted';
                    EzsFifoTrace::log('E2E_GLOBAL_FAILED',['global_job_id'=>$id,'source'=>$source,'reason'=>$code,'exception'=>get_class($e)]);
                    // EZS_QUEUE_STOP_ON_KO_R1: persist a fail-closed barrier BEFORE
                    // freeing the global active slot. Never terminate the active
                    // worker; runSubprocess() has already waited for its exit.
                    $pausePath = \App\Service\QueueBarrierPaths::pause($platformRoot);
                    $pauseRecord = json_encode(['schema'=>'ezs.queue.pause_on_ko.v1',
                        'global_job_id'=>$id, 'reason'=>$code,
                        'at_utc'=>gmdate('c')], JSON_THROW_ON_ERROR);
                    if (!is_file($pausePath)) {
                        $tempPause = $pausePath . '.' . getmypid() . '.tmp';
                        if (file_put_contents($tempPause, $pauseRecord . "\n", LOCK_EX) === false
                            || !rename($tempPause, $pausePath)) {
                            throw new \RuntimeException('queue_ko_pause_persist_failed', 0, $e);
                        }
                    }
                    $terminal = $this->queueManager->markEnded($id, true, $e->getMessage());
                    if (!$terminal instanceof JobQueue || !$terminal->isTerminal()) {
                        // Fail closed: never claim another job while this one might
                        // still own the unique global running slot.
                        throw new \RuntimeException('queue_failed_terminal_commit_missing', 0, $e);
                    }
                    if (!$terminal->hasError() && $terminal->getStatus() !== JobQueue::STATUS_CANCELLED) {
                        throw new \RuntimeException('queue_failed_terminal_state_invalid', 0, $e);
                    }
                    $output->writeln(sprintf('
<
error>[Job #%d] ended (KO): %s
<
/error>', $id, $e->getMessage()));
                    // EZS_QUEUE_CONSUMER_STAY_ALIVE_ON_KO_R1C: the persistent
                    // --loop consumer must remain alive while the queue is paused.
                    // QueueManager denies all claims except an explicitly retried
                    // blocked job. A one-shot --drain exits on KO as before.
                    if (!$loop) { return Command::FAILURE; }
                }
            } while ($loop || $drain);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return Command::SUCCESS;
    }

    private function dispatchToActor(JobQueue $job, string $platformRoot, OutputInterface $output): void
    {
        $id = (int) $job->getId();
        $source = $job->getSource();
        $payload = $job->getPayload();
        $type = (string) ($payload['job'] ?? $payload['type'] ?? '');

        if ($type === 'healthcheck') {
            $this->queueManager->updateProgress($id, 100);
            return;
        }

        $php = is_file('H:/PHP/php-8.5-x64/php.exe') ? 'H:/PHP/php-8.5-x64/php.exe' : 'php';
        $python = is_file('H:/Python/pythoncore-3.14-64/python.exe') ? 'H:/Python/pythoncore-3.14-64/python.exe' : 'python';
        $internalToken = (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: '');
        $baseUrl = (string) ($_ENV['ORCHESTRATOR_BASE_URL'] ?? $_SERVER['ORCHESTRATOR_BASE_URL'] ?? 'http://orchestrator.ezstudio.local:8000');

        // Former expertise jobs must fail closed; never launch a legacy worker.
        if ($source === 'assistant' && in_array($type, ['profile.review', 'profile.direct_research'], true)) {
            throw new \RuntimeException('expertise_locale_suspendue');
        }

        if ($source === 'lab' && $type === 'profile_chatgpt') {
            $this->runSubprocess($id, [$php, $platformRoot.'/bin/console', 'lab:profile:expertise-job',
                (string) $id, (string) ($payload['song_id'] ?? 0), (string) ($payload['run_id'] ?? 0),
                (string) ($payload['prompt_revision_id'] ?? 0)], $platformRoot, [
                    'APP_ID' => 'ezs_lab', 'ORCHESTRATOR_JOB_ID' => (string) $id,
                    'ORCHESTRATOR_BASE_URL' => $baseUrl, 'EZS_QUEUE_INTERNAL_TOKEN' => $internalToken,
                ], 620, $output);
            return;
        }

        if ($source === 'lab') {
            if ($type === 'profile') {
                $analysisJobId = (int) ($payload['analysis_job_id'] ?? $payload['job_id'] ?? 0);
                $cmd = [$php, $platformRoot . '/bin/console', 'lab:profile:once', '--apply', '--orchestrator-job-id=' . $id];
                if ($analysisJobId > 0) { $cmd[] = '--job-id=' . $analysisJobId; } if ($this->getApplication()?->getKernel()?->getEnvironment() === 'test') { $cmd[] = '--env=test'; }
                $this->runSubprocess($id, $cmd, $platformRoot, ['APP_ID' => 'ezs_lab', 'ORCHESTRATOR_JOB_ID' => (string) $id, 'ORCHESTRATOR_BASE_URL' => $baseUrl, 'EZS_QUEUE_INTERNAL_TOKEN' => (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: ''), 'EZS_QUEUE_INTERNAL_TOKEN' => (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: '')], 1800, $output);
                return;
            }
            if (in_array($type, ['stems', 'chords'], true)) {
                $labRoot = $platformRoot . '/apps/ezs_lab';
                $analysisJobId = (int) ($payload['analysis_job_id'] ?? 0);
                if ($analysisJobId < 1) {
                    throw new \RuntimeException('lab_worker_job_identity_missing');
                }
                // Orchestrator knows only the LAB job identity; the LAB actor owns resource resolution.
                $cmd = [$php,$platformRoot.'/bin/console','lab:analysis:global-worker',
                    '--job-id='.$analysisJobId];
                $this->runSubprocess($id, $cmd, $platformRoot, ['APP_ID' => 'ezs_lab', 'EZSTUDIO_DEP_ROOT' => $platformRoot . '/var/runtime/deps', 'ORCHESTRATOR_JOB_ID' => (string) $id, 'ORCHESTRATOR_BASE_URL' => $baseUrl, 'EZS_QUEUE_INTERNAL_TOKEN' => (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: ''), 'EZS_QUEUE_INTERNAL_TOKEN' => (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: '')], 3600, $output);
                return;
            }
        }

        throw new \RuntimeException(sprintf('unsupported_actor_dispatch:%s', $source));
    }

    /** @param list
<
string> $command @param array
<
string,string> $env */
    private function runSubprocess(int $queueJobId, array $command, string $cwd, array $env, int $timeout, OutputInterface $output): void
    {
        $process = new Process($command, $cwd, $env);
        $process->setTimeout($timeout);
        $process->start();
        $pid = $process->getPid();
        EzsFifoTrace::log('E2E_CHILD_SPAWN',['global_job_id'=>$queueJobId,'result'=>'spawned']);
        if (is_int($pid) && $pid > 0) { $this->queueManager->attachWorkerPid($queueJobId, $pid); }
        // EZS_QUEUE_ASSISTANT_DIAGNOSTIC_R1D: keep only sanitized QUEUE_ERROR
        // fragments; the worker's raw stdout/stderr is never stored in the queue.
        $queueErrorLine = null;
        $queueLineBuffer = ['out' => '', 'err' => ''];
        $process->wait(static function (string $type, string $buffer) use ($output,$queueJobId,&$queueErrorLine,&$queueLineBuffer): void {
            $output->write($buffer);
            $channel = $type === Process::ERR ? 'err' : 'out';
            $complete = preg_split('/\r?\n/', $queueLineBuffer[$channel] . $buffer);
            $queueLineBuffer[$channel] = (string) array_pop($complete);
            // Bound the incomplete line even if a misbehaving child never emits LF.
            if (strlen($queueLineBuffer[$channel]) > 2048) { $queueLineBuffer[$channel] = ''; }
            foreach ($complete as $completeLine) {
                $safe = self::safeQueueErrorDiagnostic($completeLine);
                if ($safe !== null) { $queueErrorLine = $safe; }
            }
            foreach (preg_split('/\r?\n/', $buffer) as $line) {
                // Only stable diagnostic markers, never raw argv, paths, exception bodies or tokens.
                if (preg_match('/\b(PROFILE_[A-Z0-9_]{4,90}|(?:[A-Z]+_){1,}[A-Z0-9_]{3,90})\b/', $line, $match)) {
                    EzsFifoTrace::log('E2E_CHILD_MARKER',['global_job_id'=>$queueJobId,'marker'=>$match[1]]);
                }
            }
        });
        // The last output fragment may not end in a newline.
        foreach ($queueLineBuffer as $lastLine) {
            $safe = self::safeQueueErrorDiagnostic($lastLine);
            if ($safe !== null) { $queueErrorLine = $safe; }
        }
        $exitCode = (int) ($process->getExitCode() ?? -1);
        EzsFifoTrace::log('E2E_CHILD_EXIT',['global_job_id'=>$queueJobId,'exit_code'=>$exitCode]);
        if (!$process->isSuccessful()) {
            // Keep the canonical subprocess exit code; append only a sanitized
            // reason if the Assistant explicitly emitted a QUEUE_ERROR line.
            $reason = sprintf('subprocess_exit_%d', $exitCode);
            if ($queueErrorLine !== null) {
                EzsFifoTrace::log('E2E_CHILD_SAFE_QUEUE_ERROR', [
                    'global_job_id' => $queueJobId,
                    'exit_code' => $exitCode,
                    'diagnostic' => $queueErrorLine,
                ]);
                $reason .= ' | ' . $queueErrorLine;
            }
            throw new \RuntimeException($reason);
        }
    }

    /** Strict allowlist: diagnostic identity without raw HTTP bodies, URLs or secrets. */
    private static function safeQueueErrorDiagnostic(string $line): ?string
    {
        $line = trim($line);
        if (!preg_match('/\AQUEUE_ERROR ([A-Za-z][A-Za-z0-9_]{0,63}):[ ]*(.*)\z/D', $line, $match)) {
            return null;
        }
        $type = $match[1];
        $detail = trim($match[2]);
        // Stable worker codes; never copy arbitrary free-form exception text.
        $knownCode = preg_match('/\A(?:orchestrator_|one_shot_|lab_|queue_|assistant_|expertise_|profile_|invalid_|missing_)[a-z0-9_]{1,90}\z/D', $detail) === 1;
        $httpStatus = preg_match('/\AHTTP Error [1-5][0-9]{2}(?:: [A-Za-z ]{1,40})?\z/D', $detail) === 1;
        $safeDetail = ($knownCode || $httpStatus) && !preg_match('/[A-Za-z0-9]{24,}/', $detail)
            ? $detail : 'detail_redacted';
        return 'QUEUE_ERROR ' . $type . ': ' . $safeDetail;
    }
}
