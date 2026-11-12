<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** L'intention de l'operateur prime toujours sur les sondes. */
final class DesiredServiceState
{
    public const GROUPS=['root','dev','lab','assistant','prod'];
    public const RUNNING='RUNNING';
    public const STOPPED='STOPPED';
    private readonly string $path;

    public function __construct(?string $path=null)
    {
        $this->path=$path??'H:/EZS_orchestrator/runtime/supervision/desired-services.json';
    }

    public function all():array
    {
        if(!is_file($this->path))return array_fill_keys(self::GROUPS,self::STOPPED);
        $raw=file_get_contents($this->path);
        $data=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($data)||($data['schema']??null)!=='ezs.desired-services.v1'||!is_array($data['services']??null))
            throw new \RuntimeException('EZS_DESIRED_STATE_INVALID');
        $services=array_fill_keys(self::GROUPS,self::STOPPED);
        foreach($data['services'] as $name=>$state){
            if($name==='assistant_ai' && in_array($state,[self::RUNNING,self::STOPPED],true)) continue; // legacy field retired on next write
            if(!array_key_exists($name,$services)||!in_array($state,[self::RUNNING,self::STOPPED],true))
                throw new \RuntimeException('EZS_DESIRED_STATE_INVALID_ENTRY');
            $services[$name]=$state;
        }
        return $services;
    }

    public function get(string $name):string
    {
        self::assertGroup($name);
        return $this->all()[$name];
    }

    public function set(string $name,string $state):void
    {
        $this->setMany([$name=>$state]);
    }

    public function setMany(array $changes):void
    {
        foreach($changes as $name=>$state){
            self::assertGroup($name);
            if(!in_array($state,[self::RUNNING,self::STOPPED],true))
                throw new \InvalidArgumentException('EZS_DESIRED_STATE_REFUSED');
        }
        if($changes===[])return;
        $dir=dirname($this->path);
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('EZS_DESIRED_DIR_FAILED');
        $lock=fopen($dir.'/desired-services.lock','c+');
        if(!is_resource($lock)||!flock($lock,LOCK_EX|LOCK_NB)){
            if(is_resource($lock))fclose($lock);
            throw new \RuntimeException('EZS_DESIRED_LOCK_BUSY');
        }
        try{
            $services=array_replace($this->all(),$changes);
            $payload=json_encode(['schema'=>'ezs.desired-services.v1','services'=>$services],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
            $tmp=$this->path.'.'.bin2hex(random_bytes(8)).'.tmp';
            try{
                if(file_put_contents($tmp,$payload,LOCK_EX)!==strlen($payload)||!rename($tmp,$this->path))
                    throw new \RuntimeException('EZS_DESIRED_WRITE_FAILED');
            }finally{if(is_file($tmp))unlink($tmp);}
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    private static function assertGroup(string $name):void
    {
        if(!in_array($name,self::GROUPS,true))throw new \InvalidArgumentException('EZS_DESIRED_GROUP_REFUSED');
    }
}
