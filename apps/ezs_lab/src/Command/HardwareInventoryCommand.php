<?php
declare(strict_types=1);

namespace EZSLab\Command;

use Shared\Hardware\InventoryRestClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Explicit on-demand REST consultation; not a new job, allocator or monitor. */
#[AsCommand(name: 'lab:hardware:inventory', description: 'Consulter l’inventaire matériel de l’Orchestrator')]
final class HardwareInventoryCommand extends Command
{
    public function __construct(private readonly InventoryRestClient $client) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $data = $this->client->read();
            $output->writeln(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            // No exception messages from network or secrets printed to console.
            $output->writeln('<error>HARDWARE_INVENTORY_UNAVAILABLE</error>');
            return Command::FAILURE;
        }
    }
}
