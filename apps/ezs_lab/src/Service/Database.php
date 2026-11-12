<?php
namespace EZSLab\Service;

use PDO;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly string $databasePath) {}

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dir = dirname($this->databasePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        $this->pdo = $pdo;
        $this->ensureSchema();
        $this->ensureAnalysisResourceLockSchema();

        return $this->pdo;
    }

    public function path(): string
    {
        return $this->databasePath;
    }

    private function ensureSchema(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS songs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    artist TEXT NOT NULL DEFAULT '',
    source_filename TEXT NOT NULL,
    audio_sha256 TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_songs_sha ON songs(audio_sha256);

CREATE TABLE IF NOT EXISTS benchmark_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    song_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    requested_signature TEXT NOT NULL,
    detected_signature TEXT,
    tempo REAL,
    status TEXT NOT NULL,
    progress INTEGER NOT NULL DEFAULT 0,
    engine_version TEXT NOT NULL,
    input_path TEXT NOT NULL,
    error TEXT,
    result_json TEXT,
    FOREIGN KEY(song_id) REFERENCES songs(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_runs_song ON benchmark_runs(song_id);

CREATE TABLE IF NOT EXISTS algorithm_results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL,
    algorithm TEXT NOT NULL,
    phase INTEGER NOT NULL,
    score REAL NOT NULL,
    margin REAL NOT NULL,
    grid_json TEXT NOT NULL,
    UNIQUE(run_id, algorithm),
    FOREIGN KEY(run_id) REFERENCES benchmark_runs(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS judgements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL UNIQUE,
    selected_algorithm TEXT NOT NULL,
    selected_phase INTEGER NOT NULL,
    reference TEXT NOT NULL DEFAULT '',
    comment TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    FOREIGN KEY(run_id) REFERENCES benchmark_runs(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS run_reviews (
    run_id INTEGER PRIMARY KEY,
    reference TEXT NOT NULL DEFAULT '',
    comment TEXT NOT NULL DEFAULT '',
    reviewed_at TEXT NOT NULL,
    FOREIGN KEY(run_id) REFERENCES benchmark_runs(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS algorithm_reviews (
    run_id INTEGER NOT NULL,
    algorithm TEXT NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('approved','rejected')),
    reviewed_at TEXT NOT NULL,
    PRIMARY KEY(run_id, algorithm),
    FOREIGN KEY(run_id) REFERENCES benchmark_runs(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_algorithm_reviews_algorithm ON algorithm_reviews(algorithm);

CREATE TABLE IF NOT EXISTS run_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    level TEXT NOT NULL,
    message TEXT NOT NULL,
    FOREIGN KEY(run_id) REFERENCES benchmark_runs(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS analysis_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER,
    scientific_run_id INTEGER,
    song_id INTEGER NOT NULL,
    source_path TEXT NOT NULL,
    kind TEXT NOT NULL,
    status TEXT NOT NULL,
    progress INTEGER NOT NULL DEFAULT 0,
    request_json TEXT NOT NULL,
    error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(run_id, kind),
    FOREIGN KEY(run_id) REFERENCES benchmark_runs(id) ON DELETE CASCADE,
    FOREIGN KEY(song_id) REFERENCES songs(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_analysis_jobs_status
ON analysis_jobs(status, created_at, id);
SQL;

        $this->pdo->exec($sql);

        $jobColumns = $this->pdo->query('PRAGMA table_info(analysis_jobs)')->fetchAll(PDO::FETCH_ASSOC);
        $jobColumnNames = array_column($jobColumns, 'name');
        if (!in_array('dispatch_owner', $jobColumnNames, true)) {
            $this->pdo->exec("ALTER TABLE analysis_jobs ADD COLUMN dispatch_owner TEXT NOT NULL DEFAULT 'lab' CHECK(dispatch_owner IN ('lab','orchestrator'))");
        }
        // Atomic outbox: the owner and outbox record are committed with the job.
        $this->pdo->exec(<<<'QUEUE_SQL'
CREATE TABLE IF NOT EXISTS global_queue_admission_outbox (
    job_id INTEGER PRIMARY KEY REFERENCES analysis_jobs(id) ON DELETE CASCADE,
    state TEXT NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','admitted')),
    global_job_id INTEGER,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    updated_at TEXT NOT NULL
);
CREATE TRIGGER IF NOT EXISTS trg_global_queue_admission_insert
AFTER INSERT ON analysis_jobs WHEN NEW.dispatch_owner='orchestrator'
BEGIN
    INSERT INTO global_queue_admission_outbox(job_id,state,updated_at)
    VALUES(NEW.id,'pending',NEW.created_at);
END;
CREATE TABLE IF NOT EXISTS global_queue_terminal_receipts (
    job_id INTEGER PRIMARY KEY REFERENCES analysis_jobs(id),
    global_job_id INTEGER NOT NULL,
    terminal_state TEXT NOT NULL CHECK(terminal_state IN ('failed','cancelled'))
);
CREATE TRIGGER IF NOT EXISTS trg_global_queue_admission_fence_status
BEFORE UPDATE OF status ON analysis_jobs
WHEN OLD.dispatch_owner='orchestrator' AND NEW.status <> OLD.status
 AND NOT (
     OLD.status='queued' AND NEW.status IN ('failed','cancelled')
     AND EXISTS (
        SELECT 1 FROM global_queue_terminal_receipts proof
        JOIN global_queue_admission_outbox o ON o.job_id=proof.job_id
        WHERE proof.job_id=OLD.id AND o.state='admitted'
          AND o.global_job_id=proof.global_job_id
          AND proof.terminal_state=NEW.status
     )
 )
BEGIN
    SELECT RAISE(ABORT,'global_queue_dispatch_fenced');
END;
CREATE TRIGGER IF NOT EXISTS trg_global_queue_admission_fence_owner
BEFORE UPDATE OF dispatch_owner ON analysis_jobs
WHEN OLD.dispatch_owner='orchestrator' AND NEW.dispatch_owner <> 'orchestrator'
BEGIN
    SELECT RAISE(ABORT,'global_queue_owner_immutable');
END;
CREATE TRIGGER IF NOT EXISTS trg_global_queue_admission_fence_delete
BEFORE DELETE ON analysis_jobs WHEN OLD.dispatch_owner='orchestrator'
BEGIN
    SELECT RAISE(ABORT,'global_queue_cancel_protocol_required');
END;
QUEUE_SQL);
        $songColumns = $this->pdo->query("PRAGMA table_info(songs)")->fetchAll();
        $songColumnNames = array_map(
            static fn(array $row): string => (string)$row['name'],
            $songColumns
        );
        if (!in_array('source_path', $songColumnNames, true)) {
            $this->pdo->exec('ALTER TABLE songs ADD COLUMN source_path TEXT');
        }
    }

    public function allRuns(): array
    {
        $sql = 'SELECT r.*, s.title, s.artist, s.source_filename,
                       rr.reference AS review_reference,
                       rr.comment AS review_comment,
                       rr.reviewed_at
                FROM benchmark_runs r
                JOIN songs s ON s.id = r.song_id
                LEFT JOIN run_reviews rr ON rr.run_id = r.id
                ORDER BY r.id DESC';

        return $this->pdo()->query($sql)->fetchAll();
    }

    public function createSong(string $title, string $artist, string $filename, string $sha): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO songs(title,artist,source_filename,audio_sha256,created_at)
             VALUES(?,?,?,?,?)'
        );
        $stmt->execute([$title, $artist, $filename, $sha, gmdate('c')]);

        return (int)$this->pdo()->lastInsertId();
    }

    public function createRun(int $songId, string $signature, string $inputPath): int
    {
        $now = gmdate('c');

        $stmt = $this->pdo()->prepare(
            'INSERT INTO benchmark_runs(
                song_id,created_at,updated_at,requested_signature,status,
                progress,engine_version,input_path
             ) VALUES(?,?,?,?,?,?,?,?)'
        );

        $stmt->execute([
            $songId,
            $now,
            $now,
            $signature,
            'running',
            0,
            'r6-app-v2',
            $inputPath,
        ]);

        return (int)$this->pdo()->lastInsertId();
    }

    public function run(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT r.*, s.title, s.artist, s.source_filename, s.audio_sha256
             FROM benchmark_runs r
             JOIN songs s ON s.id = r.song_id
             WHERE r.id=?'
        );

        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function algorithms(int $runId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT ar.*,
                    rv.status AS review_status
             FROM algorithm_results ar
             LEFT JOIN algorithm_reviews rv
               ON rv.run_id = ar.run_id
              AND rv.algorithm = ar.algorithm
             WHERE ar.run_id=?
             ORDER BY ar.id'
        );

        $stmt->execute([$runId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['grid'] = json_decode($row['grid_json'], true) ?: [];
            $row['review_status'] = $row['review_status'] ?: 'unreviewed';
        }

        return $rows;
    }

    public function logs(int $runId, int $limit = 200): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM run_logs
             WHERE run_id=?
             ORDER BY id DESC
             LIMIT ?'
        );

        $stmt->bindValue(1, $runId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_reverse($stmt->fetchAll());
    }

    public function runReview(int $runId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM run_reviews WHERE run_id=?'
        );
        $stmt->execute([$runId]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function saveAlgorithmApprovals(
        int $runId,
        array $approvedAlgorithms,
        string $reference,
        string $comment
    ): void {
        $this->pdo()->beginTransaction();

        try {
            $algorithms = $this->algorithms($runId);
            $approved = array_fill_keys(array_map('strval', $approvedAlgorithms), true);
            $now = gmdate('c');

            $upsertReview = $this->pdo()->prepare(
                'INSERT INTO algorithm_reviews(run_id,algorithm,status,reviewed_at)
                 VALUES(?,?,?,?)
                 ON CONFLICT(run_id,algorithm)
                 DO UPDATE SET
                    status=excluded.status,
                    reviewed_at=excluded.reviewed_at'
            );

            foreach ($algorithms as $algorithm) {
                $name = (string)$algorithm['algorithm'];
                $status = isset($approved[$name]) ? 'approved' : 'rejected';

                $upsertReview->execute([
                    $runId,
                    $name,
                    $status,
                    $now,
                ]);
            }

            $stmt = $this->pdo()->prepare(
                'INSERT INTO run_reviews(run_id,reference,comment,reviewed_at)
                 VALUES(?,?,?,?)
                 ON CONFLICT(run_id)
                 DO UPDATE SET
                    reference=excluded.reference,
                    comment=excluded.comment,
                    reviewed_at=excluded.reviewed_at'
            );

            $stmt->execute([
                $runId,
                $reference,
                $comment,
                $now,
            ]);

            $this->pdo()->commit();
        } catch (\Throwable $e) {
            $this->pdo()->rollBack();
            throw $e;
        }
    }

    public function deleteSongByRunId(int $runId): ?array
    {
        $run = $this->run($runId);

        if ($run === null) {
            return null;
        }

        $songId = (int)$run['song_id'];

        $stmt = $this->pdo()->prepare(
            'SELECT id, input_path FROM benchmark_runs WHERE song_id=? ORDER BY id'
        );
        $stmt->execute([$songId]);
        $runs = $stmt->fetchAll();

        $runIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            $runs
        );

        $audioPaths = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => (string)$row['input_path'],
            $runs
        ))));

        $this->pdo()->beginTransaction();

        try {
            $delete = $this->pdo()->prepare('DELETE FROM songs WHERE id=?');
            $delete->execute([$songId]);
            $this->pdo()->commit();
        } catch (\Throwable $e) {
            $this->pdo()->rollBack();
            throw $e;
        }

        return [
            'song_id' => $songId,
            'run_ids' => $runIds,
            'audio_paths' => $audioPaths,
        ];
    }

    public function globalScores(): array
    {
        $sql = <<<'SQL'
SELECT
    ar.algorithm,
    COUNT(DISTINCT ar.run_id) AS total_runs,
    SUM(CASE WHEN rv.status='approved' THEN 1 ELSE 0 END) AS approved,
    SUM(CASE WHEN rv.status='rejected' THEN 1 ELSE 0 END) AS rejected,
    SUM(CASE WHEN rv.status IS NOT NULL THEN 1 ELSE 0 END) AS reviewed
FROM algorithm_results ar
JOIN benchmark_runs br ON br.id = ar.run_id AND br.status='done'
LEFT JOIN algorithm_reviews rv
  ON rv.run_id = ar.run_id
 AND rv.algorithm = ar.algorithm
GROUP BY ar.algorithm
ORDER BY ar.algorithm
SQL;

        $rows = $this->pdo()->query($sql)->fetchAll();

        foreach ($rows as &$row) {
            $reviewed = (int)$row['reviewed'];
            $approved = (int)$row['approved'];

            $row['score_percent'] = $reviewed > 0
                ? round(($approved / $reviewed) * 100.0, 1)
                : null;

            $row['unreviewed'] = (int)$row['total_runs'] - $reviewed;
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                $as = $a['score_percent'] ?? -1;
                $bs = $b['score_percent'] ?? -1;

                if ($as === $bs) {
                    return strcmp((string)$a['algorithm'], (string)$b['algorithm']);
                }

                return $bs <=> $as;
            }
        );

        return $rows;
    }

    public function reportRows(): array
    {
        $sql = <<<'SQL'
SELECT
    br.id AS run_id,
    s.title,
    s.artist,
    br.detected_signature,
    br.requested_signature,
    br.tempo,
    ar.algorithm,
    ar.phase,
    rv.status AS review_status,
    rr.reference,
    rr.comment,
    rr.reviewed_at
FROM benchmark_runs br
JOIN songs s ON s.id = br.song_id
JOIN algorithm_results ar ON ar.run_id = br.id
LEFT JOIN algorithm_reviews rv
  ON rv.run_id = ar.run_id
 AND rv.algorithm = ar.algorithm
LEFT JOIN run_reviews rr
  ON rr.run_id = br.id
WHERE br.status='done'
ORDER BY s.title COLLATE NOCASE, s.artist COLLATE NOCASE, ar.algorithm
SQL;

        $rows = $this->pdo()->query($sql)->fetchAll();

        foreach ($rows as &$row) {
            $row['review_status'] = $row['review_status'] ?: 'unreviewed';
        }

        return $rows;
    }

    public function queueAnalysisJob(
        int $runId,
        string $audioPath,
        string $signature
    ): int {
        $run = $this->run($runId);
        if ($run === null) {
            throw new \RuntimeException('run_not_found');
        }

        $request = [
            'run_id' => $runId,
            'database' => $this->path(),
            'signature' => $signature,
            'deps' => (string)(getenv('EZSTUDIO_DEP_ROOT') ?: dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'deps'),
            'keep_upload' => ((string)(getenv('KEEP_UPLOADS') ?: '1')) !== '0',
            'audio_sha256' => (string)($run['audio_sha256'] ?? ''),
        ];
        $now = gmdate('c');

        $stmt = $this->pdo()->prepare(
            'INSERT INTO analysis_jobs(
                run_id,scientific_run_id,song_id,source_path,
                kind,status,progress,request_json,error,created_at,updated_at
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT(run_id,kind)
             DO UPDATE SET
                song_id=excluded.song_id,
                source_path=excluded.source_path,
                status=excluded.status,
                progress=excluded.progress,
                request_json=excluded.request_json,
                error=NULL,
                updated_at=excluded.updated_at'
        );
        $stmt->execute([
            $runId,
            null,
            (int)$run['song_id'],
            $audioPath,
            'benchmark',
            'queued',
            0,
            json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            null,
            $now,
            $now,
        ]);

        $select = $this->pdo()->prepare(
            "SELECT id FROM analysis_jobs WHERE run_id=? AND kind='benchmark'"
        );
        $select->execute([$runId]);
        $jobId = (int)$select->fetchColumn();

        $this->pdo()->prepare(
            "UPDATE benchmark_runs
             SET status='running',progress=0,error=NULL,updated_at=?
             WHERE id=?"
        )->execute([$now, $runId]);

        $this->pdo()->prepare(
            'INSERT INTO run_logs(run_id,created_at,level,message)
             VALUES(?,?,?,?)'
        )->execute([
            $runId,
            $now,
            'INFO',
            'job LAB queued for EZS_orchestrator: #'.$jobId,
        ]);

        return $jobId;
    }



    public function queueScientificStemsJob(
        int $scientificRunId,
        int $songId,
        string $sourcePath,
        string $audioHash,
        string $storageRoot,
        bool $force,
        string $engineProfile,
        ?int $parentJobId = null
    ): int {
        if ($scientificRunId <= 0) {
            throw new \InvalidArgumentException('scientific_run_id_required');
        }
        if ($songId <= 0) {
            throw new \InvalidArgumentException('song_id_required');
        }
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('stems_source_missing');
        }

        $request = [
            'scientific_run_id' => $scientificRunId,
            'audio_hash' => $audioHash,
            'storage_root' => $storageRoot,
            'force' => $force,
            'engine_profile' => $engineProfile,
            'parent_job_id' => $parentJobId,
            'output_contract' => 'ezstudio.stems.v1',
        ];
        $now = gmdate('c');

        $stmt = $this->pdo()->prepare(
            'INSERT INTO analysis_jobs(
                run_id,scientific_run_id,song_id,source_path,
                kind,status,progress,request_json,error,created_at,updated_at,dispatch_owner
             ) VALUES(NULL,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $scientificRunId,
            $songId,
            $sourcePath,
            'stems',
            'queued',
            0,
            json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            null,
            $now,
            $now,
            'lab',
        ]);

        return (int)$this->pdo()->lastInsertId();
    }

    public function queueScientificChordsJob(
        int $benchmarkRunId,
        int $scientificRunId,
        string $selectionRequestPath,
        string $signature
    ): int {
        $run = $this->run($benchmarkRunId);
        if ($run === null) {
            throw new \RuntimeException('benchmark_run_not_found');
        }
        if ($scientificRunId <= 0) {
            throw new \InvalidArgumentException('scientific_run_id_required');
        }
        if (!is_file($selectionRequestPath)) {
            throw new \RuntimeException('selection_request_missing');
        }

        $request = [
            'run_id' => $benchmarkRunId,
            'scientific_run_id' => $scientificRunId,
            'database' => $this->path(),
            'signature' => $signature,
            'deps' => (string)(getenv('EZSTUDIO_DEP_ROOT') ?: dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'deps'),
            'keep_upload' => true,
            'audio_sha256' => (string)($run['audio_sha256'] ?? ''),
            'selection_request' => $selectionRequestPath,
            'execution_contract' => 'ezstudio.chords.selection.v1',
        ];
        $now = gmdate('c');

        $stmt = $this->pdo()->prepare(
            'INSERT INTO analysis_jobs(
                run_id,scientific_run_id,song_id,source_path,
                kind,status,progress,request_json,error,created_at,updated_at,dispatch_owner
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $benchmarkRunId,
            $scientificRunId,
            (int)$run['song_id'],
            (string)$run['input_path'],
            'chords_scientific',
            'queued',
            0,
            json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            null,
            $now,
            $now,
            'lab',
        ]);
        $jobId = (int)$this->pdo()->lastInsertId();

        $this->pdo()->prepare(
            "UPDATE benchmark_runs
             SET status='running',progress=0,error=NULL,updated_at=?
             WHERE id=?"
        )->execute([$now, $benchmarkRunId]);

        $this->pdo()->prepare(
            'INSERT INTO run_logs(run_id,created_at,level,message)
             VALUES(?,?,?,?)'
        )->execute([
            $benchmarkRunId,
            $now,
            'INFO',
            'scientific CHORDS job queued for EZS_orchestrator: #'.$jobId
                .' ; scientific_run='.$scientificRunId,
        ]);

        return $jobId;
    }

    private function isDistributedAnalysisJob(array $job): bool
    {
        // PROFILE review jobs belong to the canonical Orchestrator queue.
        // The historical XPS polling worker must remain disabled.
        if ((string)($job['kind'] ?? '') === 'assistant.profile.review') {
            return false;
        }

        $request = json_decode((string)($job['request_json'] ?? ''), true);
        if (!is_array($request)) {
            return false;
        }

        $distributed = $request['distributed'] ?? null;
        return is_array($distributed)
            && ($distributed['enabled'] ?? false) === true;
    }

    public function analysisQueue(int $limit = 100): array
    {
        $effectiveLimit = max(1, min(500, $limit));
        $stmt = $this->pdo()->query(
            "SELECT j.id,j.kind,j.status,j.progress,j.run_id,
                    j.scientific_run_id,j.song_id,j.request_json,s.title,s.artist
             FROM analysis_jobs j
             JOIN songs s ON s.id=j.song_id
             WHERE j.status='queued'
                AND j.dispatch_owner='lab'
                AND j.kind <> 'assistant.profile.review'
             ORDER BY j.created_at ASC,j.id ASC"
        );

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($this->isDistributedAnalysisJob($row)) {
                continue;
            }

            $rows[] = [
                'job_id' => (int)$row['id'],
                'kind' => (string)$row['kind'],
                'status' => (string)$row['status'],
                'progress' => (int)$row['progress'],
                'song_id' => (int)$row['song_id'],
                'title' => (string)$row['title'],
                'artist' => (string)$row['artist'],
                'resource_class' => 'gpu',
                'priority' => 50,
            ];

            if (count($rows) >= $effectiveLimit) {
                break;
            }
        }
        return $rows;
    }

    public function analysisJobsForSongKind(int $songId, string $kind): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT j.*,s.title,s.artist,s.audio_sha256
             FROM analysis_jobs j
             JOIN songs s ON s.id=j.song_id
             WHERE j.song_id=? AND j.kind=?
             ORDER BY j.id DESC'
        );
        $stmt->execute([$songId, $kind]);
        return $stmt->fetchAll();
    }

    public function analysisJob(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT j.*,r.requested_signature,
                    s.title,s.artist,s.audio_sha256
             FROM analysis_jobs j
             LEFT JOIN benchmark_runs r ON r.id=j.run_id
             JOIN songs s ON s.id=j.song_id
             WHERE j.id=?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function claimNextAnalysisJob(): ?array
    {
        $pdo = $this->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $candidates = $pdo->query(
                "SELECT * FROM analysis_jobs j
                 WHERE j.status='queued'
                    AND j.dispatch_owner='lab'
                    AND j.kind <> 'assistant.profile.review'
                   AND NOT EXISTS (
                       SELECT 1
                       FROM analysis_resource_locks l
                       WHERE l.song_id=j.song_id
                         AND l.expires_at_epoch > CAST(strftime('%s','now') AS INTEGER)
                         AND l.phase = CASE
                             WHEN j.kind IN ('benchmark','chords','chords_scientific') THEN 'chords'
                             ELSE j.kind
                         END
                   )
                 ORDER BY j.created_at ASC,j.id ASC"
            )->fetchAll();

            $row = null;
            foreach ($candidates as $candidate) {
                if (!$this->isDistributedAnalysisJob($candidate)) {
                    $row = $candidate;
                    break;
                }
            }

            if (!$row) {
                $pdo->commit();
                return null;
            }

            $now = gmdate('c');
            $stmt = $pdo->prepare(
                "UPDATE analysis_jobs
                 SET status='running',progress=1,updated_at=?,error=NULL
                 WHERE id=? AND status='queued'"
            );
            $stmt->execute([$now, (int)$row['id']]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }

            if ($row['run_id'] !== null) {
                $pdo->prepare(
                    "UPDATE benchmark_runs
                     SET status='running',progress=1,updated_at=?,error=NULL
                     WHERE id=?"
                )->execute([$now, (int)$row['run_id']]);
            }

            $pdo->commit();
            return $this->analysisJob((int)$row['id']);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function analysisJobContext(int $id): array
    {
        $job = $this->analysisJob($id);
        if ($job === null) {
            throw new \RuntimeException('job_not_found');
        }

        $request = json_decode((string)$job['request_json'], true);
        if (!is_array($request)) {
            throw new \RuntimeException('invalid_job_request');
        }

        if ((string)$job['kind'] === 'assistant.profile.review') {
            $request['expertise_payload'] = (new ProfileExternalReviewService($this))->assertionsForRun((int)$job['scientific_run_id']);
        }

        $tmpRoot = rtrim(
            (string)(getenv('EZSTUDIO_TMP_ROOT') ?: dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'tmp'),
            '\\/'
        );
        $progressFile = $tmpRoot
            .DIRECTORY_SEPARATOR.'jobs'
            .DIRECTORY_SEPARATOR.(string)$id
            .DIRECTORY_SEPARATOR.'progress.json';

        return [
            'protocol' => 'ezscore.analysis-job.v2',
            'job_id' => (int)$job['id'],
            'kind' => (string)$job['kind'],
            'status' => (string)$job['status'],
            'resource_class' => 'gpu',
            'priority' => 50,
            'song_id' => (int)$job['song_id'],
            'paths' => [
                'source' => (string)$job['source_path'],
                'progress_file' => $progressFile,
            ],
            'request' => $request,
            'song' => [
                'title' => (string)$job['title'],
                'artist' => (string)$job['artist'],
            ],
        ];
    }

    public function updateAnalysisJobProgress(int $id, int $progress): bool
    {
        if ($progress < 0 || $progress > 100) {
            throw new \InvalidArgumentException('progress_must_be_0_100');
        }

        $job = $this->analysisJob($id);
        if ($job === null || (string)$job['status'] !== 'running') {
            return false;
        }

        $now = gmdate('c');
        $this->pdo()->prepare(
            "UPDATE analysis_jobs SET progress=?,updated_at=? WHERE id=?"
        )->execute([$progress, $now, $id]);
        if ($job['run_id'] !== null) {
            $this->pdo()->prepare(
                "UPDATE benchmark_runs SET progress=?,updated_at=? WHERE id=?"
            )->execute([$progress, $now, (int)$job['run_id']]);
        }
        return true;
    }

    public function completeAnalysisJob(int $id): void
    {
        $job = $this->analysisJob($id);
        if ($job === null) {
            throw new \RuntimeException('job_not_found');
        }
        if ((string)$job['status'] !== 'running') {
            throw new \RuntimeException('job_not_running');
        }

        if (in_array((string)$job['kind'], ['benchmark','chords_scientific'], true)) {
            $run = $this->run((int)$job['run_id']);
            if ($run === null || (string)$run['status'] !== 'done') {
                throw new \RuntimeException('benchmark_run_not_done');
            }
        }

        $this->pdo()->prepare(
            "UPDATE analysis_jobs
             SET status='completed',progress=100,error=NULL,updated_at=?
             WHERE id=?"
        )->execute([gmdate('c'), $id]);
    }

    public function failAnalysisJob(int $id, string $error): void
    {
        $job = $this->analysisJob($id);
        if ($job === null) {
            return;
        }

        $message = mb_substr(
            trim($error) !== '' ? trim($error) : 'orchestrator_job_failed',
            0,
            1000
        );
        $now = gmdate('c');

        $this->pdo()->prepare(
            "UPDATE analysis_jobs
             SET status='failed',error=?,updated_at=?
             WHERE id=?"
        )->execute([$message, $now, $id]);

        if ($job['run_id'] !== null) {
            $run = $this->run((int)$job['run_id']);
            if ($run !== null && (string)$run['status'] !== 'done') {
                $this->pdo()->prepare(
                    "UPDATE benchmark_runs
                     SET status='error',error=?,updated_at=?
                     WHERE id=?"
                )->execute([$message, $now, (int)$job['run_id']]);
            }
        }
    }



    public function cancelStaleAnalysisJobsForSongs(
        array $songIds,
        int $staleSeconds = 60
    ): array {
        $songIds = array_values(array_unique(array_map('intval', $songIds)));
        $songIds = array_values(array_filter(
            $songIds,
            static fn(int $id): bool => $id > 0
        ));
        if ($songIds === []) return [];

        $staleSeconds = max(30, $staleSeconds);
        $cutoff = gmdate('c', time() - $staleSeconds);
        $marks = implode(',', array_fill(0, count($songIds), '?'));

        $stmt = $this->pdo()->prepare(
            "SELECT id,kind,status,updated_at
             FROM analysis_jobs
             WHERE song_id IN ($marks)
               AND status IN ('running','cancelling')
               AND dispatch_owner='lab'
               AND updated_at <= ?
             ORDER BY id"
        );
        $i = 1;
        foreach ($songIds as $id) {
            $stmt->bindValue($i++, $id, \PDO::PARAM_INT);
        }
        $stmt->bindValue($i, $cutoff, \PDO::PARAM_STR);
        $stmt->execute();
        $stale = $stmt->fetchAll();

        if ($stale === []) return [];

        $ids = array_map(
            static fn(array $row): int => (int)$row['id'],
            $stale
        );
        $jobMarks = implode(',', array_fill(0, count($ids), '?'));
        $update = $this->pdo()->prepare(
            "UPDATE analysis_jobs
             SET status='cancelled',
                 error='catalog_delete_stale_orphan_cancelled',
                 updated_at=?
             WHERE id IN ($jobMarks)
               AND status IN ('running','cancelling')"
        );
        $i = 1;
        $update->bindValue($i++, gmdate('c'), \PDO::PARAM_STR);
        foreach ($ids as $id) {
            $update->bindValue($i++, $id, \PDO::PARAM_INT);
        }
        $update->execute();

        return $stale;
    }

    public function requestAnalysisCancellationForSongs(array $songIds): array
    {
        $songIds = array_values(array_unique(array_map('intval', $songIds)));
        $songIds = array_values(array_filter(
            $songIds,
            static fn(int $id): bool => $id > 0
        ));
        if ($songIds === []) return [];

        $marks = implode(',', array_fill(0, count($songIds), '?'));
        $ownerCheck = $this->pdo()->prepare(
            "SELECT id FROM analysis_jobs WHERE song_id IN ($marks) AND dispatch_owner='orchestrator' AND status IN ('queued','running','cancelling') ORDER BY id LIMIT 1"
        );
        $ownerCheck->execute($songIds);
        $globalId = $ownerCheck->fetchColumn();
        if ($globalId !== false) {
            throw new \RuntimeException('orchestrator_cancel_requires_global_protocol:job='.(int)$globalId);
        }
        $pdo = $this->pdo();
        $now = gmdate('c');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE analysis_jobs
                 SET status='cancelled',
                     error='catalog_delete_cancelled_before_claim',
                     progress=0,
                     updated_at=?
                 WHERE song_id IN ($marks) AND status='queued'"
            );
            $stmt->execute([$now, ...$songIds]);

            $stmt = $pdo->prepare(
                "UPDATE analysis_jobs
                 SET status='cancelling',
                     error='catalog_delete_cancellation_requested',
                     updated_at=?
                 WHERE song_id IN ($marks) AND status='running'"
            );
            $stmt->execute([$now, ...$songIds]);

            $stmt = $pdo->prepare(
                "SELECT id,kind,status,progress,updated_at
                 FROM analysis_jobs
                 WHERE song_id IN ($marks)
                 ORDER BY id"
            );
            $stmt->execute($songIds);
            $rows = $stmt->fetchAll();
            $pdo->commit();
            return $rows;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function activeAnalysisJobsForSongsKind(
        array $songIds,
        string $kind
    ): array {
        $songIds = array_values(array_unique(array_filter(
            array_map('intval', $songIds),
            static fn(int $id): bool => $id > 0
        )));
        $kind = trim($kind);

        if ($songIds === [] || $kind === '') {
            return [];
        }

        $marks = implode(',', array_fill(0, count($songIds), '?'));
        $stmt = $this->pdo()->prepare(
            "SELECT id,song_id,kind,status,progress,scientific_run_id,updated_at
             FROM analysis_jobs
             WHERE song_id IN ($marks)
               AND kind=?
               AND status IN ('queued','running','cancelling')
             ORDER BY id"
        );

        $i = 1;
        foreach ($songIds as $songId) {
            $stmt->bindValue($i++, $songId, \PDO::PARAM_INT);
        }
        $stmt->bindValue($i, $kind, \PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Authorized terminal mirror; proof is transaction-local, never retained as a bypass. */
    public function reconcileGlobalTerminal(int $labJobId, int $globalJobId, string $terminal): void
    {
        if ($labJobId < 1 || $globalJobId < 1 || !in_array($terminal,['failed','cancelled'],true)) {
            throw new \DomainException('invalid_global_terminal_reconciliation');
        }
        $pdo = $this->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $pdo->prepare('SELECT j.id,j.status,j.dispatch_owner,j.scientific_run_id,o.state AS admission_state,o.global_job_id,r.state AS scientific_state FROM analysis_jobs j JOIN global_queue_admission_outbox o ON o.job_id=j.id LEFT JOIN scientific_runs r ON r.id=j.scientific_run_id WHERE j.id=?');
            $stmt->execute([$labJobId]);
            $current = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($current) || $current['dispatch_owner'] !== 'orchestrator'
                || $current['admission_state'] !== 'admitted'
                || (int)$current['global_job_id'] !== $globalJobId
                || (int)$current['scientific_run_id'] < 1) {
                throw new \DomainException('global_terminal_identity_conflict');
            }
            $scienceTarget = $terminal === 'failed' ? 'error' : 'cancelled';
            if ($current['status'] === $terminal && $current['scientific_state'] === $scienceTarget) {
                $pdo->commit();
                return;
            }
            if ($current['status'] !== 'queued'
                || !in_array($current['scientific_state'], ['queued', $scienceTarget], true)) {
                throw new \DomainException('global_terminal_local_state_conflict');
            }
            $proof = $pdo->prepare('INSERT INTO global_queue_terminal_receipts(job_id,global_job_id,terminal_state) VALUES(?,?,?)');
            $proof->execute([$labJobId,$globalJobId,$terminal]);
            $now = gmdate('c');
            $update = $pdo->prepare("UPDATE analysis_jobs SET status=?,progress=0,error=?,updated_at=? WHERE id=? AND status='queued' AND dispatch_owner='orchestrator'");
            $update->execute([$terminal, 'global_authoritative_'.$terminal,$now,$labJobId]);
            if ($update->rowCount() !== 1) throw new \DomainException('global_terminal_update_conflict');
            // A scientific run may already carry the matching terminal result.
            // Preserve its finished_at and never rewrite or reinterpret its evidence.
            if ($current['scientific_state'] === 'queued') {
                $runs = $pdo->prepare("UPDATE scientific_runs SET state=?,finished_at=? WHERE id=? AND state='queued'");
                $runs->execute([$scienceTarget,$now,(int)$current['scientific_run_id']]);
                if ($runs->rowCount() !== 1) throw new \DomainException('global_scientific_reconciliation_conflict');
            }
            $pdo->prepare('DELETE FROM global_queue_terminal_receipts WHERE job_id=?')->execute([$labJobId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function requestAnalysisJobCancellation(int $id): array
    {
        $job = $this->analysisJob($id);
        if ($job === null) {
            throw new \RuntimeException('job_not_found');
        }

        // Orchestrator is the sole authority for globally dispatched jobs.
        // A local cancellation must not violate the SQLite owner fence.
        if ((string)($job['dispatch_owner'] ?? 'lab') === 'orchestrator') {
            throw new \RuntimeException('orchestrator_cancel_requires_global_protocol');
        }
        $status = (string)$job['status'];
        if (!in_array($status, ['queued','running','cancelling'], true)) {
            return $job;
        }

        $now = gmdate('c');
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            if ($status === 'queued') {
                $pdo->prepare(
                    "UPDATE analysis_jobs
                     SET status='cancelled',
                         progress=0,
                         error='cancelled_by_user_before_claim',
                         updated_at=?
                     WHERE id=? AND status='queued'"
                )->execute([$now, $id]);

                $this->markScientificRunCancelledFromJob(
                    $job,
                    'cancelled_by_user_before_claim',
                    $now
                );
            } else {
                $pdo->prepare(
                    "UPDATE analysis_jobs
                     SET status='cancelling',
                         error='cancel_requested_by_user',
                         updated_at=?
                     WHERE id=? AND status IN ('running','cancelling')"
                )->execute([$now, $id]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->analysisJob($id) ?? $job;
    }

    private function markScientificRunCancelledFromJob(
        array $job,
        string $reason,
        string $now
    ): void {
        $scientificRunId = (int)($job['scientific_run_id'] ?? 0);
        if ($scientificRunId <= 0) {
            return;
        }

        $tables = $this->pdo()->query(
            "SELECT name FROM sqlite_master WHERE type='table'"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('scientific_runs', $tables, true)) {
            return;
        }

        $this->pdo()->prepare(
            "UPDATE scientific_runs
             SET state='cancelled', finished_at=?
             WHERE id=? AND state!='done'"
        )->execute([$now, $scientificRunId]);

        if (in_array('scientific_run_labels', $tables, true)) {
            $this->pdo()->prepare(
                "INSERT INTO scientific_run_labels(run_id,label,value)
                 VALUES(?,?,?)
                 ON CONFLICT(run_id,label)
                 DO UPDATE SET value=excluded.value"
            )->execute([$scientificRunId, 'last_error', $reason]);
        }
    }

    public function markAnalysisJobCancelled(int $id): void
    {
        $job = $this->analysisJob($id);
        if ($job === null) {
            throw new \RuntimeException('job_not_found');
        }
        if ((string)($job['dispatch_owner'] ?? 'lab') === 'orchestrator') {
            throw new \RuntimeException('orchestrator_cancel_requires_global_protocol');
        }
        if (!in_array((string)$job['status'], ['cancelling','cancelled'], true)) {
            throw new \RuntimeException('job_not_cancelling');
        }

        $now = gmdate('c');
        $this->pdo()->prepare(
            "UPDATE analysis_jobs
             SET status='cancelled',
                 error=CASE
                     WHEN error='cancel_requested_by_user' THEN 'cancelled_by_user'
                     ELSE 'catalog_delete_cancelled'
                 END,
                 updated_at=?
             WHERE id=?"
        )->execute([$now, $id]);

        $this->markScientificRunCancelledFromJob(
            $job,
            'cancelled_by_user_or_catalog',
            $now
        );

        if ($job['run_id'] !== null) {
            $run = $this->run((int)$job['run_id']);
            if ($run !== null && (string)$run['status'] !== 'done') {
                $this->pdo()->prepare(
                    "UPDATE benchmark_runs
                     SET status='error',
                         error='cancelled_by_catalog_delete',
                         updated_at=?
                     WHERE id=?"
                )->execute([$now, (int)$job['run_id']]);
            }
        }
    }

    public function reconcileStaleCancellingAnalysisJobsForSongs(
        array $songIds,
        int $staleSeconds = 60
    ): array {
        $songIds = array_values(array_unique(array_filter(
            array_map('intval', $songIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($songIds === []) {
            return [];
        }

        $cutoff = gmdate('c', time() - max(30, $staleSeconds));
        $marks = implode(',', array_fill(0, count($songIds), '?'));

        $stmt = $this->pdo()->prepare(
            "SELECT id
             FROM analysis_jobs
             WHERE song_id IN ($marks)
               AND status='cancelling'
               AND dispatch_owner='lab'
               AND updated_at <= ?
             ORDER BY id"
        );

        $i = 1;
        foreach ($songIds as $songId) {
            $stmt->bindValue($i++, $songId, \PDO::PARAM_INT);
        }
        $stmt->bindValue($i, $cutoff, \PDO::PARAM_STR);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $job = $this->analysisJob($id);
            if ($job === null || (string)$job['status'] !== 'cancelling') {
                continue;
            }
            $this->markAnalysisJobCancelled($id);
        }

        return $rows;
    }

    public function activeOrCancellingAnalysisJobsForSongs(array $songIds): array
    {
        $songIds = array_values(array_unique(array_map('intval', $songIds)));
        $songIds = array_values(array_filter(
            $songIds,
            static fn(int $id): bool => $id > 0
        ));
        if ($songIds === []) return [];

        $marks = implode(',', array_fill(0, count($songIds), '?'));
        $stmt = $this->pdo()->prepare(
            "SELECT id,kind,status,progress,updated_at
             FROM analysis_jobs
             WHERE song_id IN ($marks)
               AND status IN ('queued','running','cancelling')
             ORDER BY id"
        );
        $stmt->execute($songIds);
        return $stmt->fetchAll();
    }


    private function ensureAnalysisResourceLockSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS analysis_resource_locks (
    song_id INTEGER NOT NULL,
    phase TEXT NOT NULL,
    operation TEXT NOT NULL,
    owner TEXT NOT NULL,
    created_at TEXT NOT NULL,
    expires_at_epoch INTEGER NOT NULL,
    PRIMARY KEY(song_id, phase),
    FOREIGN KEY(song_id) REFERENCES songs(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_analysis_resource_locks_owner
ON analysis_resource_locks(owner);

CREATE TRIGGER IF NOT EXISTS trg_analysis_jobs_phase_lock_insert
BEFORE INSERT ON analysis_jobs
BEGIN
    SELECT CASE WHEN EXISTS(
        SELECT 1
        FROM analysis_resource_locks l
        WHERE l.song_id = NEW.song_id
          AND l.expires_at_epoch > CAST(strftime('%s','now') AS INTEGER)
          AND l.phase = CASE
              WHEN NEW.kind IN ('benchmark','chords','chords_scientific') THEN 'chords'
              ELSE NEW.kind
          END
    ) THEN RAISE(ABORT, 'analysis_phase_locked') END;
END;

CREATE TRIGGER IF NOT EXISTS trg_analysis_jobs_phase_lock_claim
BEFORE UPDATE OF status ON analysis_jobs
WHEN NEW.status='running' AND OLD.status='queued'
BEGIN
    SELECT CASE WHEN EXISTS(
        SELECT 1
        FROM analysis_resource_locks l
        WHERE l.song_id = NEW.song_id
          AND l.expires_at_epoch > CAST(strftime('%s','now') AS INTEGER)
          AND l.phase = CASE
              WHEN NEW.kind IN ('benchmark','chords','chords_scientific') THEN 'chords'
              ELSE NEW.kind
          END
    ) THEN RAISE(ABORT, 'analysis_phase_locked') END;
END;
SQL);
    }

    private function phaseKindSql(string $alias = 'analysis_jobs'): string
    {
        return "CASE
            WHEN {$alias}.kind IN ('benchmark','chords','chords_scientific') THEN 'chords'
            ELSE {$alias}.kind
        END";
    }

    public function acquireAnalysisPhaseLocks(
        array $songIds,
        array $phases,
        string $owner,
        int $ttlSeconds = 300
    ): void {
        $songIds = array_values(array_unique(array_filter(
            array_map('intval', $songIds),
            static fn(int $id): bool => $id > 0
        )));
        $phases = array_values(array_unique(array_filter(
            array_map('strval', $phases),
            static fn(string $phase): bool => in_array(
                $phase, ['profile','stems','chords','lyrics'], true
            )
        )));
        if ($songIds === [] || $phases === []) {
            throw new \InvalidArgumentException('analysis_phase_lock_scope_empty');
        }

        $pdo = $this->pdo();
        $nowEpoch = time();
        $expires = $nowEpoch + max(60, $ttlSeconds);
        $now = gmdate('c');

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->prepare(
                'DELETE FROM analysis_resource_locks WHERE expires_at_epoch <= ?'
            )->execute([$nowEpoch]);

            $insert = $pdo->prepare(
                'INSERT INTO analysis_resource_locks(
                    song_id,phase,operation,owner,created_at,expires_at_epoch
                 ) VALUES(?,?,?,?,?,?)'
            );
            foreach ($songIds as $songId) {
                foreach ($phases as $phase) {
                    $insert->execute([
                        $songId,
                        $phase,
                        'delete',
                        $owner,
                        $now,
                        $expires,
                    ]);
                }
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \RuntimeException(
                'analysis_phase_busy_or_locked:'.$e->getMessage(),
                0,
                $e
            );
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function releaseAnalysisPhaseLocks(string $owner): void
    {
        $this->pdo()->prepare(
            'DELETE FROM analysis_resource_locks WHERE owner=?'
        )->execute([$owner]);
    }

    public function requestPhaseCancellation(
        array $songIds,
        array $phases,
        int $staleSeconds = 60
    ): array {
        $songIds = array_values(array_unique(array_filter(
            array_map('intval', $songIds),
            static fn(int $id): bool => $id > 0
        )));
        $phases = array_values(array_unique(array_map('strval', $phases)));
        if ($songIds === [] || $phases === []) {
            return [];
        }

        $songMarks = implode(',', array_fill(0, count($songIds), '?'));
        $phaseMarks = implode(',', array_fill(0, count($phases), '?'));
        $phaseExpr = $this->phaseKindSql('analysis_jobs');
        $ownerCheck = $this->pdo()->prepare(
            "SELECT id FROM analysis_jobs WHERE song_id IN ($songMarks) AND $phaseExpr IN ($phaseMarks) AND dispatch_owner='orchestrator' AND status IN ('queued','running','cancelling') ORDER BY id LIMIT 1"
        );
        $ownerCheck->execute([...$songIds, ...$phases]);
        $globalId = $ownerCheck->fetchColumn();
        if ($globalId !== false) {
            throw new \RuntimeException('orchestrator_cancel_requires_global_protocol:job='.(int)$globalId);
        }
        $pdo = $this->pdo();
        $now = gmdate('c');
        $cutoff = gmdate('c', time() - max(30, $staleSeconds));

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE analysis_jobs
                 SET status='cancelled',
                     error='phase_delete_cancelled_before_claim',
                     updated_at=?
                 WHERE song_id IN ($songMarks)
                   AND $phaseExpr IN ($phaseMarks)
                   AND status='queued'"
            );
            $stmt->execute([$now, ...$songIds, ...$phases]);

            $stmt = $pdo->prepare(
                "UPDATE analysis_jobs
                 SET status='cancelled',
                     error='phase_delete_stale_job_reconciled',
                     updated_at=?
                 WHERE updated_at <= ?
                   AND song_id IN ($songMarks)
                   AND $phaseExpr IN ($phaseMarks)
                   AND status IN ('running','cancelling')"
            );
            $stmt->execute([$now, $cutoff, ...$songIds, ...$phases]);

            $stmt = $pdo->prepare(
                "UPDATE analysis_jobs
                 SET status='cancelling',
                     error='phase_delete_cancellation_requested',
                     updated_at=?
                 WHERE song_id IN ($songMarks)
                   AND $phaseExpr IN ($phaseMarks)
                   AND status='running'"
            );
            $stmt->execute([$now, ...$songIds, ...$phases]);

            if ($this->tableExistsForMutex('scientific_runs')) {
                $stmt = $pdo->prepare(
                    "UPDATE scientific_runs
                     SET state='cancelled',
                         finished_at=COALESCE(finished_at, ?)
                     WHERE id IN (
                         SELECT scientific_run_id
                         FROM analysis_jobs
                         WHERE song_id IN ($songMarks)
                           AND $phaseExpr IN ($phaseMarks)
                           AND status='cancelled'
                           AND scientific_run_id IS NOT NULL
                     )
                       AND state!='done'"
                );
                $stmt->execute([$now, ...$songIds, ...$phases]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->activePhaseJobs($songIds, $phases);
    }

    public function activePhaseJobs(array $songIds, array $phases): array
    {
        $songIds = array_values(array_unique(array_filter(
            array_map('intval', $songIds),
            static fn(int $id): bool => $id > 0
        )));
        $phases = array_values(array_unique(array_map('strval', $phases)));
        if ($songIds === [] || $phases === []) {
            return [];
        }

        $songMarks = implode(',', array_fill(0, count($songIds), '?'));
        $phaseMarks = implode(',', array_fill(0, count($phases), '?'));
        $phaseExpr = $this->phaseKindSql('analysis_jobs');

        $stmt = $this->pdo()->prepare(
            "SELECT id,kind,status,progress,updated_at,scientific_run_id
             FROM analysis_jobs
             WHERE song_id IN ($songMarks)
               AND $phaseExpr IN ($phaseMarks)
               AND status IN ('queued','running','cancelling')
             ORDER BY id"
        );
        $stmt->execute([...$songIds, ...$phases]);
        return $stmt->fetchAll();
    }

    private function tableExistsForMutex(string $name): bool
    {
        $stmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?"
        );
        $stmt->execute([$name]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function exportData(): array
    {
        $runs = $this->allRuns();

        foreach ($runs as &$run) {
            $runId = (int)$run['id'];
            $run['algorithms'] = $this->algorithms($runId);
            $run['review'] = $this->runReview($runId);

            if (!empty($run['result_json'])) {
                $run['result'] = json_decode((string)$run['result_json'], true);
            } else {
                $run['result'] = null;
            }

            unset($run['input_path'], $run['result_json']);
        }

        return [
            'schema_version' => 2,
            'exported_at' => gmdate('c'),
            'global_scores' => $this->globalScores(),
            'runs' => $runs,
        ];
    }

    public function allSongs(): array
    {
        $sql = "SELECT s.id,s.title,s.artist,s.source_filename,s.audio_sha256,s.created_at,
                       COUNT(r.id) AS run_count,
                       MAX(r.id) AS latest_run_id,
                       MAX(r.updated_at) AS latest_run_updated_at
                FROM songs s
                LEFT JOIN benchmark_runs r ON r.song_id=s.id
                GROUP BY s.id,s.title,s.artist,s.source_filename,s.audio_sha256,s.created_at
                ORDER BY s.title COLLATE NOCASE, s.artist COLLATE NOCASE, s.id";

        return $this->pdo()->query($sql)->fetchAll();
    }

    public function song(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT id,title,artist,source_filename,audio_sha256,created_at
             FROM songs
             WHERE id=?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function runsForSong(int $songId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT r.*, s.title, s.artist, s.source_filename, s.audio_sha256,
                    rr.reference AS review_reference,
                    rr.comment AS review_comment,
                    rr.reviewed_at
             FROM benchmark_runs r
             JOIN songs s ON s.id=r.song_id
             LEFT JOIN run_reviews rr ON rr.run_id=r.id
             WHERE r.song_id=?
             ORDER BY r.id DESC"
        );
        $stmt->execute([$songId]);

        return $stmt->fetchAll();
    }

    public function latestRunForSong(int $songId): ?array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT r.*, s.title, s.artist, s.source_filename, s.audio_sha256
             FROM benchmark_runs r
             JOIN songs s ON s.id=r.song_id
             WHERE r.song_id=?
             ORDER BY r.id DESC
             LIMIT 1"
        );
        $stmt->execute([$songId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

}
