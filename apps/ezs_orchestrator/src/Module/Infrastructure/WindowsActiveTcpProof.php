<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Preuve en lecture seule : propriétaire du 4-tuple de NOTRE connexion TCP. */
final class WindowsActiveTcpProof
{
    public static function parseOwner(string $netstat, int $serverPort, int $clientPort): ?int
    {
        if($serverPort<1 || $clientPort<1 || $clientPort===$serverPort)return null;
        $owners=[];
        foreach(preg_split('/\r?\n/', $netstat) as $line){
            if(!preg_match('/^\s*TCP\s+127\.0\.0\.1:(\d+)\s+127\.0\.0\.1:(\d+)\s+ESTABLISHED\s+(\d+)\s*$/i', $line, $m))continue;
            if((int)$m[1]!==$serverPort || (int)$m[2]!==$clientPort)continue;
            $owners[(int)$m[3]]=true;
        }
        return count($owners)===1 && (int)array_key_first($owners)>0 ? (int)array_key_first($owners) : null;
    }

    public static function owner(int $port): ?int
    {
        if(PHP_OS_FAMILY!=='Windows' || $port<1 || $port>65535)return null;
        $socket=@stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $error, 1.5, STREAM_CLIENT_CONNECT);
        if(!is_resource($socket))return null;
        try{
            $local=stream_socket_get_name($socket,false);
            $remote=stream_socket_get_name($socket,true);
            if(!is_string($local) || !preg_match('/^127\.0\.0\.1:(\d+)$/D',$local,$m))return null;
            if($remote!=='127.0.0.1:'.$port)return null;
            $clientPort=(int)$m[1];
            if($clientPort<1)return null;
            // The socket stays open while inspecting TCP ownership. Retry only a
            // bounded amount for kernel table propagation; never accept another tuple.
            for($attempt=0;$attempt<3;$attempt++){
                $p=new \Symfony\Component\Process\Process(['C:/Windows/System32/netstat.exe','-ano','-p','tcp']);
                $p->run();
                if(!$p->isSuccessful())throw new \RuntimeException('EZS_ACTIVE_TCP_NETSTAT_FAILED');
                $pid=self::parseOwner($p->getOutput(),$port,$clientPort);
                if($pid!==null)return $pid;
                if($attempt<2)usleep(100000);
            }
            return null;
        }finally{fclose($socket);}
    }
}
