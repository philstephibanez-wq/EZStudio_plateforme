CREATE TABLE job_queue (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, source VARCHAR(50) NOT NULL, status VARCHAR(20) NOT NULL, payload CLOB NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, progress INTEGER NOT NULL DEFAULT 0, has_error INTEGER NOT NULL DEFAULT 0, error_message TEXT DEFAULT NULL, attempts INTEGER NOT NULL DEFAULT 0, worker_pid INTEGER DEFAULT NULL);

CREATE INDEX idx_job_queue_source_status
ON job_queue (source, status);

CREATE INDEX idx_job_queue_status_created
ON job_queue (status, created_at, id);
