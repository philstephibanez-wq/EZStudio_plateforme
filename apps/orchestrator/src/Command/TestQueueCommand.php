<?php

namespace Ezs\Web\Command;

use App\Service\QueueManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:test-queue',
    description: 'Teste le dispatching d un job dans la queue',
)]
class TestQueueCommand extends Command
{
    private QueueManager $queueManager;

    public function __construct(QueueManager $queueManager)
    {
        parent::__construct();
        $this->queueManager = $queueManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $job = $this->queueManager->dispatch('test_queue_action', ['status' => 'ok']);
        $output->writeln('<info>Job dispatché et enregistré en BDD avec succès ! ID du job : ' . $job->getId() . '</info>');
        return Command::SUCCESS;
    }
}
