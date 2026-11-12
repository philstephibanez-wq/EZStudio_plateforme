<?php

declare(strict_types=1);

namespace EZSLab\Command;

use EZSLab\Service\LocalProfileQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/** One local dispatcher. Assistant jobs remain owned by the remote Assistant worker. */
#[AsCommand(name: 'lab:profile:drain', description: 'Dépiler la queue locale PROFILE sans modifier les moteurs')]
final class LocalProfileDrainCommand extends Command
{
    public function __construct(private readonly LocalProfileQueue $queue) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = dirname(__DIR__, 2);
        $dir = $root.'/var/runtime';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('profile_drain_lock_dir_missing');
        }
        $handle = fopen($dir.'/profile-drain.lock', 'c+b');
        if ($handle === false) throw new \RuntimeException('profile_drain_lock_open_failed');
        try {
            // Bounded waiting prevents a lost wakeup at the end of another drain.
            $locked = false;
            for ($attempt = 0; $attempt < 120; ++$attempt) {
                if (flock($handle, LOCK_EX | LOCK_NB)) { $locked = true; break; }
                usleep(250000);
            }
            if (!$locked) {
                $output->writeln('<error>PROFILE_DRAIN_LOCK_TIMEOUT</error>');
                return Command::FAILURE;
            }
            $idle = 0;
            while ($idle < 8) {
                $head = $this->queue->head();
                if (!$head || $this->queue->hasActive() || (string)$head['kind'] !== 'profile') {
                    ++$idle;
                    usleep(250000);
                    continue;
                }
                $idle = 0;
                $jobId = (int)$head['id'];
                $output->writeln('PROFILE_DRAIN_START job='.$jobId);
                $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
                $child = new Process([$php, 'bin/console', 'lab:profile:once', '--job-id='.$jobId, '--apply'], $root);
                $child->setTimeout(null);
                $child->run(function ($type, $line) use ($output): void { $output->write($line); });
                if (!$child->isSuccessful()) {
                    // A claim failure can mean another owner won; do not fail unrelated jobs.
                    $output->writeln('<error>PROFILE_DRAIN_JOB_FAILED job='.$jobId.'</error>');
                    if (($this->queue->head()['id'] ?? null) == $jobId) {
                        return Command::FAILURE; // no busy-loop on an unclaimable job
                    }
                }
            }
            $output->writeln('PROFILE_DRAIN_IDLE');
            return Command::SUCCESS;
        } finally {
            if (isset($locked) && $locked) flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
