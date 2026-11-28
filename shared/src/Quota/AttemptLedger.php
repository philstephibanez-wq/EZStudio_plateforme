<?php
declare(strict_types=1);
namespace Shared\Quota;

/** R2: one atomic receipt per execution, independent of scientific databases. */
final class AttemptLedger {
    private static function dir(string $root, bool $test): string {
        return $root.'/var/ezs_quota_attempts'.($test?'_test':'');
    }
    public static function isTest(): bool {
        return ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV')) === 'test';
    }
    private static function snapshot(string $root,bool $test): array {
        try {
            require_once __DIR__.'/QuotaReading.php';
            return QuotaReading::view(QuotaReading::load($root.'/var/ezs_quota'.($test?'_test':'').'.json'),time());
        } catch (\Throwable) { return []; }
    }
    private static function write(string $file,array $row): void {
        $tmp=$file.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $raw=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if (file_put_contents($tmp,$raw)!==strlen($raw)||!rename($tmp,$file)) throw new \RuntimeException('ledger_write');
        } finally { if(is_file($tmp)) unlink($tmp); }
    }
    public static function begin(string $root,bool $test,int $job,int $song,int $run,int $revision): ?array {
        try {
            $dir=self::dir($root,$test);
            if (!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir)) throw new \RuntimeException('ledger_dir');
            $id=bin2hex(random_bytes(16));
            $row=['id'=>$id,'job'=>$job,'song'=>$song,'run'=>$run,'revision'=>$revision,'started_at'=>time(),'finished_at'=>null,'duration_ms'=>null,'status'=>'open','error'=>null,'model'=>null,'input_tokens'=>null,'output_tokens'=>null,'total_tokens'=>null,'quota_before'=>self::snapshot($root,$test),'quota_after'=>null,'quota_attributed'=>null];
            $file=$dir.'/'.$id.'.json'; self::write($file,$row);
            return ['file'=>$file,'row'=>$row,'clock'=>hrtime(true),'root'=>$root,'test'=>$test];
        } catch (\Throwable) { error_log('EZS_QUOTA_R2_LEDGER_BEGIN_FAILED'); return null; }
    }
    public static function usage(string $stderr): array {
        $out=['model'=>null,'input_tokens'=>null,'output_tokens'=>null,'total_tokens'=>null];
        foreach(preg_split('/\r?\n/',$stderr) as $line) {
            if(!str_starts_with($line,'EZS_USAGE_R2 ')||strlen($line)>512) continue;
            $v=json_decode(substr($line,13),true);
            if(!is_array($v)) continue;
            foreach(['input_tokens','output_tokens','total_tokens'] as $k) if(is_int($v[$k]??null)&&$v[$k]>=0) $out[$k]=$v[$k];
            if(is_string($v['model']??null)&&preg_match('/\A(?:gpt-[a-zA-Z0-9.-]{1,60}|o[1-9](?:-[a-zA-Z0-9.-]{1,60})?)\z/D',$v['model'])) $out['model']=$v['model'];
        }
        return $out;
    }
    public static function progress(?array $ctx,string $stage): void {
        if($ctx===null || !in_array($stage,['export','session_lock','registration','oauth','models','model_select','search','result'],true)) return;
        try {
            $row=$ctx['row'];
            $row['stage']=$stage;
            $row['stage_at']=time();
            self::write($ctx['file'],$row);
        } catch (\Throwable) { error_log('EZS_MONITORING_STAGE_WRITE_FAILED'); }
    }
    public static function finish(?array $ctx,string $status,?string $code,string $stderr=''): void {
        if($ctx===null) return;
        try {
            $row=array_replace($ctx['row'],self::usage($stderr));
            $row['status']=$status; $row['error']=$code;
            $row['finished_at']=time(); $row['duration_ms']=(int)((hrtime(true)-$ctx['clock'])/1000000);
            $row['quota_after']=self::snapshot($ctx['root'],$ctx['test']);
            self::write($ctx['file'],$row);
        } catch (\Throwable) { error_log('EZS_QUOTA_R2_LEDGER_FINISH_FAILED'); }
    }
    public static function rows(string $root,bool $test): ?array {
        try {
            $dir=self::dir($root,$test); if(!is_dir($dir)) return [];
            $files=glob($dir.'/*.json'); if($files===false) throw new \RuntimeException('ledger_read');
            $rows=[];
            foreach($files as $file) {
                if(is_link($file)||filesize($file)>65536) throw new \RuntimeException('ledger_invalid');
                $row=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
                if(!is_array($row)||($row['id']??null)!==basename($file,'.json')) throw new \RuntimeException('ledger_invalid');
                if(($row['status']??null)==='open' && time()-$row['started_at']>660) $row['status']='unconfirmed';
                $rows[]=$row;
            }
            usort($rows,static fn($a,$b)=>[$b['started_at'],$b['id']]<=>[$a['started_at'],$a['id']]); return $rows;
        } catch (\Throwable) { error_log('EZS_QUOTA_R2_LEDGER_READ_FAILED'); return null; }
    }
    public static function stats(?array $rows,?array $songs=null): ?array {
        if($rows===null) return null;
        if($songs!==null) $rows=array_values(array_filter($rows,static fn($r)=>in_array($r['song'],$songs,true)));
        $s=['attempts'=>count($rows),'songs'=>count(array_unique(array_column($rows,'song'))),'success'=>0,'failed'=>0,'saturated'=>0,'unconfirmed'=>0,'open'=>0,'measured'=>0,'total_tokens'=>null,'average_tokens'=>null,'average_ms'=>null,'latest'=>$rows[0]['status']??null];
        $duration=0;$n=0;
        foreach($rows as $r) {
            if(in_array($r['status'],['success','failed','saturated','unconfirmed','open'],true)) $s[$r['status']]++;
            if(is_int($r['total_tokens']??null)) {$s['measured']++;$s['total_tokens']=($s['total_tokens']??0)+$r['total_tokens'];}
            if(is_int($r['duration_ms']??null)) {$duration+=$r['duration_ms'];$n++;}
        }
        if($s['measured']) $s['average_tokens']=$s['total_tokens']/$s['measured'];
        if($n) $s['average_ms']=$duration/$n;
        return $s;
    }
}
