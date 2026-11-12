<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Démarrage isolé de l'IA Assistant sur MAIN. Aucun arrêt de processus. */
final class LocalAiLifecycle
{
    public const ROOT = 'H:/EZStudioLab_assistant';
    public const EXE = self::ROOT.'/runtime/ai/bin/llama-server.exe';
    public const MODEL = self::ROOT.'/runtime/models-cache/assistant/bench/qwen2.5-1.5b-instruct-q4_k_m/qwen2.5-1.5b-instruct-q4_k_m.gguf';
    public const PORT = 8720;
    private static array $handles=[];
    private readonly \Closure $inventory;
    private readonly \Closure $listeners;
    private readonly \Closure $health;
    private readonly \Closure $spawn;
    private readonly \Closure $exists;
    private readonly \Closure $activeOwner;

    public function __construct(?callable $inventory=null,?callable $listeners=null,?callable $health=null,?callable $spawn=null,?callable $exists=null,?callable $activeOwner=null)
    {
        $this->inventory=\Closure::fromCallable($inventory ?? static fn():array=>(new WindowsProcessInventory())->collect());
        $this->listeners=\Closure::fromCallable($listeners ?? static function():array {
            $p=new \Symfony\Component\Process\Process(['C:/Windows/System32/netstat.exe','-ano','-p','tcp']);
            $p->run();if(!$p->isSuccessful())throw new \RuntimeException('EZS_AI_NETSTAT_FAILED');
            return WindowsPortOwnership::parseListeners($p->getOutput());
        });
        $this->health=\Closure::fromCallable($health ?? static function():bool {
            $ctx=stream_context_create(['http'=>['timeout'=>2,'ignore_errors'=>true]]);
            $body=@file_get_contents('http://127.0.0.1:8720/health',false,$ctx);
            if($body===false)return false;
            $status=$http_response_header[0]??'';
            if(!preg_match('~^HTTP/\S+ 200(?:\s|$)~',$status))return false;
            $json=json_decode($body,true);
            return is_array($json) && in_array($json['status']??null,['ok','ready'],true);
        });
        $this->spawn=\Closure::fromCallable($spawn ?? self::nativeSpawn(...));
        $this->exists=\Closure::fromCallable($exists ?? static fn(string $path):bool=>is_file($path));
        $this->activeOwner=\Closure::fromCallable($activeOwner ?? WindowsActiveTcpProof::owner(...));
    }
    /** @return list<string> */
    public static function arguments():array
    {
        return ['-m',self::MODEL,'--host','127.0.0.1','--port','8720','-c','24576','-np','1'];
    }
    private static function isOurs(array $p):bool
    {
        $norm=static fn(string $s):string=>strtolower(str_replace('/','\\',$s));
        if($norm((string)($p['executable']??''))!==$norm(self::EXE))return false;
        $parts=[self::EXE,...self::arguments()];
        $patterns=array_map(static fn(string $s):string=>'(?:"'.preg_quote($norm($s),'~').'"|'.preg_quote($norm($s),'~').')',$parts);
        return (bool)preg_match('~^\s*'.implode('\s+',$patterns).'\s*$~iD',$norm((string)($p['command']??'')));
    }
    /** A listener with PID 0 or no listener is NOT proof of a free port. */
    private function owner(array $listeners):?int
    {
        $owners=$listeners[self::PORT]??[];
        if(count($owners)>1)throw new \RuntimeException('EZS_AI_PORT_MULTIPLE_OWNERS');
        if(count($owners)===1 && (int)$owners[0]>0)return (int)$owners[0];
        // Reuse R8F8's exact 4-tuple proof, never trust a random ESTABLISHED socket.
        $pid=($this->activeOwner)(self::PORT);
        return is_int($pid) && $pid>0 ? $pid : null;
    }
    /** @return array{pid:int,action:string} */
    public function start():array
    {
        if(!(($this->exists)(self::EXE)) || !(($this->exists)(self::MODEL)))throw new \RuntimeException('EZS_AI_DEPENDENCY_MISSING');
        $inventory=($this->inventory)();$listeners=($this->listeners)();
        $pid=$this->owner($listeners);
        $llamas=array_values(array_filter($inventory,static fn(array $p):bool=>str_ends_with(strtolower(str_replace('\\','/',(string)($p['executable']??''))),'/llama-server.exe')));
        if($pid!==null){
            $p=null;foreach($inventory as $item){if((int)($item['pid']??0)===$pid)$p=$item;}
            if($p===null || !self::isOurs($p))throw new \RuntimeException('EZS_AI_PORT_FOREIGN');
            if(count($llamas)!==1)throw new \RuntimeException('EZS_AI_DUPLICATE_SERVERS');
            if(!(($this->health)()))throw new \RuntimeException('EZS_AI_OWNED_NOT_READY');
            return ['pid'=>$pid,'action'=>'ALREADY_READY'];
        }
        // Never spawn if a live llama is present, or if the listener is unverified.
        if(($listeners[self::PORT]??[])!==[])throw new \RuntimeException('EZS_AI_PORT_UNVERIFIED');
        if($llamas!==[])throw new \RuntimeException('EZS_AI_PROCESS_WITHOUT_LISTENER');
        $pid=($this->spawn)();
        if(!is_int($pid)||$pid<1)throw new \RuntimeException('EZS_AI_SPAWN_FAILED');
        for($i=0;$i<120;$i++){
            usleep(500000);
            $current=($this->inventory)();$ports=($this->listeners)();
            $found=null;foreach($current as $item){if((int)($item['pid']??0)===$pid)$found=$item;}
            if($found===null)throw new \RuntimeException('EZS_AI_SPAWNED_PROCESS_EXITED');
            if(!self::isOurs($found))throw new \RuntimeException('EZS_AI_SPAWNED_IDENTITY_MISMATCH');
            $owner=$this->owner($ports);
            if($owner===$pid && ($this->health)())return ['pid'=>$pid,'action'=>'STARTED'];
            if($owner!==null && $owner!==$pid)throw new \RuntimeException('EZS_AI_SPAWN_PORT_FOREIGN');
        }
        throw new \RuntimeException('EZS_AI_START_TIMEOUT_NO_TERMINATION');
    }
    private static function nativeSpawn():int
    {
        $logDir=self::ROOT.'/logs';
        if(!is_dir($logDir))throw new \RuntimeException('EZS_AI_LOGDIR_MISSING');
        $nonce=bin2hex(random_bytes(6));
        $resource=proc_open([self::EXE,...self::arguments()], [
            0=>['file','NUL','r'],
            1=>['file',$logDir.'/llama-orchestrator-'.$nonce.'.out.log','a'],
            2=>['file',$logDir.'/llama-orchestrator-'.$nonce.'.err.log','a'],
        ],$pipes,self::ROOT,null,['bypass_shell'=>true,'create_new_console'=>false,'suppress_errors'=>true]);
        if(!is_resource($resource))throw new \RuntimeException('EZS_AI_PROC_OPEN_FAILED');
        $s=proc_get_status($resource);
        if(!($s['running']??false) || (int)($s['pid']??0)<1){proc_close($resource);throw new \RuntimeException('EZS_AI_PROC_EXITED');}
        self::$handles[]=$resource;
        return (int)$s['pid'];
    }
}
