<?php

declare(strict_types=1);

/** Dedicated, one-shot PHP worker launched hidden through Windows COM by the REST controller. */
$root = 'H:/EZS_orchestrator';
$id = (string)($argv[1]??'');
if (!preg_match('/^[a-f0-9]{32}$/D',$id)) exit(2);
require $root.'/vendor/autoload.php';

use Ezs\Web\Module\Infrastructure\Controller\RestSupervisionController as Controller;
use Ezs\Web\Module\Infrastructure\DesiredServiceState;

$dir = $root.'/runtime/supervision';
$file = $dir.'/rest-operation.json';
$lock = fopen($dir.'/rest-worker.lock','c+');
if (!is_resource($lock) || !flock($lock,LOCK_EX|LOCK_NB)) exit(3);
try {
    $state = Controller::readState($file);
    if (($state['id']??'') !== $id || ($state['state']??'') !== 'RUNNING') exit(4);
    $groups = $state['groups']??[];
    if (!is_array($groups) || array_diff($groups,['dev','lab','assistant','prod'])) exit(5);
    $errors=[];
    foreach ($groups as $group) {
        // Re-read current intention just before each action: STOPPED must always win.
        if ((new DesiredServiceState())->get($group) !== DesiredServiceState::RUNNING) continue;
        $log = $root.'/var/log/supervision/rest-'.$id.'-'.$group.'.log';
        $fd = fopen($log,'ab');
        if (!is_resource($fd)) { $errors[]=$group.':log_unavailable';continue; }
        $cmd=['H:/PHP/php-8.5-x64/php.exe',$root.'/bin/console','ezs:services','start',$group,'--env=dev','--no-debug'];
        $descriptors=[0=>['file','NUL','r'],1=>$fd,2=>$fd];
        $start=hrtime(true);
        try {
            $process=proc_open($cmd,$descriptors,$pipes,$root,null,['bypass_shell'=>true,'create_new_console'=>false]);
            if (!is_resource($process)) throw new RuntimeException('start_process_failed');
            $exit=proc_close($process);
            if ($exit!==0) $errors[]=$group.':exit_'.$exit;
        } catch (Throwable $e) {$errors[]=$group.':'.$e->getMessage();}
        finally { fclose($fd); }
        $state['last_group']=$group;
        $state['last_group_duration_ms']=round((hrtime(true)-$start)/1000000,2);
        Controller::writeState($file,$state);
    }
    Controller::writeState($file,['state'=>$errors===[]?'IDLE':'FAILED','id'=>$id,
        'started_at'=>$state['started_at'],'finished_at'=>time(),'groups'=>$groups,'errors'=>$errors]);
} catch (Throwable $e) {
    try { Controller::writeState($file,['state'=>'FAILED','id'=>$id,'finished_at'=>time(),'error'=>'worker_exception']); }
    catch (Throwable) {}
    exit(1);
} finally {flock($lock,LOCK_UN);fclose($lock);}
