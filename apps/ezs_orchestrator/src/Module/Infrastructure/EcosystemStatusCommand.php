<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ezs:ecosystem:status', description: 'Attestation DEV en lecture seule et état déclaré PROD')]
final class EcosystemStatusCommand extends Command
{
    private readonly WindowsProcessInventory $inventory;

    public function __construct(?WindowsProcessInventory $inventory = null)
    {
        $this->inventory = $inventory ?? new WindowsProcessInventory();
        parent::__construct();
    }

    /** @param array<string,mixed>|null $state @param list<array<string,mixed>> $processes */
    public static function devEvidence(?array $state, array $processes): string
    {
        if ($state === null) return 'ABSENT_UNVERIFIED';
        $pid = (int)($state['php_pid'] ?? 0);
        if ($pid <= 0) return 'INVALID';
        foreach ($processes as $process) {
            if ((int)($process['pid'] ?? 0) === $pid) {
                return EcosystemLifecycleGuard::attestRootFastcgi($state, $process);
            }
        }
        return 'RECORDED_PROCESS_NOT_FOUND';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = dirname(__DIR__, 3);
        $states = [];
        foreach (['dev','prod'] as $env) {
            $path = $root . '/runtime/v2-' . $env . '/processes.json';
            if (!is_file($path)) {
                $states[$env] = null;
                $output->writeln('EZS_' . strtoupper($env) . '_STATE=ABSENT_UNVERIFIED');
                continue;
            }
            try {
                $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data)) throw new \UnexpectedValueException('state not object');
                $states[$env] = $data;
                $output->writeln('EZS_' . strtoupper($env) . '_STATE=PRESENT_UNVERIFIED');
            } catch (\Throwable) {
                $output->writeln('<error>EZS_' . strtoupper($env) . '_STATE=INVALID</error>');
                return Command::FAILURE;
            }
        }
        try {
            $observed = $this->inventory->collect();
            $verdict = self::devEvidence($states['dev'], $observed);
            $output->writeln('EZS_DEV_FASTCGI_IDENTITY=' . $verdict);
            $output->writeln('EZS_INVENTORY_PROCESS_COUNT=' . count($observed));
            $output->writeln('EZS_PROD_ATTESTATION=NOT_IMPLEMENTED');
            $output->writeln('EZS_STATUS=READ_ONLY_WMI_OBSERVATION');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('EZS_INVENTORY=UNAVAILABLE');
            $output->writeln('EZS_DEV_FASTCGI_IDENTITY=UNVERIFIED');
            $output->writeln('EZS_PROD_ATTESTATION=NOT_IMPLEMENTED');
            $output->writeln('EZS_STATUS=READ_ONLY_INVENTORY_UNAVAILABLE');
            return Command::SUCCESS;
        }
    }
}
