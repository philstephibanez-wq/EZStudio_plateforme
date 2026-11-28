<?php
declare(strict_types=1);
namespace Shared\Quota;
use Symfony\Component\Process\Process;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
final class CodexQuotaProvider {
    public static function normalize(array $v, int $now): array {
        $out=[];
        if (($v['account_type']??null)!=='chatgpt') throw new \RuntimeException('account');
        $windows=$v['quota_windows']['codex']??$v['quota_windows']['default']??[];
        if (($windows['limitId']??null)!=='codex') throw new \RuntimeException('limit');
        foreach (['primary'=>300,'secondary'=>10080] as $slot=>$duration) {
            $w=$windows[$slot]??[]; $p=$w['remaining_percent']??null; $r=$w['reset_epoch']??null;
            if ((!is_int($p)&&!is_float($p)) || !is_finite((float)$p) || $p<0 || $p>100 || ($w['window_minutes']??null)!==$duration || !is_int($r) || $r<=$now) throw new \RuntimeException('window');
            $out[$duration===300?'five_hours':'weekly']=['remaining'=>$p,'reset'=>$r,'observed'=>$now];
        }
        return $out;
    }
    public static function read(string $root): array {
        try {
            $dir=$root.'/var/runtime/deps/codex';
            $cfg=json_decode(file_get_contents($dir.'/ezs-config.json'),true,8,JSON_THROW_ON_ERROR);
            $exe=$dir.'/'.$cfg['executable'];
            if (!is_file($exe)||!is_file($cfg['python'])) return [];
            $cache=new FilesystemAdapter('ezs_codex_quota_r1',60,$root.'/var/cache/ezs_codex_quota');
            return $cache->get('quota',static function ($item) use ($cfg,$exe,$root) {
                $item->expiresAfter(60);
                try {
                    $p=new Process([$cfg['python'],'-X','utf8','-B',$root.'/shared/src/Quota/codex-quota-probe.py','--codex',$exe],$root);
                    $p->setTimeout(25); $p->mustRun();
                    return self::normalize(json_decode($p->getOutput(),true,16,JSON_THROW_ON_ERROR),time());
                } catch (\Throwable) { return []; }
            });
        } catch (\Throwable) { return []; }
    }
}
