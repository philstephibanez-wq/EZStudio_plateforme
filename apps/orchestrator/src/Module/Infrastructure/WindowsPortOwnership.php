<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

/** Windows process ownership diagnostics. Read-only; no PowerShell or CMD wrappers. */
final class WindowsPortOwnership
{
    public static function capture(array $command): array
    {
        $pipes = [];
        $p = @proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, null,
            PHP_OS_FAMILY === 'Windows' ? ['bypass_shell'=>true,'create_new_console'=>false] : []);
        if (!is_resource($p)) return ['ok'=>false,'output'=>''];
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($p);
        return ['ok'=>$exit===0,'output'=>(string)$output,'error'=>(string)$error];
    }

    public static function parseListeners(string $output): array
    {
        $found = [];
        foreach (preg_split('/\r?\n/', $output) as $line) {
            if (preg_match('/^\s*TCP\s+127\.0\.0\.1:(\d+)\s+\S+\s+LISTENING\s+(\d+)\s*$/i', $line, $m)) {
                $port=(int)$m[1];$pid=(int)$m[2];
                $found[$port][$pid]=true;
            }
        }
        return array_map(static fn(array $items): array => array_map('intval',array_keys($items)), $found);
    }

    public static function parseTasks(string $output): array
    {
        $tasks=[];
        foreach (preg_split('/\r?\n/', $output) as $line) {
            if (trim($line)==='')continue;
            $row=str_getcsv($line, ',', '"', '');
            if(count($row)<2 || !ctype_digit(trim($row[1])))continue;
            $tasks[(int)$row[1]]=strtolower(trim($row[0]));
        }
        return $tasks;
    }

    public static function snapshot(array $spec): array
    {
        if(PHP_OS_FAMILY!=='Windows')return ['available'=>false,'reason'=>'windows_required'];
        $net=self::capture(['C:/Windows/System32/netstat.exe','-ano','-p','tcp']);
        $task=self::capture(['C:/Windows/System32/tasklist.exe','/fo','csv','/nh']);
        if(!$net['ok'] || !$task['ok'])return ['available'=>false,'reason'=>'windows_process_query_failed'];
        $listeners=self::parseListeners($net['output']);
        $tasks=self::parseTasks($task['output']);
        $result=[];
        foreach($spec as $label=>$expected){
            [$port,$pid,$image]=$expected;
            $owners=$listeners[$port]??[];
            $current=$tasks[$pid]??null;
            $result[$label]=[
                'port'=>$port,'expected_pid'=>$pid,
                'listener_pids'=>$owners,'image'=>$current,
                'pid_matches'=>count($owners)===1 && $owners[0]===$pid,
                'image_matches'=>$current===strtolower($image),
                'verified'=>count($owners)===1 && $owners[0]===$pid && $current===strtolower($image),
            ];
        }
        return ['available'=>true,'checks'=>$result,
            'warning'=>'PID and executable basename verified; executable full path, command line and process start time are not verified'];
    }
}
