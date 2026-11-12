<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** One-shot CLI adapter to canonical FrankenPHP site lifecycle, never legacy PHP-CGI. */
#[AsCommand(name:'ezs:services',description:'État désiré et contrôle explicite des sites FrankenPHP')]
final class ServiceSupervisorCommand extends Command
{
    private const ROOT='H:/EZS_orchestrator';
    private const GROUPS=['root','dev','lab','assistant','prod'];

    protected function configure(): void
    {
        $this->addArgument('action',InputArgument::REQUIRED,'status|tick|diagnostics|start|stop|restart')
            ->addArgument('group',InputArgument::OPTIONAL,'root|dev|lab|assistant|prod');
    }

    public static function nextAttempt(int $failures,int $now): int
    {
        return $now + min(300, 30 * (2 ** min(4, max(0, $failures-1))));
    }

    public static function planned(array $desired): array
    {
        $plan=[];
        foreach (self::GROUPS as $group) {
            if ($group==='root') continue;
            if (($desired[$group]??DesiredServiceState::STOPPED)===DesiredServiceState::RUNNING) $plan[]=$group;
        }
        return $plan;
    }

    /** Only human-initiated DEV and LAB actions are delegated. ROOT owns its own watchdog. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action=(string)$input->getArgument('action');
        $group=(string)($input->getArgument('group')??'');
        if (!in_array($action,['status','tick','diagnostics','start','stop','restart'],true)) return Command::INVALID;
        if (in_array($action,['start','stop','restart'],true) && !in_array($group,self::GROUPS,true)) return Command::INVALID;
        try {
            $intent=new DesiredServiceState();
            if ($action==='status') {
                foreach ($intent->all() as $name=>$state) $output->writeln($name.'='.$state);
                return Command::SUCCESS;
            }
            if ($action==='diagnostics') {
                $output->writeln('EZS_NATIVE_FRANKENPHP_DIAGNOSTICS_USE_PLATFORM_STATUS');
                return Command::SUCCESS;
            }
            if ($action==='tick') {
                // A retired scheduled tick must not start or stop services implicitly.
                $output->writeln('<error>EZS_LEGACY_SUPERVISOR_TICK_RETIRED</error>');
                return Command::FAILURE;
            }
            if (!in_array($group,['dev','lab'],true)) {
                // ROOT has a dedicated watchdog; PROD and Assistant require their own explicit lifecycle.
                throw new \RuntimeException('EZS_GROUP_OUT_OF_SCOPE_NO_MUTATION_'.$group);
            }
            if ($action==='restart') {
                throw new \RuntimeException('EZS_RESTART_REQUIRES_SEPARATE_STOP_START');
            }
            $native=new NativeSiteLifecycle();
            // Fail before changing persisted intent when the site cannot pass preflight.
            if ($action==='start') $native->request($group,'Preflight');
            $desired=$action==='start'?DesiredServiceState::RUNNING:DesiredServiceState::STOPPED;
            $intent->set($group,$desired);
            $result=$action==='start'?$native->start($group):$native->stop($group);
            $output->writeln('EZS_NATIVE_SITE_'.strtoupper($action).'_'.strtoupper($group).'_OK requests='.count($result));
            return Command::SUCCESS;
        } catch (\Throwable $failure) {
            $output->writeln('<error>EZS_SERVICES_REFUSED '.$failure->getMessage().'</error>');
            return Command::FAILURE;
        }
    }
}
