<?php

declare(strict_types=1);

namespace EZSLab\Command;

use EZSLab\Service\Database;
use EZSLab\Service\LabResourceResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/** LAB-owned execution adapter. Orchestrator passes only its LAB job identity. */
#[AsCommand(name: 'lab:analysis:global-worker', description: 'Execute a LAB job admitted by the single Orchestrator FIFO')]
final class LabGlobalQueueWorkerCommand extends Command
{
    public function __construct(
        private readonly Database $database,
        private readonly LabResourceResolver $resources,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('job-id', null, InputOption::VALUE_REQUIRED, 'LAB analysis_jobs ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobId = filter_var($input->getOption('job-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($jobId === false || $jobId === null) {
            $output->writeln('<error>lab_global_worker_job_id_required</error>');
            return Command::FAILURE;
        }
        $job = $this->database->analysisJob((int) $jobId);
        if (!$job || (string) ($job['dispatch_owner'] ?? '') !== 'lab') {
            $output->writeln('<error>lab_global_worker_job_not_found_or_foreign</error>');
            return Command::FAILURE;
        }
        $kind = (string) ($job['kind'] ?? '');
        if (!in_array($kind, ['stems', 'chords_scientific'], true)) {
            $output->writeln('<error>lab_global_worker_kind_invalid</error>');
            return Command::FAILURE;
        }
        $run = $kind === 'stems' ? (int) $job['scientific_run_id'] : (int) $job['run_id'];
        if ($run < 1) {
            $output->writeln('<error>lab_global_worker_run_identity_missing</error>');
            return Command::FAILURE;
        }
        // No physical path is read from the database, global queue, or business payload.
        $audio = $this->resources->audio((string) $job['audio_sha256']);
        $request = json_decode((string) $job['request_json'], true);
        if (!is_array($request)) throw new \RuntimeException('lab_worker_request_invalid');
        $signature = trim((string) ($request['signature'] ?? $job['requested_signature'] ?? 'Auto')) ?: 'Auto';
        $labRoot = dirname(__DIR__, 2);
        $platformRoot = dirname($labRoot, 2);
        $python = trim((string) ($_ENV['EZSTUDIO_WORKER_PYTHON'] ?? $_SERVER['EZSTUDIO_WORKER_PYTHON'] ?? getenv('EZSTUDIO_WORKER_PYTHON') ?: 'python'));
        $depsRoot = trim((string) ($_ENV['EZSTUDIO_DEP_ROOT'] ?? $_SERVER['EZSTUDIO_DEP_ROOT'] ?? getenv('EZSTUDIO_DEP_ROOT') ?: ''));
        if ($depsRoot === '') $depsRoot = $platformRoot.'/var/runtime/deps';
        $args = [$python, $labRoot.'/python/worker.py', '--db', $this->database->path(),
            '--run-id', (string) $run, '--audio', $audio, '--signature', $signature,
            '--deps', $depsRoot, '--keep-upload', '1'];
        if ($kind === 'chords_scientific') {
            $selection = $this->resources->chordsRequest((int) $job['scientific_run_id']);
            if (!is_file($selection)) throw new \RuntimeException('lab_chords_request_resource_missing');
            $args[] = '--selection-request';
            $args[] = $selection;
        }
        $process = new Process($args, $labRoot.'/python', ['EZSTUDIO_DEP_ROOT' => $depsRoot]);
        $process->setTimeout(null); // The single Orchestrator FIFO owns the global timeout.
        $process->run(static function (string $type, string $data) use ($output): void {
            $output->write($data);
        });
        return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }
}
