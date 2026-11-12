<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Persisted, per-component incident fingerprint sets. Caller owns exclusive file lock. */
final class IncidentRegisterR6
{
    public static function fingerprint(string $service,string $component,string $state,string $code,string $message): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($message));
        if ($normalized===null) $normalized=trim($message);
        return hash('sha256',json_encode([$service,$component,$state,$code,$normalized],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }

    public static function transition(array &$register,string $service,string $component,string $state,string $code,string $message,string $now): ?array
    {
        $key=$service.'/'.$component;
        $entry=$register[$key]??['active'=>false,'seen'=>[]];
        if ($state==='OK') {
            if (empty($entry['active'])) return null;
            $register[$key]=['active'=>false,'seen'=>[],'resolved_at'=>$now];
            return ['event'=>'RESOLVED','service'=>$service,'component'=>$component,'timestamp_utc'=>$now];
        }
        $hash=self::fingerprint($service,$component,$state,$code,$message);
        if (!empty($entry['active']) && isset($entry['seen'][$hash])) {
            $entry['last_seen']=$now;
            $register[$key]=$entry;
            return null;
        }
        if (empty($entry['active'])) $entry=['active'=>true,'seen'=>[],'first_seen'=>$now];
        $entry['seen'][$hash]=true;
        $entry['last_seen']=$now;
        $register[$key]=$entry;
        return ['event'=>'NEW_ERROR','service'=>$service,'component'=>$component,'state'=>$state,
            'code'=>$code,'message'=>$message,'checksum'=>$hash,'timestamp_utc'=>$now];
    }
}
