<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** A pure classifier. Collection is independent of the caller's lifecycle authority. */
final class ServiceProbeR6
{
    public const PORTS = [
        'dev' => [8502, 8602],
        'lab' => [8702, 8701],
        'assistant' => [8712, 8713],
        'prod' => [8501, 8510, 8511],
    ];
    public const SOFT_PORTS = ['dev'=>8602,'lab'=>8701,'assistant'=>8713,'prod'=>8511];

    public static function classifySoft(string $service, int $http, ?array $json, ?string $error): array
    {
        if (!array_key_exists($service, self::PORTS)) throw new \InvalidArgumentException('EZS_PROBE_UNKNOWN_SERVICE');
        if ($http === 404) {
            return ['state'=>$service==='prod'?'FATAL':'ERROR','code'=>'SOFT_HTTP_404',
                'message'=>'Mandatory status endpoint returned HTTP 404'];
        }
        if ($error !== null) return ['state'=>'COLLECTION_ERROR','code'=>'SOFT_COLLECTION_ERROR', 'message'=>$error];
        if ($http !== 200) return ['state'=>'ERROR','code'=>'SOFT_HTTP_'.$http,'message'=>'Unexpected soft HTTP status'];
        if (!is_array($json) || ($json['schema']??null)!=='ezs.service-status.v1'
            || ($json['state']??null)!=='OK' && ($json['state']??null)!=='KO'
            || !isset($json['components']) || !is_array($json['components'])) {
            return ['state'=>'ERROR','code'=>'SOFT_SCHEMA_INVALID','message'=>'Status response is not ezs.service-status.v1'];
        }
        if (($json['state']??null)==='KO') return ['state'=>'ERROR','code'=>'SOFT_SERVICE_KO','message'=>'Application reports KO'];
        return ['state'=>'OK','code'=>'SOFT_OK','message'=>'Application reports OK'];
    }

    public static function fatalCutoverCandidate(string $service, string $desired, array $soft, bool $portOwnerAttested): bool
    {
        return $service==='prod' && $desired==='RUNNING' && $portOwnerAttested
            && ($soft['state']??null)==='FATAL' && ($soft['code']??null)==='SOFT_HTTP_404';
    }
}
