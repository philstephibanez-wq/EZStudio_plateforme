<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure\Command;

use Ezs\Web\Module\Infrastructure\ProdFastcgiPreflight;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ezs:prod:fastcgi-preflight', description: 'Read-only PROD FastCGI candidate check')]
final class ProdFastcgiPreflightCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $root = dirname(__DIR__, 4);
            $result = ProdFastcgiPreflight::inspect($root);
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $output->writeln('<error>PROD_FASTCGI_PREFLIGHT_FAILED: '. $error->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
