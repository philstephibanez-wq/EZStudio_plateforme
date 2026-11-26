<?php
require_once __DIR__.'/../src/Service/ProfileItemsView.php';
use EZSLab\Service\ProfileItemsView;
function check($v,$m){if(!$v)throw new RuntimeException($m);}
$items=[];
foreach(['1','1a','1b','1c','2','3','4','5','6','7','8','9'] as $id) $items[$id]=['state'=>'not_analyzed','value'=>null,'confidence'=>['kind'=>'not_calculated','value'=>null]];
$items['1']['state']='known';$items['1']['value']=['title'=>'<test>','artists'=>['Artiste A & B'],'recording_version'=>null];
$items['2']=['state'=>'known','value'=>243.5,'confidence'=>['kind'=>'calibrated','value'=>0]];
$items['4']=['state'=>'known','value'=>['primary_bpm'=>null,'tracker_bpm'=>117.45,'beat_unit'=>null],'confidence'=>['kind'=>'uncalibrated','value'=>null]];
$items['8']=['state'=>'known','value'=>['coverage'=>'partial','entries'=>[['role'=>'backing','delivery'=>'unknown','articulation'=>'unknown','languages'=>null,'presence'=>'possible']]],'confidence'=>['kind'=>'uncalibrated','value'=>null]];
$rows=ProfileItemsView::rows(['items'=>$items]);
check(count($rows)===12,'count');
check($rows[0]['parts'][0]===['text'=>'<test>','translate'=>false],'literal');
check($rows[0]['parts'][2]['text']==='version_unknown','version');
check($rows[4]['parts'][1]['text']==='0.0 %','zero');
check($rows[5]['parts'][0]['text']==='state_not_analyzed','missing');
check($rows[6]['parts'][0]['text']==='primary_unknown','tempo');
check($rows[6]['detail']==='confidence_uncalibrated','confidence');
check($rows[10]['parts'][3]['text']==='articulation_unknown','articulation');
check($rows[10]['parts'][4]['text']==='presence_possible','presence');
foreach(['fr','en'] as $lang){$dict=json_decode(file_get_contents(__DIR__.'/../../../translations/ezs_profile_items.'.$lang.'.json'),true,512,JSON_THROW_ON_ERROR);foreach($rows as $row){check(isset($dict[$row['label']],$dict[$row['detail']]),'translation');foreach($row['parts'] as $part)if($part['translate'])check(isset($dict[$part['text']]),'part translation');}}
echo "E05_PRESENTER_TEST_OK\n";
