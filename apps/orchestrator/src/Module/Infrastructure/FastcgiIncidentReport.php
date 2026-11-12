<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Read-only FastCGI diagnostics; bounded Windows Application events and existing logs. */
final class FastcgiIncidentReport
{
    public static function collect(string $root, ?array $state): array
    {
        $pid = is_array($state) && isset($state['php_pid']) && is_numeric($state['php_pid']) ? (int)$state['php_pid'] : null;
        $report = [
            'expected_pid'=>$pid,
            'port'=>8791,
            'diagnostic_only'=>true,
            'note'=>'Event logs may not explain termination; no cause inferred from absent listener.',
            'recent_application_events'=>[],
            'startup_stderr_files'=>[],
        ];
        $logs = glob(rtrim(sys_get_temp_dir(), '\\/').'/ezs-orchestrator/php-cgi-start-*.stderr.log') ?: [];
        rsort($logs, SORT_STRING);
        foreach(array_slice($logs,0,5) as $file) {
            $report['startup_stderr_files'][] = [
                'file'=>$file,
                'modified_utc'=>gmdate('c',(int)filemtime($file)),
                'bytes'=>(int)filesize($file),
            ];
        }
        if(PHP_OS_FAMILY!=='Windows') {
            $report['events_available']=false;
            $report['events_reason']='windows_required';
            return $report;
        }
        $cmd=['C:/Windows/System32/wevtutil.exe','qe','Application','/rd:true','/c:25','/f:text',
              '/q:*[System[(EventID=1000 or EventID=1001 or EventID=1002 or EventID=1026) and TimeCreated[timediff(@SystemTime) <= 86400000]]]'];
        $pipes=[];
        $p=@proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
        if(!is_resource($p)){
            $report['events_available']=false;
            $report['events_reason']='wevtutil_launch_failed';
            return $report;
        }
        fclose($pipes[0]);
        $output=stream_get_contents($pipes[1]);fclose($pipes[1]);
        $error=stream_get_contents($pipes[2]);fclose($pipes[2]);
        $status=proc_close($p);
        $report['events_available']=$status===0;
        if($status!==0){
            $report['events_reason']='wevtutil_failed';
            return $report;
        }
        foreach(preg_split('/(?=^Event\[\d+\]:)/m',(string)$output) as $event) {
            if(preg_match('/php-cgi(?:\.exe)?|php\.exe/i',$event)){
                $report['recent_application_events'][]=substr(trim($event),0,1500);
            }
        }
        return $report;
    }
}
