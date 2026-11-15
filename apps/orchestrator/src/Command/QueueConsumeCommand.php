<?php

declare(strict_types=1);

namespace Ezs\Web\Command;

use App\Entity\JobQueue;
use App\Service\JobRetryCoordinator;
use App\Service\QueueManager;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly JobRetryCoordinator $retryCoordinator,
        private readonly EntityManagerInterface $entityManager,
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
            $recovered = $this->queueManager->recoverInterruptedJobs();
            $reconciled = $this->reconcileDivergentRetries($platformRoot);
            if ($reconciled > 0) { $output->writeln(sprintf('<comment>[Reconcile] %d job(s) divergents reconcilies.</comment>', $reconciled)); }
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
                    $this->queueManager->markCompletedIfActive($id);
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
                $this->runSubprocess($id, [$python, '-B', '-u', $script, '--once', '--job-id', (string) $jobId, '--run-id', (string) $runId], $platformRoot . '/apps/ezs_assistant', ['ORCHESTRATOR_JOB_ID' => (string) $id, 'ORCHESTRATOR_BASE_URL' => $baseUrl, 'EZS_QUEUE_INTERNAL_TOKEN' => (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: ''), 'EZS_QUEUE_INTERNAL_TOKEN' => (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: '')], 1800, $output);
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
                $this->runSubprocess($id, $cmd, $platformRoot, ['APP_ID' => 'ezs_lab', 'EZS_LAB_DB_PATH' => (string) ($payload['lab_db_path'] ?? ''), 'ORCHESTRATOR_JOB_ID' => (string) $id, 'ORCHESTRATOR_BASE_URL' => $baseUrl, 'EZS_QUEUE_INTERNAL_TOKEN' => (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: ''), 'EZS_QUEUE_INTERNAL_TOKEN' => (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: '')], 1800, $output);
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
                $cmd = [$python, $labRoot . '/python/worker.py', '--db', ((string) ($payload['lab_db_path'] ?? '') !== '' ? (string) $payload['lab_db_path'] : ($labRoot . '/data/benchmark.sqlite')), '--run-id', (string) $runId, '--audio', $audio, '--signature', $signature, '--deps', $platformRoot . '/var/runtime/deps', '--keep-upload', '1'];
                $selectionRequest = trim((string) ($payload['selection_request'] ?? ''));
                if ($type === 'chords' && $selectionRequest !== '') { $cmd[] = '--selection-request'; $cmd[] = $selectionRequest; }
                $this->runSubprocess($id, $cmd, $labRoot . '/python', ['EZSTUDIO_DEP_ROOT' => $platformRoot . '/var/runtime/deps', 'ORCHESTRATOR_JOB_ID' => (string) $id, 'ORCHESTRATOR_BASE_URL' => $baseUrl, 'EZS_QUEUE_INTERNAL_TOKEN' => (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: ''), 'EZS_QUEUE_INTERNAL_TOKEN' => (string) ($_ENV['EZS_QUEUE_INTERNAL_TOKEN'] ?? $_SERVER['EZS_QUEUE_INTERNAL_TOKEN'] ?? getenv('EZS_QUEUE_INTERNAL_TOKEN') ?: '')], 3600, $output);
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
    private function runSubprocess(int $queueJobId, array $command, string $cwd, array $env, int $timeout, OutputInterface $output): void
    {
        $process = new Process($command, $cwd, $env);
        $process->setTimeout($timeout);
        $process->start(); $pid = $process->getPid(); if (is_int($pid) && $pid > 0) { $this->queueManager->attachWorkerPid($queueJobId, $pid); } $process->wait(static function (string $type, string $buffer) use ($output): void { $output->write($buffer); });
        if (!$process->isSuccessful()) { throw new \RuntimeException(sprintf('subprocess_exit_%d', $process->getExitCode() ?? -1)); }
    }

    private function reconcileDivergentRetries(string $platformRoot): int
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative("SELECT id, source, payload FROM job_queue WHERE status = 'ended' AND has_error = 1 ORDER BY id DESC LIMIT 50");
        $labDb = $platformRoot . '/apps/ezs_lab/data/benchmark.sqlite';
        $dirState = $platformRoot . '/apps/ezs_assistant/runtime/state/profile-direct';
        $pdo = is_file($labDb) ? new \PDO('sqlite:' . $labDb) : null;
        $count = 0;
        foreach ($rows as $r) {
            $pay = json_decode((string) ($r['payload'] ?? '{}'), true); if (!is_array($pay)) { continue; }
            $type = (string) ($pay['job'] ?? $pay['type'] ?? ''); $divergent = false;
            if ($r['source'] === 'assistant' && $type === 'profile.direct_research') {
                $jid = (string) ($pay['job_id'] ?? ''); $rf = $dirState . '/' . $jid . '.result.json';
                if (preg_match('/^[a-f0-9]{32}$/', $jid) && is_file($rf)) { $j = json_decode((string) file_get_contents($rf), true); $divergent = is_array($j) && ($j['state'] ?? '') === 'queued'; }
            } elseif ($pdo instanceof \PDO) {
                $lid = $r['source'] === 'lab' ? (int) ($pay['analysis_job_id'] ?? $pay['job_id'] ?? 0) : (int) ($pay['job_id'] ?? 0);
                if ($lid > 0) { $st = $pdo->prepare("SELECT status, progress, error FROM analysis_jobs WHERE id = ?"); $st->execute([$lid]); $jr = $st->fetch(\PDO::FETCH_ASSOC); $divergent = is_array($jr) && ($jr['status'] ?? '') === 'queued' && (int) ($jr['progress'] ?? 0) === 0 && ($jr['error'] ?? null) === null; }
            }
            if ($divergent && ($this->retryCoordinator->retry((int) $r['id'])['ok'] ?? false)) { $count++; }
        }
        return $count;
    }
}
