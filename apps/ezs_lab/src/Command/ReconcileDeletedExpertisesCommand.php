<?php
declare(strict_types=1);
namespace EZSLab\Command;

use EZSLab\Service\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name:'lab:expertise:reconcile-deleted',description:'Reconcile explicitly deleted, never-started expertise jobs')]
final class ReconcileDeletedExpertisesCommand extends Command
{
    public function __construct(private readonly Database $db,private readonly HttpClientInterface $http){parent::__construct();}
    protected function configure(): void {$this->addOption('ids',null,InputOption::VALUE_REQUIRED)->addOption('apply',null,InputOption::VALUE_NONE);}
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        try {
            $raw=(string)$input->getOption('ids');
            if (!preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D',$raw)) throw new \RuntimeException('explicit_ids_required');
            $ids=array_map('intval',explode(',',$raw));
            if(count($ids)!==count(array_unique($ids))) throw new \RuntimeException('duplicate_ids');
            $token=trim((string)($_ENV['EZS_QUEUE_INTERNAL_TOKEN']??$_SERVER['EZS_QUEUE_INTERNAL_TOKEN']??getenv('EZS_QUEUE_INTERNAL_TOKEN')?:''));
            if($token==='') throw new \RuntimeException('queue_token_missing');
            $base=(string)($_ENV['ORCHESTRATOR_BASE_URL']??$_SERVER['ORCHESTRATOR_BASE_URL']??getenv('ORCHESTRATOR_BASE_URL')?:'http://orchestrator.ezstudio.local:8000');
            $pdo=$this->db->pdo();
            $pdo->beginTransaction();
            try {
                foreach($ids as $id){
                    $response=$this->http->request('GET',rtrim($base,'/').'/api/queue/'.$id.'/execution-proof',['headers'=>['X-EZS-Queue-Token'=>$token],'timeout'=>5.0]);
                    if($response->getStatusCode()!==404 || ($response->toArray(false)['error']??null)!=='global_job_not_found') throw new \RuntimeException('global_absence_not_proven_'.$id);
                    $st=$pdo->prepare("SELECT id,status,progress,scientific_run_id FROM analysis_jobs WHERE kind IN ('assistant.profile.review','assistant.stems.review','assistant.chords.review','assistant.lyrics.review') AND dispatch_owner='lab' AND json_valid(request_json)=1 AND CAST(json_extract(request_json,'$.orchestrator_queue_id') AS INTEGER)=?");
                    $st->execute([$id]); $rows=$st->fetchAll(\PDO::FETCH_ASSOC);
                    if(count($rows)!==1) throw new \RuntimeException('local_identity_ambiguous_'.$id);
                    $row=$rows[0];
                    if(!in_array($row['status'],['queued','cancelled'],true) || (int)$row['progress']!==0 || (int)$row['scientific_run_id']<1) throw new \RuntimeException('local_state_conflict_'.$id);
                    $output->writeln('MATCH global='.$id.' local='.$row['id'].' run='.$row['scientific_run_id'].' state='.$row['status']);
                    if($input->getOption('apply') && $row['status']==='queued'){
                        $u=$pdo->prepare("UPDATE analysis_jobs SET status='cancelled',error='cancelled_by_user',updated_at=? WHERE id=? AND status='queued' AND progress=0");
                        $u->execute([gmdate('c'),$row['id']]); if($u->rowCount()!==1) throw new \RuntimeException('local_cas_failed');
                    }
                }
                $pdo->commit();
            } catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            $output->writeln($input->getOption('apply')?'RECONCILE_OK':'CHECK_OK');return Command::SUCCESS;
        }catch(\Throwable $e){$output->writeln('RECONCILE_REFUSED '.get_class($e).' '.$e->getMessage());return Command::FAILURE;}
    }
}
