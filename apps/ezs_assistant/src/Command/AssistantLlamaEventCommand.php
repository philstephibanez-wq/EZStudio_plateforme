<?php
declare(strict_types=1);

namespace EZSAssistant\Command;

use EZSAssistant\Service\AssistantLlamaLifecycle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'assistant:llama:event', description: 'Traiter une transition Windows de FrankenPHP pour Llama')]
final class AssistantLlamaEventCommand extends Command
{
    public function __construct(private readonly AssistantLlamaLifecycle $lifecycle)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->lifecycle->run() === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
