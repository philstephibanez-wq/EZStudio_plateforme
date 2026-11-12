<?php
declare(strict_types=1);
namespace EZSLab\Service;

final class AssistantJobCheckpointService {
    public function __construct(private readonly Database $db) {}

    public function save(int $jobId, array $payload): array {
        $job=(new ProfileAiRequestService($this->db))->contextForJob($jobId);
        if (!$job || !in_array($job['job_status'], ['queued','running'], true)) {
            throw new \RuntimeException('job_not_active');
        }
        if (($payload['schema']??null)!=='ezstudio.assistant-checkpoint.v1' ||
            array_diff(array_keys($payload), ['schema','event_key','phase','outcome','detail'])) {
            throw new \InvalidArgumentException('invalid_checkpoint_envelope');
        }
        $key=$payload['event_key']??null;
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D',$key)) {
            throw new \InvalidArgumentException('invalid_event_key');
        }
        $phase=$payload['phase']??null;
        $outcome=$payload['outcome']??null;
        if (!in_array($phase,['intake','discovery','identity','evidence','verification','persistence'],true) ||
            !in_array($outcome,['started','ok','blocked','failed'],true)) {
            throw new \InvalidArgumentException('invalid_checkpoint_state');
        }
        $detail=$payload['detail']??null;
        if (!is_array($detail) || array_diff(array_keys($detail),['discovered_count','read_count','assertion_count','reason_code'])) {
            throw new \InvalidArgumentException('invalid_checkpoint_detail');
        }
        foreach ($detail as $field=>$value) {
            if ($field==='reason_code') {
                if (!is_string($value) || !preg_match('/^[a-z0-9_:-]{1,80}$/D',$value)) throw new \InvalidArgumentException('invalid_reason');
            } elseif (!is_int($value) || $value<0 || $value>100000) {
                throw new \InvalidArgumentException('invalid_count');
            }
        }
        $pdo=$this->db->pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS assistant_job_checkpoints(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL,
            run_id INTEGER NOT NULL,
            event_key TEXT NOT NULL,
            phase TEXT NOT NULL,
            outcome TEXT NOT NULL,
            detail_json TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(job_id,event_key),
            FOREIGN KEY(job_id) REFERENCES analysis_jobs(id) ON DELETE CASCADE
        )");
        $json=json_encode($detail,JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT OR IGNORE INTO assistant_job_checkpoints(job_id,run_id,event_key,phase,outcome,detail_json,created_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$jobId,$job['run_id'],$key,$phase,$outcome,$json,gmdate('c')]);
        $stmt=$pdo->prepare('SELECT * FROM assistant_job_checkpoints WHERE job_id=? AND event_key=?');
        $stmt->execute([$jobId,$key]);
        $row=$stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $row['phase']!==$phase || $row['outcome']!==$outcome || $row['detail_json']!==$json) {
            throw new \RuntimeException('checkpoint_idempotency_conflict');
        }
        return $this->view($row);
    }

    public function history(int $jobId): array {
        $pdo=$this->db->pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS assistant_job_checkpoints(
            id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INTEGER NOT NULL,run_id INTEGER NOT NULL,
            event_key TEXT NOT NULL,phase TEXT NOT NULL,outcome TEXT NOT NULL,detail_json TEXT NOT NULL,
            created_at TEXT NOT NULL,UNIQUE(job_id,event_key),
            FOREIGN KEY(job_id) REFERENCES analysis_jobs(id) ON DELETE CASCADE)");
        $job=(new ProfileAiRequestService($this->db))->contextForJob($jobId);
        if (!$job) throw new \RuntimeException('job_not_found');
        $stmt=$pdo->prepare('SELECT * FROM assistant_job_checkpoints WHERE job_id=? ORDER BY id ASC');
        $stmt->execute([$jobId]);
        return array_map($this->view(...),$stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function view(array $row): array {
        return ['id'=>(int)$row['id'],'job_id'=>(int)$row['job_id'],'run_id'=>(int)$row['run_id'],
            'event_key'=>$row['event_key'],'phase'=>$row['phase'],'outcome'=>$row['outcome'],
            'detail'=>json_decode($row['detail_json'],true),'created_at'=>$row['created_at']];
    }
}
