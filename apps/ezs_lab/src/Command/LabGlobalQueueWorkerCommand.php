<?php

declare(strict_types=1);

namespace EZSLab\Command;

use EZSLab\Service\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/** LAB-owned adapter for its existing Python worker; algorithms are unchanged. */
#[AsCommand(name:'lab:analysis:global-worker',description:'Execute a LAB scientific worker using LAB private configuration')]
final class LabGlobalQueueWorkerCommand extends Command
{
    public function __construct(private readonly Database $database) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('phase',null,InputOption::VALUE_REQUIRED,'stems|chords')
            ->addOption('run-id',null,InputOption::VALUE_REQUIRED)
            ->addOption('audio',null,InputOption::VALUE_REQUIRED)
            ->addOption('signature',null,InputOption::VALUE_REQUIRED,'', 'Auto')
            ->addOption('selection-request',null,InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $phase=(string)$input->getOption('phase');
        $run=filter_var($input->getOption('run-id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $audio=(string)$input->getOption('audio');
        if (!in_array($phase,['stems','chords'],true) || $run===false || $audio==='') {
            $output->writeln('<error>invalid_lab_worker_parameters</error>');return Command::FAILURE;
        }
        $labRoot=dirname(__DIR__,2);
        $platformRoot=dirname($labRoot,2);
        $python=is_file('H:/Python/pythoncore-3.14-64/python.exe')?'H:/Python/pythoncore-3.14-64/python.exe':'python';
        $args=[$python,$labRoot.'/python/worker.py','--db',$this->database->path(),
            '--run-id',(string)$run,'--audio',$audio,'--signature',(string)$input->getOption('signature'),
            '--deps',$platformRoot.'/var/runtime/deps','--keep-upload','1'];
        $selection=trim((string)($input->getOption('selection-request')??''));
        if ($phase==='chords' && $selection!=='') { $args[]='--selection-request';$args[]=$selection; }
        $proc=new Process($args,$labRoot.'/python',['EZSTUDIO_DEP_ROOT'=>$platformRoot.'/var/runtime/deps']);
        $proc->setTimeout(null); // Orchestrator owns the global 3600-second execution deadline.
        try {
            $proc->run(static function(string $type,string $data)use($output):void { $output->write($data); });
        } catch (\Throwable $e) {
            $output->writeln('<error>lab_worker_subprocess_failed</error>');
            return Command::FAILURE;
        }
        return $proc->isSuccessful()?Command::SUCCESS:Command::FAILURE;
    }
}
