CREATE TABLE algorithm_results (
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

CREATE TABLE algorithm_reviews (
    run_id INTEGER NOT NULL,
    algorithm TEXT NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('approved','rejected')),
    reviewed_at TEXT NOT NULL,
    PRIMARY KEY(run_id, algorithm),
    FOREIGN KEY(run_id) REFERENCES benchmark_runs(id) ON DELETE CASCADE
);

CREATE TABLE analysis_job_leases (
    job_id INTEGER PRIMARY KEY,
    worker_id TEXT NOT NULL,
    lease_token TEXT NOT NULL UNIQUE,
    acquired_at TEXT NOT NULL,
    heartbeat_at TEXT NOT NULL,
    expires_at_epoch INTEGER NOT NULL,
    FOREIGN KEY(job_id) REFERENCES analysis_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY(worker_id) REFERENCES analysis_workers(worker_id) ON DELETE CASCADE
);

CREATE TABLE analysis_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER,
    scientific_run_id INTEGER,
    song_id INTEGER NOT NULL,
    kind TEXT NOT NULL,
    status TEXT NOT NULL,
    progress INTEGER NOT NULL DEFAULT 0,
    request_json TEXT NOT NULL,
    error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL, dispatch_owner TEXT NOT NULL DEFAULT 'lab' CHECK(dispatch_owner IN ('lab','orchestrator')),
    UNIQUE(run_id, kind),
    FOREIGN KEY(run_id) REFERENCES benchmark_runs(id) ON DELETE CASCADE,
    FOREIGN KEY(song_id) REFERENCES songs(id) ON DELETE CASCADE
);

CREATE TABLE analysis_resource_locks (
    song_id INTEGER NOT NULL,
    "phase" TEXT NOT NULL,
    operation TEXT NOT NULL,
    owner TEXT NOT NULL,
    created_at TEXT NOT NULL,
    expires_at_epoch INTEGER NOT NULL,
    PRIMARY KEY(song_id, "phase"),
    FOREIGN KEY(song_id) REFERENCES songs(id) ON DELETE CASCADE
);

CREATE TABLE analysis_workers (
    worker_id TEXT PRIMARY KEY,
    protocol TEXT NOT NULL,
    capabilities_json TEXT NOT NULL,
    state TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    last_seen_epoch INTEGER NOT NULL
);

CREATE TABLE assistant_engine_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, engine_key TEXT NOT NULL, revision_number INTEGER NOT NULL, revision_ref TEXT NOT NULL UNIQUE, fingerprint TEXT NOT NULL, params_json TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(engine_key, fingerprint));

CREATE TABLE assistant_job_checkpoints(
            id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INTEGER NOT NULL,run_id INTEGER NOT NULL,
            event_key TEXT NOT NULL,phase TEXT NOT NULL,outcome TEXT NOT NULL,detail_json TEXT NOT NULL,
            created_at TEXT NOT NULL,UNIQUE(job_id,event_key),
            FOREIGN KEY(job_id) REFERENCES analysis_jobs(id) ON DELETE CASCADE);

CREATE TABLE assistant_preset_baselines (phase TEXT PRIMARY KEY, preset_revision_id INTEGER NOT NULL, updated_at TEXT NOT NULL);

CREATE TABLE assistant_preset_revision_engines (preset_revision_id INTEGER NOT NULL, engine_revision_id INTEGER NOT NULL, position INTEGER NOT NULL, PRIMARY KEY(preset_revision_id, position));

CREATE TABLE assistant_preset_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, phase TEXT NOT NULL, revision_number INTEGER NOT NULL, revision_ref TEXT NOT NULL UNIQUE, fingerprint TEXT NOT NULL, preset_id TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'promoted', created_at TEXT NOT NULL, UNIQUE(phase, fingerprint));

CREATE TABLE assistant_run_preset (run_id INTEGER PRIMARY KEY, preset_revision_id INTEGER NOT NULL, captured_at TEXT NOT NULL);

CREATE TABLE benchmark_runs (
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
    error TEXT,
    result_json TEXT,
    FOREIGN KEY(song_id) REFERENCES songs(id) ON DELETE CASCADE
);

CREATE TABLE ezs_retry_receipts (
            global_job_id INTEGER NOT NULL, attempt INTEGER NOT NULL,lab_job_id INTEGER NOT NULL,
            state TEXT NOT NULL CHECK(state IN ('prepared','committed','aborting','aborted')),
            original_job TEXT NOT NULL,original_run TEXT,created_at TEXT NOT NULL,
            PRIMARY KEY(global_job_id,attempt));

CREATE TABLE ezstudio_schema_migrations (
    migration_id TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL
);

CREATE TABLE global_queue_admission_outbox (
    job_id INTEGER PRIMARY KEY REFERENCES analysis_jobs(id) ON DELETE CASCADE,
    state TEXT NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','admitted')),
    global_job_id INTEGER,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    updated_at TEXT NOT NULL
);

CREATE TABLE global_queue_terminal_receipts(job_id INTEGER PRIMARY KEY REFERENCES analysis_jobs(id),global_job_id INTEGER NOT NULL,terminal_state TEXT NOT NULL CHECK(terminal_state IN ('failed','cancelled')) );

CREATE TABLE judgements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL UNIQUE,
    selected_algorithm TEXT NOT NULL,
    selected_phase INTEGER NOT NULL,
    reference TEXT NOT NULL DEFAULT '',
    comment TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    FOREIGN KEY(run_id) REFERENCES benchmark_runs(id) ON DELETE CASCADE
);

CREATE TABLE "preset_module_revisions" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    "module_key" TEXT NOT NULL,
    revision_number INTEGER NOT NULL,
    revision_ref TEXT NOT NULL UNIQUE,
    fingerprint TEXT NOT NULL,
    composition_json TEXT NOT NULL,
    created_at TEXT NOT NULL, content_sha256 TEXT,
    UNIQUE("module_key", revision_number),
    UNIQUE("module_key", fingerprint)
);

CREATE TABLE "preset_phase_baselines" (
    "phase" TEXT PRIMARY KEY
        CHECK("phase" IN ('profile','stems','chords','lyrics')),
    "phase_revision_id" INTEGER NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY("phase_revision_id")
        REFERENCES "preset_phase_revisions"(id) ON DELETE RESTRICT
);

CREATE TABLE "preset_phase_revision_modules" (
    "phase_revision_id" INTEGER NOT NULL,
    "module_revision_id" INTEGER NOT NULL,
    position INTEGER NOT NULL,
    PRIMARY KEY("phase_revision_id", position),
    UNIQUE("phase_revision_id", "module_revision_id"),
    FOREIGN KEY("phase_revision_id")
        REFERENCES "preset_phase_revisions"(id) ON DELETE CASCADE,
    FOREIGN KEY("module_revision_id")
        REFERENCES "preset_module_revisions"(id) ON DELETE RESTRICT
);

CREATE TABLE "preset_phase_revision_parents" (
    "child_phase_revision_id" INTEGER NOT NULL,
    "parent_phase_revision_id" INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'evolution',
    PRIMARY KEY("child_phase_revision_id","parent_phase_revision_id",role),
    FOREIGN KEY("child_phase_revision_id")
        REFERENCES "preset_phase_revisions"(id) ON DELETE CASCADE,
    FOREIGN KEY("parent_phase_revision_id")
        REFERENCES "preset_phase_revisions"(id) ON DELETE RESTRICT
);

CREATE TABLE "preset_phase_revisions" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    "phase" TEXT NOT NULL CHECK("phase" IN ('profile','stems','chords','lyrics')),
    revision_number INTEGER NOT NULL,
    revision_ref TEXT NOT NULL UNIQUE,
    fingerprint TEXT NOT NULL,
    pipeline_revision TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'candidate'
        CHECK(status IN ('candidate','promoted','rejected','superseded')),
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL, content_sha256 TEXT,
    UNIQUE("phase", revision_number),
    UNIQUE("phase", fingerprint)
);

CREATE TABLE profile_ai_maintenance (id INTEGER PRIMARY KEY CHECK(id=1), paused INTEGER NOT NULL DEFAULT 0);

CREATE TABLE profile_ai_requests (
          run_id INTEGER PRIMARY KEY,
          status TEXT NOT NULL CHECK(status IN ('pending','running','completed','error')),
          attempt INTEGER NOT NULL DEFAULT 0,
          message TEXT NOT NULL DEFAULT '',
          updated_at TEXT NOT NULL,
          FOREIGN KEY(run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE
        );

CREATE TABLE profile_external_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL,
    revision_key TEXT NOT NULL,
    reviewer TEXT NOT NULL DEFAULT 'assistant',
    status TEXT NOT NULL CHECK(status IN ('draft','completed')),
    identity_json TEXT NOT NULL DEFAULT '{}',
    sources_json TEXT NOT NULL DEFAULT '[]',
    consensus_json TEXT NOT NULL DEFAULT '{}',
    comparison_json TEXT NOT NULL DEFAULT '{}',
    confidence REAL NOT NULL DEFAULT 0,
    schema_version TEXT NOT NULL DEFAULT 'ezstudio.profile-external-review.v1',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(run_id, revision_key),
    FOREIGN KEY(run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE
);

CREATE TABLE "profile_human_validations" (
    run_id INTEGER NOT NULL,
    subject_key TEXT NOT NULL,
    scope TEXT NOT NULL CHECK(scope IN ('phase','module')),
    item_key TEXT NOT NULL,
    module_key TEXT,
    predicted_json TEXT NOT NULL DEFAULT '{}',
    verdict TEXT NOT NULL CHECK(verdict IN ('ok','ko','unknown')),
    human_value TEXT NOT NULL DEFAULT '',
    comment TEXT NOT NULL DEFAULT '',
    phase_revision_ref TEXT,
    module_revision_ref TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    missing_expected_json TEXT NOT NULL DEFAULT '[]',
    reviewer_certainty TEXT NOT NULL DEFAULT '',
    validation_schema TEXT NOT NULL DEFAULT 'ezstudio.profile-human.v2',
    PRIMARY KEY(run_id, subject_key),
    FOREIGN KEY(run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE
);

CREATE TABLE run_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    level TEXT NOT NULL,
    message TEXT NOT NULL,
    FOREIGN KEY(run_id) REFERENCES benchmark_runs(id) ON DELETE CASCADE
);

CREATE TABLE run_reviews (
    run_id INTEGER PRIMARY KEY,
    reference TEXT NOT NULL DEFAULT '',
    comment TEXT NOT NULL DEFAULT '',
    reviewed_at TEXT NOT NULL,
    FOREIGN KEY(run_id) REFERENCES benchmark_runs(id) ON DELETE CASCADE
);

CREATE TABLE scientific_artifacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL,
    artifact_id TEXT NOT NULL UNIQUE,
    role TEXT NOT NULL,
    label TEXT NOT NULL DEFAULT '',
    sha256 TEXT NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    FOREIGN KEY(run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE
);

CREATE TABLE scientific_run_aliases (
    run_id INTEGER NOT NULL,
    source_type TEXT NOT NULL,
    source_id TEXT NOT NULL,
    PRIMARY KEY(source_type,source_id),
    FOREIGN KEY(run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE
);

CREATE TABLE scientific_run_inputs (
    run_id INTEGER NOT NULL,
    artifact_id TEXT NOT NULL,
    input_role TEXT NOT NULL,
    selected INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY(run_id,artifact_id,input_role),
    FOREIGN KEY(run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE,
    FOREIGN KEY(artifact_id) REFERENCES scientific_artifacts(artifact_id) ON DELETE RESTRICT
);

CREATE TABLE scientific_run_labels (
    run_id INTEGER NOT NULL,
    label TEXT NOT NULL,
    value TEXT NOT NULL DEFAULT '',
    PRIMARY KEY(run_id,label),
    FOREIGN KEY(run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE
);

CREATE TABLE scientific_run_parents (
    child_run_id INTEGER NOT NULL,
    parent_run_id INTEGER NOT NULL,
    role TEXT NOT NULL,
    PRIMARY KEY(child_run_id,parent_run_id,role),
    FOREIGN KEY(child_run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE,
    FOREIGN KEY(parent_run_id) REFERENCES scientific_runs(id) ON DELETE RESTRICT
);

CREATE TABLE "scientific_run_preset" (
    run_id INTEGER PRIMARY KEY,
    "phase_revision_id" INTEGER NOT NULL,
    captured_at TEXT NOT NULL,
    FOREIGN KEY(run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE,
    FOREIGN KEY("phase_revision_id")
        REFERENCES "preset_phase_revisions"(id) ON DELETE RESTRICT
);

CREATE TABLE scientific_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    song_id INTEGER NOT NULL,
    item TEXT NOT NULL CHECK(item IN ('profile','stems','chords','lyrics')),
    state TEXT NOT NULL DEFAULT 'created',
    engine_name TEXT NOT NULL DEFAULT '',
    engine_version TEXT NOT NULL DEFAULT '',
    model_name TEXT NOT NULL DEFAULT '',
    checkpoint TEXT NOT NULL DEFAULT '',
    config_json TEXT NOT NULL DEFAULT '{}',
    environment_json TEXT NOT NULL DEFAULT '{}',
    metrics_json TEXT NOT NULL DEFAULT '{}',
    diagnostics_json TEXT NOT NULL DEFAULT '{}',
    human_validation_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    finished_at TEXT,
    FOREIGN KEY(song_id) REFERENCES songs(id) ON DELETE CASCADE
);

CREATE TABLE songs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    artist TEXT NOT NULL DEFAULT '',
    source_filename TEXT NOT NULL,
    audio_sha256 TEXT NOT NULL,
    created_at TEXT NOT NULL
, profile_documentary_json TEXT DEFAULT NULL);

CREATE INDEX idx_algorithm_reviews_algorithm ON algorithm_reviews(algorithm);

CREATE INDEX idx_analysis_job_leases_expiry
ON analysis_job_leases(expires_at_epoch);

CREATE INDEX idx_analysis_job_leases_worker
ON analysis_job_leases(worker_id);

CREATE INDEX idx_analysis_jobs_status
ON analysis_jobs(status, created_at, id);

CREATE INDEX idx_analysis_resource_locks_owner
ON analysis_resource_locks(owner);

CREATE INDEX idx_analysis_workers_seen
ON analysis_workers(last_seen_epoch);

CREATE INDEX idx_preset_module_key ON preset_module_revisions(module_key, revision_number);

CREATE INDEX idx_preset_phase_revision ON preset_phase_revisions(phase, revision_number);

CREATE INDEX idx_profile_external_revisions_run_status
ON profile_external_revisions(run_id,status,updated_at);

CREATE INDEX idx_profile_human_validation_certainty ON profile_human_validations(reviewer_certainty, verdict);

CREATE INDEX idx_profile_human_validation_module_revision ON profile_human_validations(module_revision_ref, verdict);

CREATE INDEX idx_profile_human_validation_phase_revision ON profile_human_validations(phase_revision_ref, verdict);

CREATE INDEX idx_profile_human_validation_subject ON profile_human_validations(subject_key, verdict);

CREATE INDEX idx_runs_song ON benchmark_runs(song_id);

CREATE INDEX idx_scientific_artifacts_run
    ON scientific_artifacts(run_id,role);

CREATE INDEX idx_scientific_runs_song_item
    ON scientific_runs(song_id,item,id);

CREATE INDEX idx_songs_sha ON songs(audio_sha256);

CREATE TRIGGER trg_analysis_jobs_phase_lock_claim
BEFORE UPDATE OF status ON analysis_jobs
WHEN NEW.status='running' AND OLD.status='queued'
BEGIN
    SELECT CASE WHEN EXISTS(
        SELECT 1
        FROM analysis_resource_locks l
        WHERE l.song_id = NEW.song_id
          AND l.expires_at_epoch > CAST(strftime('%s','now') AS INTEGER)
          AND l.phase = CASE
              WHEN NEW.kind IN ('benchmark','chords','chords_scientific')
              THEN 'chords'
              ELSE NEW.kind
          END
    ) THEN RAISE(ABORT, 'analysis_phase_locked') END;
END;

CREATE TRIGGER trg_analysis_jobs_phase_lock_insert
BEFORE INSERT ON analysis_jobs
BEGIN
    SELECT CASE WHEN EXISTS(
        SELECT 1
        FROM analysis_resource_locks l
        WHERE l.song_id = NEW.song_id
          AND l.expires_at_epoch > CAST(strftime('%s','now') AS INTEGER)
          AND l.phase = CASE
              WHEN NEW.kind IN ('benchmark','chords','chords_scientific')
              THEN 'chords'
              ELSE NEW.kind
          END
    ) THEN RAISE(ABORT, 'analysis_phase_locked') END;
END;

CREATE TRIGGER trg_global_queue_admission_fence_delete
BEFORE DELETE ON analysis_jobs WHEN OLD.dispatch_owner='orchestrator'
BEGIN
    SELECT RAISE(ABORT,'global_queue_cancel_protocol_required');
END;

CREATE TRIGGER trg_global_queue_admission_fence_owner
BEFORE UPDATE OF dispatch_owner ON analysis_jobs
WHEN OLD.dispatch_owner='orchestrator' AND NEW.dispatch_owner <> 'orchestrator'
BEGIN
    SELECT RAISE(ABORT,'global_queue_owner_immutable');
END;

CREATE TRIGGER trg_global_queue_admission_fence_status
BEFORE UPDATE OF status ON analysis_jobs
WHEN OLD.dispatch_owner='orchestrator' AND NEW.status <> OLD.status
 AND NOT (
     (OLD.status='queued' AND NEW.status IN ('failed','cancelled')
       AND EXISTS (SELECT 1 FROM global_queue_terminal_receipts proof
         JOIN global_queue_admission_outbox o ON o.job_id=proof.job_id
         WHERE proof.job_id=OLD.id AND o.state='admitted'
           AND o.global_job_id=proof.global_job_id AND proof.terminal_state=NEW.status))
     OR (OLD.status IN ('failed','error','aborted','stalled') AND NEW.status='queued'
       AND EXISTS (SELECT 1 FROM ezs_retry_receipts rr
         JOIN global_queue_admission_outbox o ON o.job_id=rr.lab_job_id
         WHERE rr.lab_job_id=OLD.id AND rr.state='prepared'
           AND o.state='admitted' AND o.global_job_id=rr.global_job_id))
     OR (OLD.status='queued' AND NEW.status IN ('failed','error','aborted','stalled')
       AND EXISTS (SELECT 1 FROM ezs_retry_receipts rr
         JOIN global_queue_admission_outbox o ON o.job_id=rr.lab_job_id
         WHERE rr.lab_job_id=OLD.id AND rr.state='aborting'
           AND o.state='admitted' AND o.global_job_id=rr.global_job_id))
 )
BEGIN SELECT RAISE(ABORT,'global_queue_dispatch_fenced'); END;

CREATE TRIGGER trg_global_queue_admission_insert
AFTER INSERT ON analysis_jobs WHEN NEW.dispatch_owner='orchestrator'
BEGIN
    INSERT INTO global_queue_admission_outbox(job_id,state,updated_at)
    VALUES(NEW.id,'pending',NEW.created_at);
END;
