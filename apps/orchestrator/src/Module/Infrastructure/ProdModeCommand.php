<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ezs:prod:mode', description: 'Consulter ou modifier le mode PROD via ProdModeService')]
final class ProdModeCommand extends Command
{
    public function __construct(private readonly ProdModeService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'status | maintenance | normal', 'status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        if (!in_array($action, ['status', 'maintenance', 'normal'], true)) {
            $output->writeln('<error>EZS_PROD_MODE_INVALID_ACTION</error>');
            return Command::INVALID;
        }
        try {
            $result = $action === 'status' ? $this->service->inspect() : $this->service->apply($action);
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return $action === 'status' && ($result['gateway_online'] ?? false) !== true
                ? Command::FAILURE : Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>EZS_PROD_MODE_FAILED:' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
