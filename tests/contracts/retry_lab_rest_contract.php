<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use EZSLab\Service\Database;
use EZSLab\Service\LabRetryService;

function must(bool $condition,string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL '.$message);
    echo 'OK '.$message.PHP_EOL;
}

function column(PDO $db,string $sql): mixed { return $db->query($sql)->fetchColumn(); }

$path=sys_get_temp_dir().'/ezs_retry_lab_'.bin2hex(random_bytes(8)).'.sqlite';
try {
    $db=new Database($path);$pdo=$db->pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS scientific_runs(id INTEGER PRIMARY KEY, state TEXT, finished_at TEXT)');
    $pdo->exec("INSERT INTO songs(id,title,artist,source_filename,audio_sha256,created_at) VALUES(601,'Test','Lab','test.mp3','hash','now')");
    $pdo->exec("INSERT INTO scientific_runs(id,state,finished_at) VALUES(20,'failed','now')");
    $pdo->exec("INSERT INTO analysis_jobs(id,scientific_run_id,song_id,source_path,kind,status,progress,request_json,error,created_at,updated_at,dispatch_owner)
              VALUES(201,20,601,'test.mp3','profile','failed',42,'{}','reason','now','now','orchestrator')");
    $pdo->exec('UPDATE global_queue_admission_outbox SET state=\'admitted\',global_job_id=368 WHERE job_id=201');
    // sqlite_master stores CREATE TRIGGER without IF NOT EXISTS; preserve the real fence.
    $triggerSql=(string)column($pdo,"SELECT sql FROM sqlite_master WHERE type='trigger' AND name='trg_global_queue_admission_fence_status'");
    must(!str_contains(strtoupper($triggerSql), 'IF NOT EXISTS'), 'SQLite canonical trigger normalization tested');
    $service=new LabRetryService($db);
    $payload=['job'=>'profile','analysis_job_id'=>201,'scientific_run_id'=>20,'song_id'=>601];
    $request=['global_job_id'=>368,'attempt'=>1,'source'=>'lab','payload'=>$payload];
    try { $service->prepare($request+['ignored'=>true]); } catch (Throwable $e) { throw $e; }
    must(column($pdo,'SELECT status FROM analysis_jobs WHERE id=201')==='queued','orchestrator-owned job prepared under receipt');
    must(column($pdo,'SELECT state FROM scientific_runs WHERE id=20')==='queued','scientific run prepared');
    $same=$service->prepare($request);
    must(($same['reconciled']??false)===true,'idempotent duplicate prepare');
    try {
        $pdo->exec("UPDATE analysis_jobs SET status='running' WHERE id=201");
        throw new RuntimeException('FAIL global queue fence bypassed');
    } catch (PDOException $e) {
        must(str_contains($e->getMessage(),'global_queue_dispatch_fenced'),'global queue fence remains active');
    }
    $service->finish($request,true);
    must(column($pdo,'SELECT status FROM analysis_jobs WHERE id=201')==='failed','abort restores LAB job');
    must(column($pdo,'SELECT state FROM scientific_runs WHERE id=20')==='failed','abort restores scientific run');
    $service->prepare($request);
    must(column($pdo,'SELECT status FROM analysis_jobs WHERE id=201')==='queued','retry permitted again after abort');
    $service->finish($request,false);
    must(column($pdo,"SELECT state FROM ezs_retry_receipts WHERE global_job_id=368 AND attempt=1")==='committed','receipt commit persisted');
    $wrong=$request;$wrong['payload']['song_id']=999;
    try {$service->state($wrong);throw new RuntimeException('FAIL foreign song accepted');}
    catch (DomainException $e) {must($e->getMessage()==='retry_state_identity_conflict','song mismatch blocked');}
    echo 'RETRY_LAB_REST_CONTRACT_OK'.PHP_EOL;
} finally {
    unset($pdo,$db);
    @unlink($path);@unlink($path.'-wal');@unlink($path.'-shm');
}
