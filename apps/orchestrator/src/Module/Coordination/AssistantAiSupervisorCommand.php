<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Opt-in supervisor. Owns only the child process it creates. No external PID killing.
 * The command must remain alive while llama-server is running.
 */
#[AsCommand(name: 'ezs:assistant-ai:serve', description: 'Superviser exclusivement le moteur IA de l’Assistant')]
final class AssistantAiSupervisorCommand extends Command
{
    public const PORT = 8720;
    public const MODEL = 'runtime/models-cache/assistant/bench/qwen2.5-1.5b-instruct-q4_k_m/qwen2.5-1.5b-instruct-q4_k_m.gguf';

    protected function configure(): void
    {
        $this->addOption('run', null, InputOption::VALUE_NONE, 'Autoriser explicitement le lancement ; absent = préflight seul');
    }

    public static function arguments(string $exe, string $model): array
    {
        return [$exe, '-m', $model, '--host', '127.0.0.1', '--port', (string) self::PORT,
            '--ctx-size', '4096', '--parallel', '1', '--gpu-layers', '0'];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = 'H:/EZStudioLab_assistant';
        $exe = $root . '/runtime/ai/bin/llama-server.exe';
        $model = $root . '/' . self::MODEL;
        if (!is_file($exe) || !is_file($model)) {
            $output->writeln('<error>ASSISTANT_AI_FILES_MISSING</error>');
            return Command::FAILURE;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            $output->writeln('<error>ASSISTANT_AI_WINDOWS_REQUIRED</error>');
            return Command::FAILURE;
        }
        if (!$input->getOption('run')) {
            $output->writeln('ASSISTANT_AI_PREFLIGHT_OK (no process started)');
            return Command::SUCCESS;
        }

        $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ezs-assistant-ai-supervisor.lock';
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('<error>ASSISTANT_AI_SUPERVISOR_ALREADY_RUNNING</error>');
            if (is_resource($lock)) {
                fclose($lock);
            }
            return Command::FAILURE;
        }

        $child = null;
        try {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . self::PORT, $errno, $errstr, 0.4);
            if (is_resource($socket)) {
                fclose($socket);
                $output->writeln('<error>ASSISTANT_AI_PORT_ALREADY_OCCUPIED</error>');
                return Command::FAILURE;
            }
            $child = new Process(self::arguments($exe, $model), $root);
            $child->setTimeout(null);
            $child->start();
            $output->writeln('ASSISTANT_AI_CHILD_STARTED pid=' . ($child->getPid() ?? 'unknown'));

            $deadline = microtime(true) + 120;
            $ready = false;
            while ($child->isRunning() && microtime(true) < $deadline) {
                if ($this->health()) {
                    $ready = true;
                    break;
                }
                usleep(400000);
            }
            if (!$ready) {
                $output->writeln('<error>ASSISTANT_AI_NOT_READY</error>');
                return Command::FAILURE;
            }
            $output->writeln('ASSISTANT_AI_READY (supervisor must remain running)');
            while ($child->isRunning()) {
                sleep(1);
            }
            $output->writeln('<error>ASSISTANT_AI_CHILD_EXITED</error>');
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>ASSISTANT_AI_START_FAILED: ' . get_class($e) . '</error>');
            return Command::FAILURE;
        } finally {
            if ($child instanceof Process && $child->isRunning()) {
                $child->stop(3);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function health(): bool
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 1, 'follow_location' => 0]]);
        $raw = @file_get_contents('http://127.0.0.1:' . self::PORT . '/health', false, $context);
        if (!is_string($raw) || strlen($raw) > 65536) {
            return false;
        }
        $data = json_decode($raw, true);
        return is_array($data) && in_array($data['status'] ?? null, ['ok', 'ready'], true);
    }
}
