<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Décision sans effet de bord, aucune autorisation d'arrêter un processus. */
final class RootDevRecoveryDecision
{
    /** @param array<string,mixed>|null $state @param list<array<string,mixed>> $processes @param array<int,list<int>> $listeners */
    public static function inspect(?array $state, array $processes, array $listeners): array
    {
        if ($state === null) return ['decision'=>'NO_STATE','reasons'=>['state_absent']];
        foreach (['php_pid','caddy_pid'] as $field) {
            if (!isset($state[$field]) || filter_var($state[$field], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) {
                return ['decision'=>'REFUSE','reasons'=>['invalid_'.$field]];
            }
        }
        $indexed=[];
        foreach ($processes as $p) $indexed[(int)($p['pid']??0)]=$p;
        $php=(int)$state['php_pid']; $caddy=(int)$state['caddy_pid'];
        $reasons=[];
        foreach (['php'=>$php,'caddy'=>$caddy] as $label=>$pid) {
            if (isset($indexed[$pid])) {
                $reasons[]='recorded_'.$label.'_pid_active_'.$pid;
                if ($label==='php') $reasons[]='php_identity_'.EcosystemLifecycleGuard::attestRootFastcgi($state,$indexed[$pid]);
            }
        }
        foreach ([8790,8791,8502,8852] as $port) {
            $owners=$listeners[$port]??[];
            if ($owners===[]) continue;
            $owners=array_values(array_unique(array_map('intval',$owners)));
            sort($owners);
            if (in_array(0,$owners,true)) {
                $reasons[]='listener_'.$port.'_owner_unattributed';
                $owners=array_values(array_filter($owners,static fn(int $pid):bool=>$pid>0));
            }
            if ($owners!==[]) $reasons[]='listener_'.$port.'_pid_'.implode('_',$owners);
        }
        return ['decision'=>$reasons===[]?'COLD_START_CANDIDATE':'REFUSE',
            'reasons'=>$reasons===[]?['requires_second_observation_and_lock']:$reasons];
    }
}
