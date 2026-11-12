<?php
declare(strict_types=1);
/** One-shot, read-only observation. Never starts, stops, or adopts a process. */
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
use Ezs\Web\Module\Infrastructure\ServiceProbeR6 as Probe;
use Ezs\Web\Module\Infrastructure\IncidentRegisterR6 as Incidents;
use Ezs\Web\Module\Infrastructure\DesiredServiceState;
use Ezs\Web\Module\Infrastructure\NativeSiteLifecycle;

$once=in_array('--once',$argv,true);
$dry=in_array('--dry-run',$argv,true);
if (!$once && !$dry) {fwrite(STDERR,"Usage: php scripts/supervision/observe_services_r6.php --once|--dry-run\n");exit(64);}
$stateDir=$root.'/runtime/supervision';
if (!is_dir($stateDir) && !mkdir($stateDir,0700,true) && !is_dir($stateDir)) throw new RuntimeException('EZS_PROBE_DIR_FAILED');
$lock=fopen($stateDir.'/service-observation-r6.lock','c+');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) {fwrite(STDERR,"EZS_PROBE_BUSY\n");exit(75);}
$now=gmdate('Y-m-d\TH:i:s\Z');
$logFile=$stateDir.'/service-observation-r6.jsonl';
$registryFile=$stateDir.'/service-incidents-r6.json';
try {
    $desired=(new DesiredServiceState())->all();
    $registry=[];
    if (is_file($registryFile)) {
        $decoded=json_decode((string)file_get_contents($registryFile),true);
        if (!is_array($decoded))throw new RuntimeException('EZS_PROBE_REGISTRY_INVALID');
        $registry=$decoded;
    }
    foreach (Probe::PORTS as $service=>$ports) {
        $identity='UNVERIFIED';
        $nativeError=null;
        try {
            $native=(new NativeSiteLifecycle())->request($service,'Status');
            // Identity counts are observations, not destructive-action authorization.
            if (preg_match('/SITE='.preg_quote($service,'/').' INTENT=(RUNNING|STOPPED) OWNED=(\d+) LISTENERS=(\d+)/', $native, $nm)) {
                $identity=((int)$nm[2]===1 && (int)$nm[3]===count($ports))?'COUNTS_CONSISTENT_NOT_OWNER_ATTESTED':
                    (((int)$nm[2]===0 && (int)$nm[3]===0)?'ABSENT':'UNVERIFIED');
            }
        } catch (Throwable $failure) { $nativeError='NATIVE_STATUS_COLLECTION_FAILED'; }
        $hard=[];
        foreach ($ports as $port) {
            $errno=0;$errstr='';
            $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$errstr,0.35,STREAM_CLIENT_CONNECT);
            $hard[(string)$port]=is_resource($socket)?'OPEN':'CLOSED';
            if (is_resource($socket))fclose($socket);
        }
        $url='http://127.0.0.1:'.Probe::SOFT_PORTS[$service].'/api/status';
        $context=stream_context_create(['http'=>['timeout'=>1.5,'ignore_errors'=>true,'follow_location'=>0,
            'header'=>"Accept: application/json\r\nConnection: close\r\n"]]);
        $body=@file_get_contents($url,false,$context);
        $headers=$http_response_header??[];
        $http=preg_match('~^HTTP/\S+\s+(\d{3})~',(string)($headers[0]??''),$m)?(int)$m[1]:0;
        $error=null;
        if (!is_string($body)) $error='SOFT_REQUEST_FAILED';
        if (is_string($body) && strlen($body)>65536) {$body='';$error='SOFT_BODY_TOO_LARGE';}
        $json=is_string($body)?json_decode($body,true):null;
        $soft=Probe::classifySoft($service,$http,is_array($json)?$json:null,$error);
        $event=Incidents::transition($registry,$service,'soft',$soft['state'],$soft['code'],$soft['message'],$now);
        $record=['timestamp_utc'=>$now,'service'=>$service,'desired'=>$desired[$service]??'UNKNOWN',
            'hard_tcp'=>$hard,'hard_identity'=>$identity,'hard_error'=>$nativeError,'soft_http'=>$http,'soft'=>$soft,
            'prod_404_fatal'=>Probe::fatalCutoverCandidate($service,$desired[$service]??'', $soft,false),
            'cutover'=>'NOT_ACTIVATED_R6'];
        echo json_encode($record,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if (!$dry) {
            $line=json_encode(['type'=>'OBSERVATION']+$record,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            if(file_put_contents($logFile,$line,FILE_APPEND|LOCK_EX)===false) throw new RuntimeException('EZS_PROBE_LOG_FAILED');
            if ($event!==null && file_put_contents($logFile,json_encode(['type'=>'INCIDENT']+$event,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX)===false) throw new RuntimeException('EZS_PROBE_LOG_FAILED');
        }
    }
    if (!$dry) {
        $json=json_encode($registry,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
        $tmp=$registryFile.'.'.bin2hex(random_bytes(8)).'.tmp';
        if(file_put_contents($tmp,$json,LOCK_EX)!==strlen($json)||!rename($tmp,$registryFile))throw new RuntimeException('EZS_PROBE_REGISTRY_WRITE_FAILED');
    }
} finally {flock($lock,LOCK_UN);fclose($lock);}
