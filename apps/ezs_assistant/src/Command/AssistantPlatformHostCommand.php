<?php
declare(strict_types=1);
namespace EZSAssistant\Command;

use EZSAssistant\Service\AssistantPlatformHost;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'assistant:platform:host', description: 'Cycle de vie natif WinSW 2 de FrankenPHP et de Llama')]
final class AssistantPlatformHostCommand extends Command
{
    public function __construct(private readonly AssistantPlatformHost $host) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('mode',null,InputOption::VALUE_REQUIRED,'start');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return match ($input->getOption('mode')) {
            'start' => $this->host->start(),
            default => Command::INVALID,
        };
    }
}
