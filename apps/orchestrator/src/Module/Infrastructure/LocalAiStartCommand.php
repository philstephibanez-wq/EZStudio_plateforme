<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'ezs:ai:start',description:'Démarrer ou adopter le serveur Qwen de l\'Assistant sans affecter les six services DEV')]
final class LocalAiStartCommand extends Command
{
    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        try {
            $result=(new LocalAiLifecycle())->start();
            $output->writeln('EZS_AI_START_OWNED_READY_8720 PID='.$result['pid'].' ACTION='.$result['action']);
            return Command::SUCCESS;
        } catch(\Throwable $e){
            $output->writeln('<error>EZS_AI_START_REFUSED:'.$e->getMessage().'</error>');
            return Command::FAILURE;
        }
    }
}
