<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Windows DEV root investigation; never changes files, processes or sockets. */
#[AsCommand(name:'ezs:dev:doctor', description:'Préflight lecture seule du boot DEV (processus WMI et ports TCP)')]
final class RootDevRecoveryDoctorCommand extends Command
{
    public function __construct(private readonly WindowsProcessInventory $inventory) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root=dirname(__DIR__,3);
        $file=$root.'/runtime/v2-dev/processes.json';
        try {
            if (is_link($file)) throw new \RuntimeException('STATE_SYMLINK');
            $state=is_file($file)?json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR):null;
            if ($state!==null && !is_array($state)) throw new \RuntimeException('STATE_INVALID');
            $processes=$this->inventory->collect();
            $snap=WindowsPortOwnership::capture(['C:/Windows/System32/netstat.exe','-ano','-p','tcp']);
            if (!$snap['ok']) throw new \RuntimeException('NETSTAT_UNAVAILABLE');
            $listeners=WindowsPortOwnership::parseListeners($snap['output']);
            $result=RootDevRecoveryDecision::inspect($state,$processes,$listeners);
            $output->writeln('EZS_DEV_DOCTOR_DECISION='.$result['decision']);
            foreach($result['reasons'] as $reason) $output->writeln('EZS_DEV_DOCTOR_REASON='.$reason);
            $output->writeln('EZS_DEV_DOCTOR_READ_ONLY=YES');
            return $result['decision']==='REFUSE'?Command::FAILURE:Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('EZS_DEV_DOCTOR_DECISION=UNAVAILABLE');
            $output->writeln('EZS_DEV_DOCTOR_REASON='.$e->getMessage());
            return Command::FAILURE;
        }
    }
}
