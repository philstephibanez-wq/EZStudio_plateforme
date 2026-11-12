<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ezs:dev:cleanup-stale', description: 'Nettoyer des états DEV prouvés obsolètes, sans toucher à PROD')]
final class EcosystemLifecycleCommand extends Command
{
    public function __construct(
        private readonly EcosystemLifecycleGuard $guard,
        private readonly WindowsProcessInventory $inventory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('inspect-root', null, InputOption::VALUE_NONE, 'Diagnostiquer l’identité FastCGI DEV sans mutation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = dirname(__DIR__, 3);
        try {
            if ($input->getOption('inspect-root')) {
                $file = $root . '/runtime/v2-dev/processes.json';
                $state = is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : null;
                if (!is_array($state)) throw new \RuntimeException('EZS_DEV_ROOT_STATE_MISSING');
                $pid = (int) ($state['php_pid'] ?? 0);
                $match = null;
                foreach ($this->inventory->collect() as $p) {
                    if ($p['pid'] === $pid) { $match = $p; break; }
                }
                if ($match === null) throw new \RuntimeException('EZS_DEV_ROOT_PROCESS_NOT_FOUND');
                $verdict = EcosystemLifecycleGuard::attestRootFastcgi($state, $match);
                $output->writeln('EZS_DEV_ROOT_PID=' . $pid);
                $output->writeln('EZS_DEV_ROOT_IDENTITY=' . $verdict);
                return $verdict === 'identity_attested' ? Command::SUCCESS : Command::FAILURE;
            }
            $count = $this->guard->cleanupDevStale($root, $this->inventory);
            $output->writeln('EZS_DEV_STALE_STATES_CLEARED count=' . $count);
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>EZS_DEV_CLEANUP_REFUSED:' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
