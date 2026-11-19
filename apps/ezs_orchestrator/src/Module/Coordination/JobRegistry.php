<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Coordination;

use PDO;
use RuntimeException;

/** Durable local registry; does not claim, dispatch, or execute jobs. */
final class JobRegistry
{
    private PDO $db;
    public function __construct(string $path)
    {
        if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('pdo_sqlite_required');
        $dir=dirname($path);
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('registry_directory_unavailable');
        $this->db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec('CREATE TABLE IF NOT EXISTS jobs (target TEXT NOT NULL, job_id INTEGER NOT NULL CHECK(job_id>0), kind TEXT NOT NULL, priority INTEGER NOT NULL, state TEXT NOT NULL CHECK(state IN (\'queued\',\'running\',\'completed\',\'failed\',\'finalize_error\')), created_at TEXT NOT NULL, updated_at TEXT NOT NULL, PRIMARY KEY(target,job_id))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS attempts (target TEXT NOT NULL, job_id INTEGER NOT NULL, attempt_no INTEGER NOT NULL CHECK(attempt_no>0), state TEXT NOT NULL, analysis_returncode INTEGER, finalize_status TEXT NOT NULL, started_at TEXT, finished_at TEXT, error TEXT, PRIMARY KEY(target,job_id,attempt_no), FOREIGN KEY(target,job_id) REFERENCES jobs(target,job_id))');
    }
    public function register(string $target,int $jobId,string $kind,int $priority=50):void
    {
        if (!in_array($target,['dev','prod','lab'],true)||$jobId<=0||trim($kind)==='') throw new \InvalidArgumentException('invalid_job_identity');
        $stmt=$this->db->prepare('INSERT OR IGNORE INTO jobs(target,job_id,kind,priority,state,created_at,updated_at) VALUES(?,?,?, ?,\'queued\',?,?)');
        $now=gmdate('Y-m-d\TH:i:s\Z');
        $stmt->execute([$target,$jobId,$kind,$priority,$now,$now]);
        $old=$this->job($target,$jobId);
        if ($old===null||$old['kind']!==$kind||(int)$old['priority']!==$priority)throw new \DomainException('job_identity_collision');
    }
    public function recordAttempt(array $data,int $attemptNo):array
    {
        if($attemptNo<=0)throw new \InvalidArgumentException('invalid_attempt_number');
        $a=JobAttemptProjection::project($data);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $job=$this->job($a['target'],$a['job_id']);
            if($job===null)throw new \DomainException('job_not_registered');
            $old=$this->attempt($a['target'],$a['job_id'],$attemptNo);
            if($old!==null) {
                $allowed=['running'=>['running','completed','failed','finalize_error'],'queued'=>['queued','running'],'completed'=>['completed'],'failed'=>['failed'],'finalize_error'=>['finalize_error']];
                if(!in_array($a['state'],$allowed[$old['state']]??[],true))throw new \DomainException('invalid_attempt_transition');
                if($old['state']===$a['state'] && $this->same($old,$a)){$this->db->commit();return $a;}
                if(in_array($old['state'],['completed','failed','finalize_error'],true))throw new \DomainException('terminal_attempt_immutable');
                $sql='UPDATE attempts SET state=?,analysis_returncode=?,finalize_status=?,started_at=?,finished_at=?,error=? WHERE target=? AND job_id=? AND attempt_no=?';
                $args=[$a['state'],$a['analysis_returncode'],$a['finalize_status'],$a['started_at']??$old['started_at'],$a['finished_at'],$a['error'],$a['target'],$a['job_id'],$attemptNo];
            }else{
                $last=(int)$this->db->query('SELECT COUNT(*) FROM attempts WHERE target='.$this->db->quote($a['target']).' AND job_id='.(int)$a['job_id'])->fetchColumn();
                if($attemptNo!==$last+1)throw new \DomainException('attempt_sequence_violation');
                if($last>0){$prev=$this->attempt($a['target'],$a['job_id'],$last);if(!in_array($prev['state'],['completed','failed','finalize_error'],true))throw new \DomainException('previous_attempt_active');}
                if(!in_array($a['state'],['queued','running'],true))throw new \DomainException('attempt_must_start_nonterminal');
                $sql='INSERT INTO attempts(state,analysis_returncode,finalize_status,started_at,finished_at,error,target,job_id,attempt_no) VALUES(?,?,?,?,?,?,?,?,?)';
                $args=[$a['state'],$a['analysis_returncode'],$a['finalize_status'],$a['started_at'],$a['finished_at'],$a['error'],$a['target'],$a['job_id'],$attemptNo];
            }
            $stmt=$this->db->prepare($sql);$stmt->execute($args);
            $this->db->prepare('UPDATE jobs SET state=?,updated_at=? WHERE target=? AND job_id=?')->execute([$a['state'],gmdate('Y-m-d\TH:i:s\Z'),$a['target'],$a['job_id']]);
            $this->db->commit();return $a;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function job(string $target,int $id):?array
    {
        $s=$this->db->prepare('SELECT * FROM jobs WHERE target=? AND job_id=?');$s->execute([$target,$id]);return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public function attempt(string $target,int $id,int $no):?array
    {
        $s=$this->db->prepare('SELECT * FROM attempts WHERE target=? AND job_id=? AND attempt_no=?');$s->execute([$target,$id,$no]);return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private function same(array $old,array $a):bool
    {
        foreach(['state','finalize_status','started_at','finished_at','error'] as $k){if(($old[$k]??null)!==($a[$k]??null))return false;}
        return ($old['analysis_returncode']===null?null:(int)$old['analysis_returncode'])===$a['analysis_returncode'];
    }
}
