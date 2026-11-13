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
    description: 'Consomme les jobs de data/orchestrator.sqlite et exécute les workers LAB / Assistant.'
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
            ->addOption('drain', null, InputOption::VALUE_NONE, 'Dépile tous les jobs pending puis quitte')
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
            $output->writeln('<comment>Consumer already running.</comment>');
            return Command::SUCCESS;
        }

        $loop = (bool) $input->getOption('loop');
        $drain = (bool) $input->getOption('drain');

        try {
            do {
                $job = $this->queueManager->claimNextPending();
                if ($job === null) {
                    if ($loop) {
                        usleep(500_000);
                        continue;
                    }
                    break;
                }

                $id = (int) $job->getId();
                $output->writeln(sprintf('<info>[Job #%d]</info> source=%s', $id, $job->getSource()));

                try {
                    $this->processJob($job, $platformRoot, $output);
                    $this->queueManager->markCompleted($id);
                    $output->writeln(sprintf('<info>[Job #%d]</info> completed', $id));
                } catch (\Throwable $e) {
                    $this->queueManager->markFailed($id);
                    $output->writeln(sprintf('<error>[Job #%d] failed: %s</error>', $id, $e->getMessage()));
                }
            } while ($loop || $drain);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return Command::SUCCESS;
    }

    private function processJob(JobQueue $job, string $platformRoot, OutputInterface $output): void
    {
        $source = $job->getSource();
        $payload = $job->getPayload();
        $type = (string) ($payload['job'] ?? $payload['type'] ?? '');

        if ($type === 'healthcheck') {
            return;
        }

        $php = is_file('H:/PHP/php-8.5-x64/php.exe') ? 'H:/PHP/php-8.5-x64/php.exe' : 'php';
        $python = is_file('H:/Python/pythoncore-3.14-64/python.exe') ? 'H:/Python/pythoncore-3.14-64/python.exe' : 'python';

        if ($source === 'assistant' && $type === 'profile.direct_research') {
            $jobId = (string) ($payload['job_id'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
                throw new \RuntimeException('invalid_assistant_job_id');
            }
            $script = $platformRoot . '/apps/ezs_assistant/runtime/profile_direct_job.py';
            $this->runSubprocess([$python, $script, '--run', $jobId], $platformRoot . '/apps/ezs_assistant', [], 600, $output);
            return;
        }

        if ($source === 'lab' && $type === 'profile') {
            $this->runSubprocess(
                [$php, $platformRoot . '/bin/console', 'lab:profile:drain'],
                $platformRoot,
                ['APP_ID' => 'ezs_lab'],
                1800,
                $output
            );
            return;
        }

        if ($source === 'lab' && in_array($type, ['stems', 'chords'], true)) {
            $labRoot = $platformRoot . '/apps/ezs_lab';
            $worker = $labRoot . '/python/worker.py';
            $dbPath = $labRoot . '/data/benchmark.sqlite';
            $deps = $platformRoot . '/var/runtime/deps';
            $runId = (int) ($payload['benchmark_run_id'] ?? $payload['scientific_run_id'] ?? 0);
            $audio = (string) ($payload['source_path'] ?? '');
            $signature = (string) ($payload['signature'] ?? 'Auto');

            if ($runId <= 0 || $audio === '') {
                throw new \RuntimeException('missing_lab_worker_parameters');
            }

            $cmd = [
                $python,
                $worker,
                '--db', $dbPath,
                '--run-id', (string) $runId,
                '--audio', $audio,
                '--signature', $signature,
                '--deps', $deps,
                '--keep-upload', '1',
            ];
            $selectionRequest = trim((string) ($payload['selection_request'] ?? ''));
            if ($type === 'chords' && $selectionRequest !== '') {
                $cmd[] = '--selection-request';
                $cmd[] = $selectionRequest;
            }

            $this->runSubprocess($cmd, $labRoot . '/python', ['EZSTUDIO_DEP_ROOT' => $deps], 3600, $output);
            return;
        }

        throw new \RuntimeException(sprintf('unsupported_job:%s/%s', $source, $type));
    }

    /** @param list<string> $command @param array<string,string> $env */
    private function runSubprocess(array $command, string $cwd, array $env, int $timeout, OutputInterface $output): void
    {
        $process = new Process($command, $cwd, $env);
        $process->setTimeout($timeout);
        $process->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('subprocess_exit_%d', $process->getExitCode() ?? -1));
        }
    }
}
