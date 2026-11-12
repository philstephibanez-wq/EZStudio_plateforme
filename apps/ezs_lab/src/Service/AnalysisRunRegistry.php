<?php

namespace EZSLab\Service;

use PDO;

class AnalysisRunRegistry
{
    public function __construct(private readonly Database $db) {}

    public function ensureSchema(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS scientific_runs (
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
CREATE INDEX IF NOT EXISTS idx_scientific_runs_song_item
    ON scientific_runs(song_id,item,id);

CREATE TABLE IF NOT EXISTS scientific_run_parents (
    child_run_id INTEGER NOT NULL,
    parent_run_id INTEGER NOT NULL,
    role TEXT NOT NULL,
    PRIMARY KEY(child_run_id,parent_run_id,role),
    FOREIGN KEY(child_run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE,
    FOREIGN KEY(parent_run_id) REFERENCES scientific_runs(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS scientific_artifacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL,
    artifact_id TEXT NOT NULL UNIQUE,
    role TEXT NOT NULL,
    label TEXT NOT NULL DEFAULT '',
    path TEXT NOT NULL,
    sha256 TEXT NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    FOREIGN KEY(run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_scientific_artifacts_run
    ON scientific_artifacts(run_id,role);

CREATE TABLE IF NOT EXISTS scientific_run_inputs (
    run_id INTEGER NOT NULL,
    artifact_id TEXT NOT NULL,
    input_role TEXT NOT NULL,
    selected INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY(run_id,artifact_id,input_role),
    FOREIGN KEY(run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE,
    FOREIGN KEY(artifact_id) REFERENCES scientific_artifacts(artifact_id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS scientific_run_aliases (
    run_id INTEGER NOT NULL,
    source_type TEXT NOT NULL,
    source_id TEXT NOT NULL,
    PRIMARY KEY(source_type,source_id),
    FOREIGN KEY(run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS scientific_run_labels (
    run_id INTEGER NOT NULL,
    label TEXT NOT NULL,
    value TEXT NOT NULL DEFAULT '',
    PRIMARY KEY(run_id,label),
    FOREIGN KEY(run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE
);
SQL;
        $this->db->pdo()->exec($sql);
    }

    public function createRun(
        int $songId,
        string $item,
        array $engine,
        array $config,
        array $environment = [],
    ): array {
        $this->ensureSchema();

        if (!in_array($item, ['profile','stems','chords','lyrics'], true)) {
            throw new \InvalidArgumentException('invalid_item');
        }

        $now = gmdate('c');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO scientific_runs(
                public_id,song_id,item,state,engine_name,engine_version,
                model_name,checkpoint,config_json,environment_json,created_at
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?)'
        );

        $placeholder = 'pending-'.bin2hex(random_bytes(8));
        $stmt->execute([
            $placeholder,
            $songId,
            $item,
            'created',
            (string)($engine['name'] ?? ''),
            (string)($engine['version'] ?? ''),
            (string)($engine['model'] ?? ''),
            (string)($engine['checkpoint'] ?? ''),
            json_encode($config, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($environment, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            $now,
        ]);

        $id = (int)$this->db->pdo()->lastInsertId();
        $publicId = sprintf('%s-%06d', $item, $id);

        $this->db->pdo()->prepare(
            'UPDATE scientific_runs SET public_id=? WHERE id=?'
        )->execute([$publicId, $id]);

        return $this->run($id);
    }

    public function addParent(int $childId, int $parentId, string $role): void
    {
        $this->ensureSchema();
        if ($childId === $parentId) {
            throw new \InvalidArgumentException('run_cannot_parent_itself');
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT OR IGNORE INTO scientific_run_parents(child_run_id,parent_run_id,role)
             VALUES(?,?,?)'
        );
        $stmt->execute([$childId, $parentId, $role]);
    }

    public function registerArtifact(
        int $runId,
        string $role,
        string $path,
        string $sha256,
        array $metadata = [],
        ?string $label = null,
    ): string {
        $this->ensureSchema();

        $artifactId = sprintf(
            'artifact-%06d-%s-%s',
            $runId,
            preg_replace('/[^a-z0-9_-]+/i', '-', $role),
            substr(strtolower($sha256), 0, 12)
        );

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO scientific_artifacts(
                run_id,artifact_id,role,label,path,sha256,metadata_json,created_at
             ) VALUES(?,?,?,?,?,?,?,?)
             ON CONFLICT(artifact_id) DO UPDATE SET
                label=excluded.label,
                path=excluded.path,
                sha256=excluded.sha256,
                metadata_json=excluded.metadata_json'
        );
        $stmt->execute([
            $runId,
            $artifactId,
            $role,
            $label ?? $role,
            $path,
            strtolower($sha256),
            json_encode($metadata, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            gmdate('c'),
        ]);

        return $artifactId;
    }

    public function selectInput(int $runId, string $artifactId, string $inputRole): void
    {
        $this->ensureSchema();

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO scientific_run_inputs(run_id,artifact_id,input_role,selected)
             VALUES(?,?,?,1)
             ON CONFLICT(run_id,artifact_id,input_role)
             DO UPDATE SET selected=1'
        );
        $stmt->execute([$runId, $artifactId, $inputRole]);
    }

    public function setAlias(int $runId, string $sourceType, string $sourceId): void
    {
        $this->ensureSchema();

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO scientific_run_aliases(run_id,source_type,source_id)
             VALUES(?,?,?)
             ON CONFLICT(source_type,source_id)
             DO UPDATE SET run_id=excluded.run_id'
        );
        $stmt->execute([$runId, $sourceType, $sourceId]);
    }

    public function setLabel(int $runId, string $label, string $value=''): void
    {
        $this->ensureSchema();

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO scientific_run_labels(run_id,label,value)
             VALUES(?,?,?)
             ON CONFLICT(run_id,label)
             DO UPDATE SET value=excluded.value'
        );
        $stmt->execute([$runId, $label, $value]);
    }

    public function run(int $id): ?array
    {
        $this->ensureSchema();

        $stmt = $this->db->pdo()->prepare(
            'SELECT sr.*,s.title,s.artist,s.audio_sha256
             FROM scientific_runs sr
             JOIN songs s ON s.id=sr.song_id
             WHERE sr.id=?'
        );
        $stmt->execute([$id]);
        $run = $stmt->fetch();
        if (!$run) {
            return null;
        }

        foreach (['config_json','environment_json','metrics_json','diagnostics_json','human_validation_json'] as $key) {
            $run[str_replace('_json','',$key)] = json_decode((string)$run[$key], true) ?: [];
        }

        $run['parents'] = $this->parents($id);
        $run['children'] = $this->children($id);
        $run['artifacts'] = $this->artifacts($id);
        $run['inputs'] = $this->inputs($id);
        $run['labels'] = $this->labels($id);

        return $run;
    }

    public function runsForSong(int $songId): array
    {
        $this->ensureSchema();

        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM scientific_runs
             WHERE song_id=?
             ORDER BY id DESC'
        );
        $stmt->execute([$songId]);
        return $stmt->fetchAll();
    }

    public function latestForSongItem(int $songId, string $item): ?array
    {
        $this->ensureSchema();

        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM scientific_runs
             WHERE song_id=? AND item=?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$songId,$item]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function lineage(int $runId): array
    {
        $this->ensureSchema();

        $seen = [];
        $nodes = [];
        $edges = [];

        $walk = function(int $id) use (&$walk,&$seen,&$nodes,&$edges): void {
            if (isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;

            $run = $this->run($id);
            if ($run === null) {
                return;
            }

            $nodes[] = [
                'id' => (int)$run['id'],
                'public_id' => (string)$run['public_id'],
                'item' => (string)$run['item'],
                'state' => (string)$run['state'],
                'engine_name' => (string)$run['engine_name'],
                'engine_version' => (string)$run['engine_version'],
                'model_name' => (string)$run['model_name'],
                'checkpoint' => (string)$run['checkpoint'],
                'config' => $run['config'],
                'labels' => $run['labels'],
            ];

            foreach ($run['parents'] as $parent) {
                $edges[] = [
                    'from' => (int)$parent['id'],
                    'to' => $id,
                    'role' => (string)$parent['role'],
                ];
                $walk((int)$parent['id']);
            }
        };

        $walk($runId);

        return [
            'root_run_id' => $runId,
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    public function compareAcrossSongs(string $item): array
    {
        $this->ensureSchema();

        $stmt = $this->db->pdo()->prepare(
            'SELECT
                sr.engine_name,
                sr.engine_version,
                sr.model_name,
                COUNT(DISTINCT s.audio_sha256) AS songs,
                COUNT(*) AS runs
             FROM scientific_runs sr
             JOIN songs s ON s.id=sr.song_id
             WHERE sr.item=?
             GROUP BY sr.engine_name,sr.engine_version,sr.model_name
             ORDER BY songs DESC,runs DESC'
        );
        $stmt->execute([$item]);
        return $stmt->fetchAll();
    }


    public function setState(
        int $runId,
        string $state,
        array $metrics = [],
        array $diagnostics = [],
        array $environment = [],
        ?string $finishedAt = null,
    ): void {
        $this->ensureSchema();

        $stmt = $this->db->pdo()->prepare(
            'UPDATE scientific_runs
             SET state=?,
                 metrics_json=?,
                 diagnostics_json=?,
                 environment_json=?,
                 finished_at=?
             WHERE id=?'
        );
        $stmt->execute([
            $state,
            json_encode($metrics, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($diagnostics, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            json_encode($environment, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            $finishedAt,
            $runId,
        ]);
    }

    public function findByAlias(string $sourceType, string $sourceId): ?array
    {
        $this->ensureSchema();

        $stmt = $this->db->pdo()->prepare(
            'SELECT sr.*
             FROM scientific_run_aliases a
             JOIN scientific_runs sr ON sr.id=a.run_id
             WHERE a.source_type=? AND a.source_id=?'
        );
        $stmt->execute([$sourceType, $sourceId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function artifactsForRun(int $runId): array
    {
        $this->ensureSchema();
        return $this->artifacts($runId);
    }

    public function artifact(string $artifactId): ?array
    {
        $this->ensureSchema();

        $stmt = $this->db->pdo()->prepare(
            'SELECT a.*,sr.song_id,sr.item,sr.public_id
             FROM scientific_artifacts a
             JOIN scientific_runs sr ON sr.id=a.run_id
             WHERE a.artifact_id=?'
        );
        $stmt->execute([$artifactId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function songIdByAudioHash(string $audioHash): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM songs WHERE audio_sha256=? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$audioHash]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int)$id;
    }

    public function runsForSongItem(int $songId, string $item): array
    {
        $this->ensureSchema();

        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM scientific_runs
             WHERE song_id=? AND item=?
             ORDER BY id DESC'
        );
        $stmt->execute([$songId,$item]);
        return $stmt->fetchAll();
    }

    private function parents(int $runId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.role,sr.*
             FROM scientific_run_parents p
             JOIN scientific_runs sr ON sr.id=p.parent_run_id
             WHERE p.child_run_id=?
             ORDER BY sr.id'
        );
        $stmt->execute([$runId]);
        return $stmt->fetchAll();
    }

    private function children(int $runId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.role,sr.*
             FROM scientific_run_parents p
             JOIN scientific_runs sr ON sr.id=p.child_run_id
             WHERE p.parent_run_id=?
             ORDER BY sr.id'
        );
        $stmt->execute([$runId]);
        return $stmt->fetchAll();
    }

    private function artifacts(int $runId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM scientific_artifacts
             WHERE run_id=?
             ORDER BY role,artifact_id'
        );
        $stmt->execute([$runId]);
        return $stmt->fetchAll();
    }

    private function inputs(int $runId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.input_role,a.*
             FROM scientific_run_inputs i
             JOIN scientific_artifacts a ON a.artifact_id=i.artifact_id
             WHERE i.run_id=? AND i.selected=1
             ORDER BY i.input_role,a.role'
        );
        $stmt->execute([$runId]);
        return $stmt->fetchAll();
    }

    private function labels(int $runId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT label,value FROM scientific_run_labels
             WHERE run_id=? ORDER BY label'
        );
        $stmt->execute([$runId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string)$row['label']] = (string)$row['value'];
        }
        return $out;
    }
}
