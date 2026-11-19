<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Explicit CLI poller: records transitions only when invoked. No background daemon. */
final class FastcgiTransitionJournal
{
    public static function observe(string $directory, ?int $expectedPid, array $listeners, ?string $image): array
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('EZS_FASTCGI_JOURNAL_DIRECTORY_FAILED');
        }
        $path=$directory.'/fastcgi-observations.jsonl';
        $snapshot=$directory.'/fastcgi-last.json';
        $lock=fopen($directory.'/fastcgi-observe.lock','c');
        if ($lock===false || !flock($lock, LOCK_EX|LOCK_NB)) {
            throw new \RuntimeException('EZS_FASTCGI_OBSERVER_LOCKED');
        }
        try {
            $current=['observed_utc'=>gmdate('c'),'expected_pid'=>$expectedPid,
              'listener_pids'=>array_values($listeners),'image'=>$image,
              'ready'=>$expectedPid!==null && count($listeners)===1 && $listeners[0]===$expectedPid && $image==='php-cgi.exe'];
            $prior=null;
            if(is_file($snapshot)) {
                $prior=json_decode((string)file_get_contents($snapshot),true);
                if(!is_array($prior))throw new \RuntimeException('EZS_FASTCGI_JOURNAL_CORRUPT');
            }
            $transition=$prior===null || $prior['ready']!==$current['ready'] || $prior['expected_pid']!==$expectedPid ||
                $prior['listener_pids']!==$current['listener_pids'];
            if($transition) {
                $event=['kind'=>$prior===null?'initial_observation':'transition','before'=>$prior,'after'=>$current,
                    'note'=>'Observed at CLI poll; not evidence of crash or exact termination time'];
                if(file_put_contents($path,json_encode($event,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n", FILE_APPEND|LOCK_EX)===false)
                    throw new \RuntimeException('EZS_FASTCGI_JOURNAL_WRITE_FAILED');
            }
            $temp=$snapshot.'.'.getmypid().'.tmp';
            if(file_put_contents($temp,json_encode($current,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false ||
                !rename($temp,$snapshot))throw new \RuntimeException('EZS_FASTCGI_SNAPSHOT_WRITE_FAILED');
            return ['current'=>$current,'changed'=>$transition,'journal'=>$path,
                'scope'=>'Manual CLI polling only; no persistent supervision'];
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
}
