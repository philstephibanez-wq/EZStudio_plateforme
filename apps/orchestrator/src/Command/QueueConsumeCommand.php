<?php

declare(strict_types=1);

namespace Ezs\Web\Command;

use App\Entity\JobQueue;
use App\Service\QueueManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'orchestrator:queue:consume',
    description: 'Ordonnanceur agnostique : consomme data/orchestrator.sqlite (waiting -> progressing -> ended).'
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
            ->addOption('drain', null, InputOption::VALUE_NONE, 'Dépile tous les jobs waiting puis quitte')
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Reste actif en boucle de scrutation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $platformRoot = dirname(__DIR__, 4);
        $lockFile = $platformRoot . '/var/orchestrator_queue_consumer.lock';
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
            $recovered = $this->queueManager->recoverInterruptedJobs();
            if ($recovered > 0) {
                $output->writeln(sprintf('
<
comment>[Recovery] %d job(s) progressing remis en waiting.
<
/comment>', $recovered));
            }

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
                $output->writeln(sprintf('
<
info>[Job #%d]
<
/info> progressing (actor=%s, attempt=%d)', $id, $source, $job->getAttempts()));

                try {
                    $this->dispatchToActor($job, $platformRoot, $output);
                    $this->queueManager->markEnded($id, false, null);
                    $this->publishReports($source, $platformRoot, $output);
                    $output->writeln(sprintf('
<
info>[Job #%d]
<
/info> ended (OK)', $id));
                } catch (\Throwable $e) {
                    $this->queueManager->markEnded($id, true, $e->getMessage());
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
        $baseUrl = (string) ($_ENV['ORCHESTRATOR_BASE_URL'] ?? $_SERVER['ORCHESTRATOR_BASE_URL'] ?? 'http://ezstudio.local:8000');

        if ($source === 'assistant') {
            if ($type === 'profile.review') {
                $jobId = (int) ($payload['job_id'] ?? 0);
                $runId = (int) ($payload['run_id'] ?? 0);
                if (!($jobId > 0) || !($runId > 0)) {
                    throw new \RuntimeException('invalid_assistant_review_parameters');
                }
                $script = $platformRoot . '/apps/ezs_assistant/runtime/profile_ai_requests_worker.py';
                $this->runSubprocess([$python, '-B', '-u', $script, '--once', '--job-id', (string) $jobId, '--run-id', (string) $runId], $platformRoot . '/apps/ezs_assistant', ['ORCHESTRATOR_JOB_ID' => (string) $id, 'ORCHESTRATOR_BASE_URL' => $baseUrl], 1800, $output);
                return;
            }
            if ($type === 'profile.direct_research') {
                $jobId = (string) ($payload['job_id'] ?? '');
                if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
                    throw new \RuntimeException('invalid_assistant_job_id');
                }
                $script = $platformRoot . '/apps/ezs_assistant/runtime/profile_direct_job.py';
                $this->runSubprocess([$python, $script, '--run', $jobId], $platformRoot . '/apps/ezs_assistant', ['ORCHESTRATOR_JOB_ID' => (string) $id, 'ORCHESTRATOR_BASE_URL' => $baseUrl], 600, $output);
                return;
            }
        }

        if ($source === 'lab') {
            if ($type === 'profile') {
                $analysisJobId = (int) ($payload['analysis_job_id'] ?? $payload['job_id'] ?? 0);
                $cmd = [$php, $platformRoot . '/bin/console', 'lab:profile:once', '--apply', '--orchestrator-job-id=' . $id];
                if ($analysisJobId > 0) { $cmd[] = '--job-id=' . $analysisJobId; }
                $this->runSubprocess($cmd, $platformRoot, ['APP_ID' => 'ezs_lab', 'ORCHESTRATOR_JOB_ID' => (string) $id, 'ORCHESTRATOR_BASE_URL' => $baseUrl], 1800, $output);
                return;
            }
            if (in_array($type, ['stems', 'chords'], true)) {
                $labRoot = $platformRoot . '/apps/ezs_lab';
                $runId = (int) ($payload['benchmark_run_id'] ?? $payload['scientific_run_id'] ?? 0);
                $audio = (string) ($payload['source_path'] ?? '');
                $signature = (string) ($payload['signature'] ?? 'Auto');
                if (!($runId > 0) || $audio === '') {
                    throw new \RuntimeException('missing_lab_worker_parameters');
                }
                $cmd = [$python, $labRoot . '/python/worker.py', '--db', $labRoot . '/data/benchmark.sqlite', '--run-id', (string) $runId, '--audio', $audio, '--signature', $signature, '--deps', $platformRoot . '/var/runtime/deps', '--keep-upload', '1'];
                $selectionRequest = trim((string) ($payload['selection_request'] ?? ''));
                if ($type === 'chords' && $selectionRequest !== '') { $cmd[] = '--selection-request'; $cmd[] = $selectionRequest; }
                $this->runSubprocess($cmd, $labRoot . '/python', ['EZSTUDIO_DEP_ROOT' => $platformRoot . '/var/runtime/deps', 'ORCHESTRATOR_JOB_ID' => (string) $id, 'ORCHESTRATOR_BASE_URL' => $baseUrl], 3600, $output);
                return;
            }
        }

        throw new \RuntimeException(sprintf('unsupported_actor_dispatch:%s', $source));
    }

    private function publishReports(string $source, string $platformRoot, OutputInterface $output): void
    {
        $python = is_file('H:/Python/pythoncore-3.14-64/python.exe') ? 'H:/Python/pythoncore-3.14-64/python.exe' : 'python';
        $script = $platformRoot . '/apps/ezs_lab/scripts/publish_run_reports.py';
        if (!is_file($script)) { return; }
        $proc = new Process([$python, $script, '--producer', $source, '--limit', '5', '--state-dir', 'H:/EZStudio_Run_Reports_state'], $platformRoot . '/apps/ezs_lab');
        $proc->setTimeout(60);
        try { $proc->run(); $output->write($proc->getOutput()); } catch (\Throwable) {}
    }

    /** @param list
<
string> $command @param array
<
string,string> $env */
    private function runSubprocess(array $command, string $cwd, array $env, int $timeout, OutputInterface $output): void
    {
        $process = new Process($command, $cwd, $env);
        $process->setTimeout($timeout);
        $process->run(static function (string $type, string $buffer) use ($output): void { $output->write($buffer); });
        if (!$process->isSuccessful()) { throw new \RuntimeException(sprintf('subprocess_exit_%d', $process->getExitCode() ?? -1)); }
    }
}