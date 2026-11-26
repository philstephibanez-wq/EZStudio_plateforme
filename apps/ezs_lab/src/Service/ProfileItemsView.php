<?php

declare(strict_types=1);
namespace EZSLab\Service;

use Symfony\Component\Process\Process;
use Psr\Log\LoggerInterface;

/** Presentation only; canonical assembly and validation remain in E04 Python. */
final class ProfileItemsView
{
    public function __construct(private readonly Database $db, private readonly LoggerInterface $logger) {}

    public function build(?array $run): array
    {
        if (!$run || ($run['state'] ?? null) !== 'done') return ['status'=>'pending','rows'=>[]];
        try {
            $root = dirname(__DIR__, 2);
            $python = (string)(getenv('EZSTUDIO_PROFILE_PYTHON') ?: ($_SERVER['EZSTUDIO_PROFILE_PYTHON'] ?? '') ?: 'H:/Python/pythoncore-3.14-64/python.exe');
            $process = new Process([$python, '-B', $root.'/scripts/read-profile-result.py', '--database', $this->db->path(), '--run-id', (string)(int)$run['id']], $root, ['PYTHONUTF8'=>'1','PYTHONIOENCODING'=>'utf-8']);
            $process->setTimeout(10);
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if (($result['schema'] ?? null) !== 'ezstudio.profile.result.v1' || ($result['run_id'] ?? null) !== (int)$run['id'] || ($result['song_id'] ?? null) !== (int)$run['song_id']) throw new \RuntimeException('profile_items_identity_mismatch');
            $rows = self::rows($result);
            $historical = !array_key_exists('scientific_items', $run['diagnostics']['profile_view'] ?? []);
            return ['status'=>$historical?'historical':'ready','rows'=>$rows];
        } catch (\Throwable $e) {
            $this->logger->warning('PROFILE canonical result unavailable', ['run_id'=>$run['id'] ?? null,'exception_type'=>get_class($e)]);
            return ['status'=>'error','rows'=>[]];
        }
    }

    public static function rows(array $result): array
    {
        $rows=[];
        foreach (['1','1a','1b','1c','2','3','4','5','6','7','8','9'] as $id) {
            $item=$result['items'][$id]; $v=$item['value']; $parts=[];
            $literal=static fn($x)=>['text'=>(string)$x,'translate'=>false];
            $key=static fn($x)=>['text'=>$x,'translate'=>true];
            if ($item['state'] !== 'known') $parts[]=$key('state_'.$item['state']);
            else switch ($id) {
                case '1':
                    $parts[]=$literal($v['title']);
                    foreach ($v['artists'] as $artist) $parts[]=$literal($artist);
                    $parts[]=$v['recording_version']===null?$key('version_unknown'):$literal($v['recording_version']); break;
                case '1a': case '1b': case '1c': foreach($v as $name) $parts[]=$literal($name); break;
                case '2': $parts[]=$literal(sprintf('%d:%02d (%.3f s)',floor($v/60),floor(fmod($v,60)),$v)); break;
                case '3': $parts[]=$literal($v['numerator'].'/'.$v['denominator']); break;
                case '4':
                    if($v['primary_bpm']!==null){$parts[]=$literal($v['primary_bpm'].' BPM');$parts[]=$key('unit_'.$v['beat_unit']);} else $parts[]=$key('primary_unknown');
                    if($v['tracker_bpm']!==null){$parts[]=$key('tracker');$parts[]=$literal($v['tracker_bpm'].' BPM');} break;
                case '5': $parts[]=$literal($v['tonic'].' '.$v['mode']); break;
                case '6': foreach($v as $genre) $parts[]=$literal(implode(' / ',array_filter([$genre['family'],$genre['genre'],$genre['subgenre']],static fn($x)=>$x!==null))); break;
                case '7': case '8': case '9':
                    $parts[]=$key('coverage_'.$v['coverage']);
                    foreach($v['entries'] as $part){
                        if($id==='9') $parts[]=$literal($part['instrument']??$part['family']);
                        else {$parts[]=$key('role_'.$part['role']);$parts[]=$key('delivery_'.$part['delivery']);$parts[]=$key('articulation_'.$part['articulation']); if($part['languages']!==null) $parts[]=$literal(implode(', ',$part['languages']));}
                        $parts[]=$key('presence_'.$part['presence']);
                    } break;
            }
            $confidence=$item['confidence'];
            if($confidence['kind']==='calibrated') $parts[]=$literal(sprintf('%.1f %%',100*$confidence['value']));
            $rows[]=['label'=>'item_'.$id,'parts'=>$parts,'detail'=>'confidence_'.$confidence['kind']];
        }
        return $rows;
    }
}
