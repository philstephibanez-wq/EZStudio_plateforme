<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

final class QueueDatabase
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

        $pdo = new PDO('sqlite:' . $this->databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        $this->pdo = $pdo;
        $this->ensureSchema();

        return $this->pdo;
    }

    public function path(): string
    {
        return $this->databasePath;
    }

    private function ensureSchema(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS job_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    source VARCHAR(50) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'waiting',
    progress INTEGER NOT NULL DEFAULT 0,
    has_error INTEGER NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    payload CLOB NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_job_queue_status_created
ON job_queue (status, created_at, id);

CREATE INDEX IF NOT EXISTS idx_job_queue_source_status
ON job_queue (source, status);
SQL;

        $this->pdo->exec($sql);

        $cols = [];
        foreach ($this->pdo->query('PRAGMA table_info(job_queue)')->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $cols[(string) $col['name']] = true;
        }

        if (!isset($cols['progress'])) {
            $this->pdo->exec('ALTER TABLE job_queue ADD COLUMN progress INTEGER NOT NULL DEFAULT 0');
        }
        if (!isset($cols['has_error'])) {
            $this->pdo->exec('ALTER TABLE job_queue ADD COLUMN has_error INTEGER NOT NULL DEFAULT 0');
        }
        if (!isset($cols['error_message'])) {
            $this->pdo->exec('ALTER TABLE job_queue ADD COLUMN error_message TEXT DEFAULT NULL');
        }
        if (!isset($cols['attempts'])) {
            $this->pdo->exec('ALTER TABLE job_queue ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0');
        }

        // Migration douce des anciens statuts vers le contrat agnostique (waiting / progressing / ended)
        $this->pdo->exec("UPDATE job_queue SET status = 'waiting' WHERE status IN ('pending', 'queued')");
        $this->pdo->exec("UPDATE job_queue SET status = 'progressing' WHERE status = 'running'");
        $this->pdo->exec("UPDATE job_queue SET status = 'ended', progress = 100, has_error = 0 WHERE status = 'completed'");
        $this->pdo->exec("UPDATE job_queue SET status = 'ended', has_error = 1 WHERE status = 'failed'");
    }
}