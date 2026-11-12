<?php
namespace EZSLab\Service;

class PresetRegistry
{
    public function __construct(private readonly Database $db) {}

    public function ensureSchema(): void
    {
        $this->db->pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS preset_module_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_key TEXT NOT NULL,
    revision_number INTEGER NOT NULL,
    revision_ref TEXT NOT NULL UNIQUE,
    fingerprint TEXT NOT NULL,
    composition_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(module_key, revision_number),
    UNIQUE(module_key, fingerprint)
);
CREATE INDEX IF NOT EXISTS idx_preset_module_key
ON preset_module_revisions(module_key, revision_number);

CREATE TABLE IF NOT EXISTS preset_phase_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    phase TEXT NOT NULL CHECK(phase IN ('profile','stems','chords','lyrics')),
    revision_number INTEGER NOT NULL,
    revision_ref TEXT NOT NULL UNIQUE,
    fingerprint TEXT NOT NULL,
    pipeline_revision TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'candidate'
        CHECK(status IN ('candidate','promoted','rejected','superseded')),
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    UNIQUE(phase, revision_number),
    UNIQUE(phase, fingerprint)
);
CREATE INDEX IF NOT EXISTS idx_preset_phase_revision
ON preset_phase_revisions(phase, revision_number);

CREATE TABLE IF NOT EXISTS preset_phase_revision_modules (
    phase_revision_id INTEGER NOT NULL,
    module_revision_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    PRIMARY KEY(phase_revision_id, position),
    UNIQUE(phase_revision_id, module_revision_id),
    FOREIGN KEY(phase_revision_id)
        REFERENCES preset_phase_revisions(id) ON DELETE CASCADE,
    FOREIGN KEY(module_revision_id)
        REFERENCES preset_module_revisions(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS preset_phase_revision_parents (
    child_phase_revision_id INTEGER NOT NULL,
    parent_phase_revision_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'evolution',
    PRIMARY KEY(child_phase_revision_id,parent_phase_revision_id,role),
    FOREIGN KEY(child_phase_revision_id)
        REFERENCES preset_phase_revisions(id) ON DELETE CASCADE,
    FOREIGN KEY(parent_phase_revision_id)
        REFERENCES preset_phase_revisions(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS scientific_run_preset (
    run_id INTEGER PRIMARY KEY,
    phase_revision_id INTEGER NOT NULL,
    captured_at TEXT NOT NULL,
    FOREIGN KEY(run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE,
    FOREIGN KEY(phase_revision_id)
        REFERENCES preset_phase_revisions(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS preset_phase_baselines (
    phase TEXT PRIMARY KEY
        CHECK(phase IN ('profile','stems','chords','lyrics')),
    phase_revision_id INTEGER NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(phase_revision_id)
        REFERENCES preset_phase_revisions(id) ON DELETE RESTRICT
);
SQL);
    }

    public function captureRunPreset(
        int $runId,
        string $phase,
        array $manifest,
    ): array {
        $this->ensureSchema();

        if (!in_array($phase, ['profile','stems','chords','lyrics'], true)) {
            throw new \InvalidArgumentException('preset_invalid_phase');
        }

        $run = $this->scientificRun($runId);
        if ($run === null) {
            throw new \RuntimeException('preset_scientific_run_missing');
        }
        if ((string)$run['item'] !== $phase) {
            throw new \RuntimeException('preset_run_phase_mismatch');
        }

        $modules = $manifest['modules'] ?? null;
        if (!is_array($modules) || $modules === []) {
            throw new \RuntimeException('preset_manifest_modules_missing');
        }

        $pipelineRevision = trim((string)($manifest['pipeline_revision'] ?? ''));
        $pdo = $this->db->pdo();
        $pdo->exec('BEGIN IMMEDIATE');

        try {
            $existingLink = $pdo->prepare(
                'SELECT phase_revision_id FROM scientific_run_preset WHERE run_id=?'
            );
            $existingLink->execute([$runId]);
            $linked = $existingLink->fetchColumn();

            $moduleRows = [];
            $position = 0;
            foreach ($modules as $module) {
                if (!is_array($module)) {
                    throw new \RuntimeException('preset_module_manifest_invalid');
                }
                $moduleKey = trim((string)($module['id'] ?? ''));
                if ($moduleKey === '') {
                    throw new \RuntimeException('preset_module_id_missing');
                }

                $composition = $this->canonicalize($module);
                $fingerprint = hash('sha256', $this->canonicalJson($composition));

                $stmt = $pdo->prepare(
                    'SELECT * FROM preset_module_revisions
                     WHERE module_key=? AND fingerprint=?'
                );
                $stmt->execute([$moduleKey, $fingerprint]);
                $moduleRow = $stmt->fetch();

                if (!$moduleRow) {
                    $next = $pdo->prepare(
                        'SELECT COALESCE(MAX(revision_number),0)+1
                         FROM preset_module_revisions WHERE module_key=?'
                    );
                    $next->execute([$moduleKey]);
                    $revisionNumber = (int)$next->fetchColumn();
                    $revisionRef = $moduleKey.'.r'.$revisionNumber;

                    $pdo->prepare(
                        'INSERT INTO preset_module_revisions(
                            module_key,revision_number,revision_ref,
                            fingerprint,composition_json,created_at
                         ) VALUES(?,?,?,?,?,?)'
                    )->execute([
                        $moduleKey,
                        $revisionNumber,
                        $revisionRef,
                        $fingerprint,
                        $this->canonicalJson($composition),
                        gmdate('c'),
                    ]);

                    $stmt = $pdo->prepare(
                        'SELECT * FROM preset_module_revisions WHERE id=?'
                    );
                    $stmt->execute([(int)$pdo->lastInsertId()]);
                    $moduleRow = $stmt->fetch();
                }

                $moduleRows[] = ['position' => $position++, 'row' => $moduleRow];
            }

            $phaseIdentity = [
                'schema' => 'ezstudio.preset.phase.v1',
                'phase' => $phase,
                'pipeline_revision' => $pipelineRevision,
                'modules' => array_map(
                    static fn(array $entry): array => [
                        'position' => (int)$entry['position'],
                        'module_key' => (string)$entry['row']['module_key'],
                        'module_revision' => (string)$entry['row']['revision_ref'],
                        'fingerprint' => (string)$entry['row']['fingerprint'],
                    ],
                    $moduleRows
                ),
            ];
            $phaseFingerprint = hash(
                'sha256',
                $this->canonicalJson($phaseIdentity)
            );

            $stmt = $pdo->prepare(
                'SELECT * FROM preset_phase_revisions
                 WHERE phase=? AND fingerprint=?'
            );
            $stmt->execute([$phase, $phaseFingerprint]);
            $phaseRow = $stmt->fetch();

            if (!$phaseRow) {
                $next = $pdo->prepare(
                    'SELECT COALESCE(MAX(revision_number),0)+1
                     FROM preset_phase_revisions WHERE phase=?'
                );
                $next->execute([$phase]);
                $revisionNumber = (int)$next->fetchColumn();
                $revisionRef = strtoupper($phase).'.r'.$revisionNumber;

                $baselineStmt = $pdo->prepare(
                    'SELECT phase_revision_id
                     FROM preset_phase_baselines WHERE phase=?'
                );
                $baselineStmt->execute([$phase]);
                $parentId = $baselineStmt->fetchColumn();

                if ($parentId === false) {
                    $latest = $pdo->prepare(
                        'SELECT id FROM preset_phase_revisions
                         WHERE phase=? ORDER BY revision_number DESC LIMIT 1'
                    );
                    $latest->execute([$phase]);
                    $parentId = $latest->fetchColumn();
                }

                $isFirst = $parentId === false;
                $status = $isFirst ? 'promoted' : 'candidate';

                $pdo->prepare(
                    'INSERT INTO preset_phase_revisions(
                        phase,revision_number,revision_ref,fingerprint,
                        pipeline_revision,status,metadata_json,created_at
                     ) VALUES(?,?,?,?,?,?,?,?)'
                )->execute([
                    $phase,
                    $revisionNumber,
                    $revisionRef,
                    $phaseFingerprint,
                    $pipelineRevision,
                    $status,
                    $this->canonicalJson([
                        'manifest_schema' => $manifest['schema']
                            ?? 'ezstudio.preset.phase.v1',
                    ]),
                    gmdate('c'),
                ]);
                $phaseId = (int)$pdo->lastInsertId();

                $linkModule = $pdo->prepare(
                    'INSERT INTO preset_phase_revision_modules(
                        phase_revision_id,module_revision_id,position
                     ) VALUES(?,?,?)'
                );
                foreach ($moduleRows as $entry) {
                    $linkModule->execute([
                        $phaseId,
                        (int)$entry['row']['id'],
                        (int)$entry['position'],
                    ]);
                }

                if ($parentId !== false) {
                    $pdo->prepare(
                        'INSERT OR IGNORE INTO preset_phase_revision_parents(
                            child_phase_revision_id,parent_phase_revision_id,role
                         ) VALUES(?,?,?)'
                    )->execute([$phaseId, (int)$parentId, 'evolution']);
                }

                if ($isFirst) {
                    $pdo->prepare(
                        'INSERT INTO preset_phase_baselines(
                            phase,phase_revision_id,updated_at
                         ) VALUES(?,?,?)'
                    )->execute([$phase, $phaseId, gmdate('c')]);
                }

                $stmt = $pdo->prepare(
                    'SELECT * FROM preset_phase_revisions WHERE id=?'
                );
                $stmt->execute([$phaseId]);
                $phaseRow = $stmt->fetch();
            }

            if ($linked !== false && (int)$linked !== (int)$phaseRow['id']) {
                throw new \RuntimeException('preset_run_link_is_immutable');
            }

            if ($linked === false) {
                $pdo->prepare(
                    'INSERT INTO scientific_run_preset(
                        run_id,phase_revision_id,captured_at
                     ) VALUES(?,?,?)'
                )->execute([$runId, (int)$phaseRow['id'], gmdate('c')]);
            }

            $pdo->commit();
            return $this->phaseRevision((int)$phaseRow['id']);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function phaseRevision(int $id): array
    {
        $this->ensureSchema();

        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM preset_phase_revisions WHERE id=?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('preset_phase_revision_missing');
        }

        $modules = $this->db->pdo()->prepare(
            'SELECT rg.position,g.*
             FROM preset_phase_revision_modules rg
             JOIN preset_module_revisions g ON g.id=rg.module_revision_id
             WHERE rg.phase_revision_id=?
             ORDER BY rg.position'
        );
        $modules->execute([$id]);

        $parents = $this->db->pdo()->prepare(
            'SELECT p.role,r.*
             FROM preset_phase_revision_parents p
             JOIN preset_phase_revisions r
               ON r.id=p.parent_phase_revision_id
             WHERE p.child_phase_revision_id=?
             ORDER BY r.revision_number'
        );
        $parents->execute([$id]);

        $row['metadata'] = json_decode((string)$row['metadata_json'], true) ?: [];
        $row['modules'] = array_map(
            function(array $module): array {
                $module['composition'] = json_decode(
                    (string)$module['composition_json'],
                    true
                ) ?: [];
                return $module;
            },
            $modules->fetchAll()
        );
        $row['parents'] = $parents->fetchAll();
        return $row;
    }

    public function presetForRun(int $runId): ?array
    {
        $this->ensureSchema();
        $stmt = $this->db->pdo()->prepare(
            'SELECT phase_revision_id FROM scientific_run_preset WHERE run_id=?'
        );
        $stmt->execute([$runId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : $this->phaseRevision((int)$id);
    }

    public function promotePhaseRevision(int $id): array
    {
        $this->ensureSchema();
        $revision = $this->phaseRevision($id);
        $phase = (string)$revision['phase'];

        $pdo = $this->db->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->prepare(
                "UPDATE preset_phase_revisions
                 SET status='superseded'
                 WHERE phase=? AND status='promoted' AND id<>?"
            )->execute([$phase, $id]);
            $pdo->prepare(
                "UPDATE preset_phase_revisions SET status='promoted' WHERE id=?"
            )->execute([$id]);
            $pdo->prepare(
                'INSERT INTO preset_phase_baselines(
                    phase,phase_revision_id,updated_at
                 ) VALUES(?,?,?)
                 ON CONFLICT(phase) DO UPDATE SET
                    phase_revision_id=excluded.phase_revision_id,
                    updated_at=excluded.updated_at'
            )->execute([$phase, $id, gmdate('c')]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->phaseRevision($id);
    }

    public function rejectPhaseRevision(int $id): void
    {
        $this->ensureSchema();
        $this->db->pdo()->prepare(
            "UPDATE preset_phase_revisions
             SET status='rejected'
             WHERE id=? AND status!='promoted'"
        )->execute([$id]);
    }

    public function diffPhaseRevisions(int $fromId, int $toId): array
    {
        $from = $this->phaseRevision($fromId);
        $to = $this->phaseRevision($toId);

        if ((string)$from['phase'] !== (string)$to['phase']) {
            throw new \InvalidArgumentException('preset_diff_phase_mismatch');
        }

        $index = static function(array $revision): array {
            $out = [];
            foreach ($revision['modules'] as $module) {
                $out[(string)$module['module_key']] = $module;
            }
            return $out;
        };

        $a = $index($from);
        $b = $index($to);
        $added = [];
        $removed = [];
        $changed = [];
        $unchanged = [];

        foreach ($b as $key => $module) {
            if (!isset($a[$key])) {
                $added[] = $module['revision_ref'];
            } elseif (
                (string)$a[$key]['fingerprint']
                !== (string)$module['fingerprint']
            ) {
                $changed[] = [
                    'module' => $key,
                    'from' => $a[$key]['revision_ref'],
                    'to' => $module['revision_ref'],
                ];
            } else {
                $unchanged[] = $module['revision_ref'];
            }
        }

        foreach ($a as $key => $module) {
            if (!isset($b[$key])) {
                $removed[] = $module['revision_ref'];
            }
        }

        return [
            'phase' => (string)$from['phase'],
            'from' => (string)$from['revision_ref'],
            'to' => (string)$to['revision_ref'],
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'unchanged' => $unchanged,
        ];
    }

    private function scientificRun(int $runId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM scientific_runs WHERE id=?'
        );
        $stmt->execute([$runId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function canonicalJson(array $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(
                fn(mixed $item): mixed => $this->canonicalize($item),
                $value
            );
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
