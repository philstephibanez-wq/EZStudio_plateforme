<?php
declare(strict_types=1);
namespace Shared\Quota;
final class QuotaReading {
    const SCOPES=['five_hours','weekly','application'];
    const FRESH=900;
    public static function validate(mixed $data,int $now): array {
        if (!is_array($data)||!$data) throw new \InvalidArgumentException('empty');
        $out=[];
        foreach($data as $key=>$v) {
            if (!in_array($key,self::SCOPES,true)||!is_array($v)) throw new \InvalidArgumentException('scope');
            $n=$v['remaining']??null; $reset=$v['reset']??null;
            if ((!is_int($n)&&!is_float($n))||!is_finite((float)$n)||$n<0||$n>100) throw new \InvalidArgumentException('percent');
            if ($reset!==null && (!is_int($reset)||$reset<=$now)) throw new \InvalidArgumentException('reset');
            $out[$key]=['remaining'=>$n,'reset'=>$reset,'observed'=>$now];
        }
        return $out;
    }
    public static function load(string $path): array {
        if (!file_exists($path)) return [];
        if (is_link($path)||filesize($path)>32768) throw new \RuntimeException('storage');
        $data=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('storage');
        foreach($data as $k=>$v) {
            if (!is_array($v)||!is_int($v['observed']??null)) throw new \RuntimeException('storage');
            self::validate([$k=>$v],$v['observed']);
        }
        return $data;
    }
    public static function save(string $path,array $data): void {
        $lock=fopen($path.'.lock','c');
        if (!$lock) throw new \RuntimeException('lock');
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('lock');
            $new=array_replace(self::load($path),$data);
            $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
            try { $raw=json_encode($new,JSON_THROW_ON_ERROR); if(file_put_contents($tmp,$raw)!==strlen($raw)||!rename($tmp,$path)) throw new \RuntimeException('write'); }
            finally { if(is_file($tmp)) unlink($tmp); }
        } finally { fclose($lock); }
    }
    public static function view(array $data,int $now): array {
        $out=[];
        foreach(self::SCOPES as $key) {
            $v=$data[$key]??null;
            if (!$v) { $out[$key]=['state'=>'unknown','remaining'=>null,'used'=>null,'reset'=>null,'observed'=>null]; continue; }
            $n=$v['remaining']; $used=100-$n;
            $state=$v['observed']>$now||$now-$v['observed']>self::FRESH||($v['reset']!==null&&$v['reset']<=$now) ? 'stale' : ($n==0?'exhausted':($used>=95?'critical':($used>=80?'low':'normal')));
            $out[$key]=$v+['used'=>$used,'state'=>$state];
        }
        return $out;
    }
}
