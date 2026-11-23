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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('drain', null, InputOption::VALUE_NONE, 'DÃƒÂ©pile tous les jobs waiting puis quitte')
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Reste actif en boucle de scrutation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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
            // EZS_FIFO_NO_AUTORETRY_R1: no implicit recovery/retry at consumer startup.
            // Failed or interrupted jobs require explicit operator action.

            do {
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
                    EzsFifoTrace::log('E2E_GLOBAL_TERMINAL',['global_job_id'=>$id,'source'=>$source,'result'=>'completed']);
                    $output->writeln(sprintf('
<
info>[Job #%d]
<
/info> ended (OK)', $id));
                } catch (\Throwable $e) {
                    $code = preg_match('/^[A-Za-z0-9_.:-]{1,95}$/D',$e->getMessage()) ? $e->getMessage() : 'exception_message_redacted';
                    EzsFifoTrace::log('E2E_GLOBAL_FAILED',['global_job_id'=>$id,'source'=>$source,'reason'=>$code,'exception'=>get_class($e)]);
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

        if ($source === 'assistant') {
            if ($type === 'profile.review') {
                $jobId = (int) ($payload['job_id'] ?? 0);
                $runId = (int) ($payload['run_id'] ?? 0);
                if (!($jobId > 0) || !($runId > 0)) {
                    throw new \RuntimeException('invalid_assistant_review_parameters');
                }
                $script = $platformRoot . '/apps/ezs_assistant/runtime/profile_ai_requests_worker.py';
                $labTok = (string) ($_ENV['EZSTUDIOLAB_ASSISTANT_TOKEN'] ?? $_SERVER['EZSTUDIOLAB_ASSISTANT_TOKEN'] ?? getenv('EZSTUDIOLAB_ASSISTANT_TOKEN') ?: '');
                if ($labTok === '' && is_file($platformRoot . '/apps/ezs_lab/.env.local')) {
                    foreach (file($platformRoot . '/apps/ezs_lab/.env.local', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
                        if (str_starts_with($ln, 'EZSTUDIO_ANALYSIS_WORKER_TOKEN=')) {
                            $labTok = trim(substr($ln, 31), " \t\n\r\0\x0B\"'");
                            break;
                        }
                    }
                }
                $this->runSubprocess($id, [$python, '-B', '-u', $script, '--once', '--job-id', (string) $jobId, '--run-id', (string) $runId], $platformRoot . '/apps/ezs_assistant', ['ORCHESTRATOR_JOB_ID' => (string) $id, 'ORCHESTRATOR_BASE_URL' => $baseUrl, 'EZSTUDIOLAB_ASSISTANT_BASE_URL' => (string) ($_ENV['EZSTUDIOLAB_ASSISTANT_BASE_URL'] ?? $_SERVER['EZSTUDIOLAB_ASSISTANT_BASE_URL'] ?? getenv('EZSTUDIOLAB_ASSISTANT_BASE_URL') ?: 'http://lab.ezstudio.local:8000'), 'EZSTUDIOLAB_ASSISTANT_TOKEN' => $labTok, 'EZS_QUEUE_INTERNAL_TOKEN' => (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: ''), 'EZS_QUEUE_INTERNAL_TOKEN' => (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: '')], 1800, $output);
                return;
            }
            if ($type === 'profile.direct_research') {
                $jobId = (string) ($payload['job_id'] ?? '');
                if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
                    throw new \RuntimeException('invalid_assistant_job_id');
                }
                $script = $platformRoot . '/apps/ezs_assistant/runtime/profile_direct_job.py';
                $this->runSubprocess($id, [$python, $script, '--run', $jobId], $platformRoot . '/apps/ezs_assistant', ['ORCHESTRATOR_JOB_ID' => (string) $id, 'ORCHESTRATOR_BASE_URL' => $baseUrl, 'EZS_QUEUE_INTERNAL_TOKEN' => (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: ''), 'EZS_QUEUE_INTERNAL_TOKEN' => (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: '')], 600, $output);
                return;
            }
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
        $process->wait(static function (string $type, string $buffer) use ($output,$queueJobId): void {
            $output->write($buffer);
            foreach (preg_split('/\r?\n/', $buffer) as $line) {
                // Only stable diagnostic markers, never raw argv, paths, exception bodies or tokens.
                if (preg_match('/\b(PROFILE_[A-Z0-9_]{4,90}|(?:[A-Z]+_){1,}[A-Z0-9_]{3,90})\b/', $line, $match)) {
                    EzsFifoTrace::log('E2E_CHILD_MARKER',['global_job_id'=>$queueJobId,'marker'=>$match[1]]);
                }
            }
        });
        EzsFifoTrace::log('E2E_CHILD_EXIT',['global_job_id'=>$queueJobId,'exit_code'=>(int)($process->getExitCode() ?? -1)]);
        if (!$process->isSuccessful()) { throw new \RuntimeException(sprintf('subprocess_exit_%d', $process->getExitCode() ?? -1)); }
    }
}
