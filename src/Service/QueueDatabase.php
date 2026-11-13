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
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
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
    }
}
