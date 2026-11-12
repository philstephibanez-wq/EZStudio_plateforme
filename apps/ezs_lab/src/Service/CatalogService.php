<?php

namespace EZSLab\Service;

use PDO;

final class CatalogService
{
    private const ITEMS = ['profile', 'stems', 'chords', 'lyrics'];

    public function __construct(private readonly Database $db) {}

    public function songs(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT id,title,artist,source_filename,audio_sha256,source_path,created_at
             FROM songs
             ORDER BY id DESC'
        )->fetchAll();

        $groups = [];
        foreach ($rows as $song) {
            $hash = trim((string)$song['audio_sha256']);
            $key = $hash !== '' ? $hash : 'song:'.(int)$song['id'];

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'id' => (int)$song['id'],
                    'title' => (string)$song['title'],
                    'artist' => (string)$song['artist'],
                    'source_filename' => (string)$song['source_filename'],
                    'audio_sha256' => $hash,
                    'created_at' => (string)$song['created_at'],
                    'song_ids' => [],
                ];
                foreach (self::ITEMS as $item) {
                    $groups[$key][$item] = $this->emptyState();
                }
            }

            $groups[$key]['song_ids'][] = (int)$song['id'];
        }

        foreach ($groups as &$group) {
            $this->db->reconcileStaleCancellingAnalysisJobsForSongs(
                $group['song_ids'],
                60
            );

            foreach (self::ITEMS as $item) {
                $lastAttempt = $this->latestScientificState(
                    $group['song_ids'],
                    $item
                );
                $group[$item] = $lastAttempt;
                // Availability and latest attempt are independent dimensions.
                // Never hide a completed PROFILE because a newer try failed.
                if ($item === 'profile') {
                    $available = $this->latestScientificState(
                        $group['song_ids'], $item, true
                    );
                    if ($available['state'] === 'done') {
                        $group[$item] = $available;
                        $group[$item]['latest_attempt'] =
                            $lastAttempt['run_id'] !== $available['run_id']
                            ? $lastAttempt : null;
                    }
                }
            }

            // PROFILE R3E: computed from the latest active PROFILE run only.
            // A previous run's review must never mark a newer run as reviewed.
            $group['profile_ai_reviewed'] = false;
            $group['profile_ai_revision_id'] = null;
            $group['profile_ai_status'] = 'none';
            $profileRunId = (int)($group['profile']['run_id'] ?? 0);
            if ($profileRunId > 0 && $group['profile']['state'] === 'done'
                && $this->tableExists('profile_external_revisions')) {
                $reviewStmt = $this->db->pdo()->prepare(
                    'SELECT id, status, consensus_json, comparison_json
                     FROM profile_external_revisions
                     WHERE run_id=? ORDER BY id DESC'
                );
                $reviewStmt->execute([$profileRunId]);
                foreach ($reviewStmt->fetchAll() as $revision) {
                    $consensus = json_decode((string)$revision['consensus_json'], true);
                    $comparison = json_decode((string)$revision['comparison_json'], true);
                    $ai = is_array($consensus) ? ($consensus['assistant_ai'] ?? []) : [];
                    $detail = is_array($comparison) ? ($comparison['assistant_ai'] ?? []) : [];
                    if (($ai['status'] ?? '') !== 'completed') {
                        continue;
                    }
                    // A completed consultative AI review counts as reviewed,
                    // even when no independent documentary dimension is scored.
                    // Keep the latest scientific run restriction above.
                    $group['profile_ai_reviewed'] = true;
                    $group['profile_ai_revision_id'] = (int)$revision['id'];
                    $group['profile_ai_status'] = (string)$revision['status'];
                    break;
                }
            }

            // Compatibility with historical benchmark CHORDS.
            if ($group['chords']['state'] === 'none') {
                $legacy = $this->latestBenchmarkState($group['song_ids']);
                if ($legacy !== null) {
                    $group['chords'] = $legacy;
                }
            }
        }
        unset($group);

        $out = array_values($groups);
        usort(
            $out,
            static fn(array $a, array $b): int =>
                strcasecmp($a['title'].' '.$a['artist'], $b['title'].' '.$b['artist'])
        );

        return $out;
    }

    public function abandonPhase(
        int $songId,
        string $phase
    ): array {
        $order = ['profile', 'stems', 'chords', 'lyrics'];
        $offset = array_search($phase, $order, true);
        if ($offset === false) {
            throw new \InvalidArgumentException('catalog_invalid_phase');
        }
        $targets = array_slice($order, (int)$offset);

        $stmt = $this->db->pdo()->prepare(
            'SELECT id,audio_sha256 FROM songs WHERE id=?'
        );
        $stmt->execute([$songId]);
        $seed = $stmt->fetch();
        if (!$seed) {
            throw new \RuntimeException('catalog_song_not_found');
        }

        $hash = trim((string)$seed['audio_sha256']);
        if ($hash !== '') {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM songs WHERE audio_sha256=? ORDER BY id'
            );
            $stmt->execute([$hash]);
            $songIds = array_map(
                static fn(array $row): int => (int)$row['id'],
                $stmt->fetchAll()
            );
        } else {
            $songIds = [$songId];
        }

        $owner = 'catalog-abandon-phase-'
            .$songId.'-'.$phase.'-'.bin2hex(random_bytes(8));

        $this->db->acquireAnalysisPhaseLocks(
            $songIds,
            $targets,
            $owner,
            300
        );

        try {
            $active = $this->db->requestPhaseCancellation(
                $songIds,
                $targets,
                60
            );
            if ($active !== []) {
                $ids = implode(
                    ',',
                    array_map(
                        static fn(array $row): string =>
                            (string)$row['id'],
                        $active
                    )
                );
                throw new \RuntimeException(
                    'catalog_phase_cancel_pending:jobs='.$ids
                );
            }

            if (!$this->tableExists('scientific_runs')) {
                return [
                    'song_ids' => $songIds,
                    'audio_sha256' => $hash,
                    'abandoned_phases' => $targets,
                    'abandoned_run_ids' => [],
                ];
            }

            $marks = implode(',', array_fill(0, count($songIds), '?'));
            $targetMarks = implode(',', array_fill(0, count($targets), '?'));

            $stmt = $this->db->pdo()->prepare(
                "SELECT id FROM scientific_runs
                 WHERE song_id IN ($marks)
                   AND item IN ($targetMarks)
                   AND state!='abandoned'
                 ORDER BY id"
            );
            $i = 1;
            foreach ($songIds as $id) {
                $stmt->bindValue($i++, $id, PDO::PARAM_INT);
            }
            foreach ($targets as $item) {
                $stmt->bindValue($i++, $item, PDO::PARAM_STR);
            }
            $stmt->execute();
            $runIds = array_map(
                static fn(array $row): int => (int)$row['id'],
                $stmt->fetchAll()
            );

            if ($runIds !== []) {
                $runMarks = implode(',', array_fill(0, count($runIds), '?'));
                $pdo = $this->db->pdo();
                $pdo->beginTransaction();
                try {
                    $update = $pdo->prepare(
                        "UPDATE scientific_runs
                         SET state='abandoned'
                         WHERE id IN ($runMarks)"
                    );
                    $this->bindIds($update, $runIds);
                    $update->execute();

                    if ($this->tableExists('scientific_run_labels')) {
                        $label = $pdo->prepare(
                            'INSERT INTO scientific_run_labels(
                                run_id,label,value
                             ) VALUES(?,?,?)
                             ON CONFLICT(run_id,label)
                             DO UPDATE SET value=excluded.value'
                        );
                        $now = gmdate('c');
                        foreach ($runIds as $runId) {
                            $label->execute([$runId, 'lifecycle', 'abandoned']);
                            $label->execute([$runId, 'abandoned_at', $now]);
                            $label->execute([$runId, 'abandoned_from_phase', $phase]);
                        }
                    }

                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }

            return [
                'song_ids' => $songIds,
                'audio_sha256' => $hash,
                'abandoned_phases' => $targets,
                'abandoned_run_ids' => $runIds,
            ];
        } finally {
            $this->db->releaseAnalysisPhaseLocks($owner);
        }
    }

    public function deletePhase(int $songId, string $phase): array
    {
        $order = ['profile', 'stems', 'chords', 'lyrics'];
        $offset = array_search($phase, $order, true);
        if ($offset === false) {
            throw new \InvalidArgumentException('catalog_invalid_phase');
        }

        $targets = array_slice($order, (int)$offset);

        $stmt = $this->db->pdo()->prepare(
            'SELECT id,title,artist,audio_sha256 FROM songs WHERE id=?'
        );
        $stmt->execute([$songId]);
        $seed = $stmt->fetch();
        if (!$seed) {
            throw new \RuntimeException('catalog_song_not_found');
        }

        $hash = trim((string)$seed['audio_sha256']);

        if ($hash !== '') {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM songs WHERE audio_sha256=? ORDER BY id'
            );
            $stmt->execute([$hash]);
            $songIds = array_map(
                static fn(array $row): int => (int)$row['id'],
                $stmt->fetchAll()
            );
        } else {
            $songIds = [$songId];
        }

        $owner = 'catalog-delete-phase-'
            .$songId.'-'.$phase.'-'.bin2hex(random_bytes(8));

        $this->db->acquireAnalysisPhaseLocks(
            $songIds,
            $targets,
            $owner,
            300
        );

        try {
            $active = $this->db->requestPhaseCancellation(
                $songIds,
                $targets,
                60
            );

            if ($active !== []) {
                $ids = implode(',', array_map(
                    static fn(array $row): string => (string)$row['id'],
                    $active
                ));
                throw new \RuntimeException(
                    'catalog_phase_cancel_pending:jobs='.$ids
                );
            }

            $marks = implode(',', array_fill(0, count($songIds), '?'));
            $targetMarks = implode(',', array_fill(0, count($targets), '?'));

            $scientificRunIds = [];
            if ($this->tableExists('scientific_runs')) {
                $stmt = $this->db->pdo()->prepare(
                    "SELECT id
                     FROM scientific_runs
                     WHERE song_id IN ($marks)
                       AND item IN ($targetMarks)"
                );

                $i = 1;
                foreach ($songIds as $id) {
                    $stmt->bindValue($i++, $id, PDO::PARAM_INT);
                }
                foreach ($targets as $item) {
                    $stmt->bindValue($i++, $item, PDO::PARAM_STR);
                }
                $stmt->execute();

                $scientificRunIds = array_map(
                    static fn(array $row): int => (int)$row['id'],
                    $stmt->fetchAll()
                );
            }

            $files = [];

            if ($scientificRunIds !== []) {
                $runMarks = implode(
                    ',',
                    array_fill(0, count($scientificRunIds), '?')
                );

                $stmt = $this->db->pdo()->prepare(
                    "SELECT COUNT(*)
                     FROM scientific_run_parents
                     WHERE parent_run_id IN ($runMarks)
                       AND child_run_id NOT IN ($runMarks)"
                );
                $i = 1;
                foreach ($scientificRunIds as $id) {
                    $stmt->bindValue($i++, $id, PDO::PARAM_INT);
                }
                foreach ($scientificRunIds as $id) {
                    $stmt->bindValue($i++, $id, PDO::PARAM_INT);
                }
                $stmt->execute();

                if ((int)$stmt->fetchColumn() > 0) {
                    throw new \RuntimeException(
                        'catalog_phase_has_external_child_runs'
                    );
                }

                $stmt = $this->db->pdo()->prepare(
                    "SELECT COUNT(*)
                     FROM scientific_run_inputs i
                     JOIN scientific_artifacts a
                       ON a.artifact_id=i.artifact_id
                     WHERE a.run_id IN ($runMarks)
                       AND i.run_id NOT IN ($runMarks)"
                );
                $i = 1;
                foreach ($scientificRunIds as $id) {
                    $stmt->bindValue($i++, $id, PDO::PARAM_INT);
                }
                foreach ($scientificRunIds as $id) {
                    $stmt->bindValue($i++, $id, PDO::PARAM_INT);
                }
                $stmt->execute();

                if ((int)$stmt->fetchColumn() > 0) {
                    throw new \RuntimeException(
                        'catalog_phase_artifacts_still_used'
                    );
                }

                $stmt = $this->db->pdo()->prepare(
                    "SELECT path
                     FROM scientific_artifacts
                     WHERE run_id IN ($runMarks)"
                );
                $this->bindIds($stmt, $scientificRunIds);
                $stmt->execute();

                foreach ($stmt->fetchAll() as $row) {
                    $path = trim((string)($row['path'] ?? ''));
                    if ($path !== '') {
                        $files[$path] = true;
                    }
                }
            }

            $jobKinds = [];
            foreach ($targets as $target) {
                if ($target === 'chords') {
                    foreach (['benchmark', 'chords', 'chords_scientific'] as $kind) {
                        $jobKinds[$kind] = true;
                    }
                } else {
                    $jobKinds[$target] = true;
                }
            }
            $jobKinds = array_keys($jobKinds);

            $jobIds = [];
            if ($jobKinds !== []) {
                $jobKindMarks = implode(
                    ',',
                    array_fill(0, count($jobKinds), '?')
                );

                $stmt = $this->db->pdo()->prepare(
                    "SELECT id
                     FROM analysis_jobs
                     WHERE song_id IN ($marks)
                       AND kind IN ($jobKindMarks)"
                );

                $i = 1;
                foreach ($songIds as $id) {
                    $stmt->bindValue($i++, $id, PDO::PARAM_INT);
                }
                foreach ($jobKinds as $kind) {
                    $stmt->bindValue($i++, $kind, PDO::PARAM_STR);
                }
                $stmt->execute();

                $jobIds = array_map(
                    static fn(array $row): int => (int)$row['id'],
                    $stmt->fetchAll()
                );
            }

            $benchmarkRunIds = [];
            if (in_array('chords', $targets, true)) {
                $stmt = $this->db->pdo()->prepare(
                    "SELECT id
                     FROM benchmark_runs
                     WHERE song_id IN ($marks)"
                );
                $this->bindIds($stmt, $songIds);
                $stmt->execute();

                $benchmarkRunIds = array_map(
                    static fn(array $row): int => (int)$row['id'],
                    $stmt->fetchAll()
                );
            }

            $pdo = $this->db->pdo();
            $pdo->beginTransaction();

            try {
                if ($scientificRunIds !== []) {
                    $runMarks = implode(
                        ',',
                        array_fill(0, count($scientificRunIds), '?')
                    );

                    $stmt = $pdo->prepare(
                        "DELETE FROM scientific_run_inputs
                         WHERE run_id IN ($runMarks)
                            OR artifact_id IN (
                                SELECT artifact_id
                                FROM scientific_artifacts
                                WHERE run_id IN ($runMarks)
                            )"
                    );
                    $i = 1;
                    foreach ($scientificRunIds as $id) {
                        $stmt->bindValue($i++, $id, PDO::PARAM_INT);
                    }
                    foreach ($scientificRunIds as $id) {
                        $stmt->bindValue($i++, $id, PDO::PARAM_INT);
                    }
                    $stmt->execute();

                    $stmt = $pdo->prepare(
                        "DELETE FROM scientific_run_parents
                         WHERE child_run_id IN ($runMarks)
                            OR parent_run_id IN ($runMarks)"
                    );
                    $i = 1;
                    foreach ($scientificRunIds as $id) {
                        $stmt->bindValue($i++, $id, PDO::PARAM_INT);
                    }
                    foreach ($scientificRunIds as $id) {
                        $stmt->bindValue($i++, $id, PDO::PARAM_INT);
                    }
                    $stmt->execute();
                }

                if ($jobIds !== []) {
                    $jobIdMarks = implode(
                        ',',
                        array_fill(0, count($jobIds), '?')
                    );
                    $stmt = $pdo->prepare(
                        "DELETE FROM analysis_jobs
                         WHERE id IN ($jobIdMarks)"
                    );
                    $this->bindIds($stmt, $jobIds);
                    $stmt->execute();
                }

                if ($scientificRunIds !== []) {
                    $runMarks = implode(
                        ',',
                        array_fill(0, count($scientificRunIds), '?')
                    );
                    $stmt = $pdo->prepare(
                        "DELETE FROM scientific_runs
                         WHERE id IN ($runMarks)"
                    );
                    $this->bindIds($stmt, $scientificRunIds);
                    $stmt->execute();
                }

                if ($benchmarkRunIds !== []) {
                    $benchMarks = implode(
                        ',',
                        array_fill(0, count($benchmarkRunIds), '?')
                    );
                    $stmt = $pdo->prepare(
                        "DELETE FROM benchmark_runs
                         WHERE id IN ($benchMarks)"
                    );
                    $this->bindIds($stmt, $benchmarkRunIds);
                    $stmt->execute();
                }

                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            foreach (array_keys($files) as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            $projectRoot = dirname(__DIR__, 2);
            $storageRoot = rtrim(
                (string)(
                    getenv('EZSTUDIO_STORAGE_ROOT')
                    ?: $projectRoot
                        .DIRECTORY_SEPARATOR.'var'
                        .DIRECTORY_SEPARATOR.'storage'
                ),
                '\\/'
            );
            $tmpRoot = rtrim(
                (string)(
                    getenv('EZSTUDIO_TMP_ROOT')
                    ?: $projectRoot
                        .DIRECTORY_SEPARATOR.'var'
                        .DIRECTORY_SEPARATOR.'tmp'
                ),
                '\\/'
            );

            if ($hash !== '') {
                foreach ($targets as $item) {
                    $this->removeTree(
                        $storageRoot
                        .DIRECTORY_SEPARATOR.$item
                        .DIRECTORY_SEPARATOR.$hash
                    );
                }
            }

            foreach ($jobIds as $jobId) {
                $this->removeTree(
                    $tmpRoot
                    .DIRECTORY_SEPARATOR.'jobs'
                    .DIRECTORY_SEPARATOR.(string)$jobId
                );
            }

            foreach ($benchmarkRunIds as $runId) {
                foreach (
                    glob(
                        $projectRoot
                        .DIRECTORY_SEPARATOR.'results'
                        .DIRECTORY_SEPARATOR.sprintf('run-%06d-*', $runId)
                    ) ?: []
                    as $path
                ) {
                    $this->removeTree($path);
                }
            }

            return [
                'song_ids' => $songIds,
                'audio_sha256' => $hash,
                'deleted_phases' => $targets,
            ];
        } finally {
            $this->db->releaseAnalysisPhaseLocks($owner);
        }
    }

    public function deleteGroup(int $songId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,title,artist,audio_sha256,source_path
             FROM songs WHERE id=?'
        );
        $stmt->execute([$songId]);
        $seed = $stmt->fetch();
        if (!$seed) {
            throw new \RuntimeException('catalog_song_not_found');
        }

        $hash = (string)$seed['audio_sha256'];
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,title,artist,audio_sha256,source_path
             FROM songs WHERE audio_sha256=? ORDER BY id'
        );
        $stmt->execute([$hash]);
        $songs = $stmt->fetchAll();
        if ($songs === []) {
            throw new \RuntimeException('catalog_song_group_empty');
        }

        $songIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            $songs
        );
        $marks = implode(',', array_fill(0, count($songIds), '?'));

        $this->db->cancelStaleAnalysisJobsForSongs($songIds, 60);
        $this->db->requestAnalysisCancellationForSongs($songIds);

        $deadline = microtime(true) + 30.0;
        do {
            $activeJobs = $this->db->activeOrCancellingAnalysisJobsForSongs($songIds);
            if ($activeJobs === []) {
                break;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);

        $activeJobs = $this->db->activeOrCancellingAnalysisJobsForSongs($songIds);
        if ($activeJobs !== []) {
            $ids = implode(',', array_map(
                static fn(array $row): string => (string)$row['id'],
                $activeJobs
            ));
            throw new \RuntimeException(
                'catalog_song_cancel_timeout:jobs='.$ids
            );
        }

        $files = [];
        foreach ($songs as $song) {
            $path = trim((string)($song['source_path'] ?? ''));
            if ($path !== '') {
                $files[$path] = true;
            }
        }

        $benchmarkRunIds = [];
        $stmt = $this->db->pdo()->prepare(
            "SELECT id,input_path FROM benchmark_runs
             WHERE song_id IN ($marks)"
        );
        $this->bindIds($stmt, $songIds);
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $benchmarkRunIds[] = (int)$row['id'];
            $path = trim((string)($row['input_path'] ?? ''));
            if ($path !== '') {
                $files[$path] = true;
            }
        }

        $scientificRunIds = [];
        if ($this->tableExists('scientific_runs')) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id FROM scientific_runs WHERE song_id IN ($marks)"
            );
            $this->bindIds($stmt, $songIds);
            $stmt->execute();
            $scientificRunIds = array_map(
                static fn(array $row): int => (int)$row['id'],
                $stmt->fetchAll()
            );
        }

        if ($scientificRunIds !== []) {
            $runMarks = implode(',', array_fill(0, count($scientificRunIds), '?'));

            // Do not destroy a run that is still referenced by another song.
            $stmt = $this->db->pdo()->prepare(
                "SELECT COUNT(*)
                 FROM scientific_run_parents p
                 JOIN scientific_runs child ON child.id=p.child_run_id
                 WHERE p.parent_run_id IN ($runMarks)
                   AND child.song_id NOT IN ($marks)"
            );
            $i = 1;
            foreach ($scientificRunIds as $id) {
                $stmt->bindValue($i++, $id, PDO::PARAM_INT);
            }
            foreach ($songIds as $id) {
                $stmt->bindValue($i++, $id, PDO::PARAM_INT);
            }
            $stmt->execute();
            if ((int)$stmt->fetchColumn() > 0) {
                throw new \RuntimeException('catalog_song_has_external_child_runs');
            }

            $stmt = $this->db->pdo()->prepare(
                "SELECT COUNT(*)
                 FROM scientific_run_inputs i
                 JOIN scientific_runs consumer ON consumer.id=i.run_id
                 JOIN scientific_artifacts a ON a.artifact_id=i.artifact_id
                 WHERE a.run_id IN ($runMarks)
                   AND consumer.song_id NOT IN ($marks)"
            );
            $i = 1;
            foreach ($scientificRunIds as $id) {
                $stmt->bindValue($i++, $id, PDO::PARAM_INT);
            }
            foreach ($songIds as $id) {
                $stmt->bindValue($i++, $id, PDO::PARAM_INT);
            }
            $stmt->execute();
            if ((int)$stmt->fetchColumn() > 0) {
                throw new \RuntimeException('catalog_song_artifacts_still_used');
            }

            $stmt = $this->db->pdo()->prepare(
                "SELECT path FROM scientific_artifacts
                 WHERE run_id IN ($runMarks)"
            );
            $this->bindIds($stmt, $scientificRunIds);
            $stmt->execute();
            foreach ($stmt->fetchAll() as $row) {
                $path = trim((string)($row['path'] ?? ''));
                if ($path !== '') {
                    $files[$path] = true;
                }
            }
        }

        $jobIds = [];
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM analysis_jobs WHERE song_id IN ($marks)"
        );
        $this->bindIds($stmt, $songIds);
        $stmt->execute();
        $jobIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            $stmt->fetchAll()
        );

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            if ($scientificRunIds !== []) {
                $runMarks = implode(',', array_fill(0, count($scientificRunIds), '?'));

                $stmt = $pdo->prepare(
                    "DELETE FROM scientific_run_inputs
                     WHERE run_id IN ($runMarks)
                        OR artifact_id IN (
                            SELECT artifact_id FROM scientific_artifacts
                            WHERE run_id IN ($runMarks)
                        )"
                );
                $i = 1;
                foreach ($scientificRunIds as $id) {
                    $stmt->bindValue($i++, $id, PDO::PARAM_INT);
                }
                foreach ($scientificRunIds as $id) {
                    $stmt->bindValue($i++, $id, PDO::PARAM_INT);
                }
                $stmt->execute();

                $stmt = $pdo->prepare(
                    "DELETE FROM scientific_run_parents
                     WHERE child_run_id IN ($runMarks)
                        OR parent_run_id IN ($runMarks)"
                );
                $i = 1;
                foreach ($scientificRunIds as $id) {
                    $stmt->bindValue($i++, $id, PDO::PARAM_INT);
                }
                foreach ($scientificRunIds as $id) {
                    $stmt->bindValue($i++, $id, PDO::PARAM_INT);
                }
                $stmt->execute();
            }

            $stmt = $pdo->prepare("DELETE FROM songs WHERE id IN ($marks)");
            $this->bindIds($stmt, $songIds);
            $stmt->execute();

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach (array_keys($files) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $projectRoot = dirname(__DIR__, 2);
        $storageRoot = rtrim((string)(getenv('EZSTUDIO_STORAGE_ROOT') ?: $projectRoot.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'storage'), '\\/');
        $tmpRoot = rtrim((string)(getenv('EZSTUDIO_TMP_ROOT') ?: $projectRoot.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'tmp'), '\\/');
        if ($hash !== '') {
            $this->removeTree($storageRoot.DIRECTORY_SEPARATOR.'stems'.DIRECTORY_SEPARATOR.$hash);
            $this->removeTree($storageRoot.DIRECTORY_SEPARATOR.'profile'.DIRECTORY_SEPARATOR.$hash);
        }
        foreach ($jobIds as $jobId) $this->removeTree($tmpRoot.DIRECTORY_SEPARATOR.'jobs'.DIRECTORY_SEPARATOR.(string)$jobId);

        foreach ($benchmarkRunIds as $runId) {
            foreach (
                glob(
                    $projectRoot.DIRECTORY_SEPARATOR.'results'
                    .DIRECTORY_SEPARATOR.sprintf('run-%06d-*', $runId)
                ) ?: []
                as $path
            ) {
                $this->removeTree($path);
            }
        }

        return [
            'song_ids' => $songIds,
            'audio_sha256' => $hash,
            'title' => (string)$seed['title'],
            'artist' => (string)$seed['artist'],
        ];
    }

    private function latestScientificState(array $songIds, string $item, bool $completedOnly = false): array
    {
        if (!$this->tableExists('scientific_runs') || $songIds === []) {
            return $this->emptyState();
        }

        $marks = implode(',', array_fill(0, count($songIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id,public_id,state,created_at,engine_version,model_name
             FROM scientific_runs
             WHERE song_id IN ($marks)
               AND item=?
               AND state!='abandoned'
               AND (? = 0 OR state='done')
             ORDER BY id DESC LIMIT 1"
        );
        $i = 1;
        foreach ($songIds as $id) {
            $stmt->bindValue($i++, $id, PDO::PARAM_INT);
        }
        $stmt->bindValue($i++, $item, PDO::PARAM_STR);
        $stmt->bindValue($i, $completedOnly ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();
        $run = $stmt->fetch();

        if (!$run) {
            return $this->emptyState();
        }

        $progress = null;
        $version = trim((string)($run['engine_version'] ?? ''));
        if ($this->tableExists('scientific_run_labels')) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT label,value FROM scientific_run_labels
                 WHERE run_id=?
                   AND label IN ('progress','preset_phase_revision')"
            );
            $stmt->execute([(int)$run['id']]);
            foreach ($stmt->fetchAll() as $labelRow) {
                $label = (string)$labelRow['label'];
                $value = trim((string)$labelRow['value']);
                if ($label === 'progress' && is_numeric($value)) {
                    $progress = max(0, min(100, (int)$value));
                } elseif ($label === 'preset_phase_revision' && $value !== '') {
                    $version = $value;
                }
            }
        }

        $job = null;
        if ($this->tableExists('analysis_jobs')) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id,status,progress,dispatch_owner
                 FROM analysis_jobs
                 WHERE scientific_run_id=?
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([(int)$run['id']]);
            $job = $stmt->fetch() ?: null;
        }

        $viewState = (string)$run['state'];
        if ($job !== null) {
            $viewState = match ((string)$job['status']) {
                'queued' => 'queued',
                'running', 'cancelling' => 'running',
                'cancelled' => 'cancelled',
                'failed' => 'error',
                default => $viewState,
            };
        }

        // Old Orchestrator jobs are retained in SQLite but are not executable
        // by LAB one-shot. Never present them as active jobs or allow a UI cancel.
        if ($item === 'profile' && $job !== null
            && (string)($job['dispatch_owner'] ?? '') === 'orchestrator'
            && in_array($viewState, ['queued', 'running'], true)) {
            $viewState = 'legacy_fifo';
        }

        return [
            'state' => $completedOnly ? 'done' : $viewState,
            'progress' => $job !== null ? (int)$job['progress'] : $progress,
            'run_id' => (int)$run['id'],
            'public_id' => (string)$run['public_id'],
            'created_at' => (string)$run['created_at'],
            'legacy' => false,
            'job_id' => $job !== null ? (int)$job['id'] : null,
            'job_status' => $job !== null ? (string)$job['status'] : null,
            'version' => $version !== '' ? $version : null,
        ];
    }

    private function latestBenchmarkState(array $songIds): ?array
    {
        if ($songIds === []) {
            return null;
        }

        $marks = implode(',', array_fill(0, count($songIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id,status,progress,created_at,engine_version
             FROM benchmark_runs
             WHERE song_id IN ($marks)
             ORDER BY id DESC LIMIT 1"
        );
        $this->bindIds($stmt, $songIds);
        $stmt->execute();
        $run = $stmt->fetch();

        if (!$run) {
            return null;
        }

        $state = match ((string)$run['status']) {
            'done' => 'done',
            'error' => 'error',
            default => (string)$run['status'],
        };

        return [
            'state' => $state,
            'progress' => (int)$run['progress'],
            'run_id' => (int)$run['id'],
            'public_id' => 'legacy-'.(int)$run['id'],
            'created_at' => (string)$run['created_at'],
            'legacy' => true,
            'version' => trim((string)($run['engine_version'] ?? '')) ?: null,
        ];
    }

    private function emptyState(): array
    {
        return [
            'state' => 'none',
            'progress' => null,
            'run_id' => null,
            'public_id' => null,
            'created_at' => null,
            'legacy' => false,
            'job_id' => null,
            'job_status' => null,
            'version' => null,
        ];
    }

    private function tableExists(string $name): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?"
        );
        $stmt->execute([$name]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function bindIds(\PDOStatement $stmt, array $ids): void
    {
        foreach (array_values($ids) as $i => $id) {
            $stmt->bindValue($i + 1, (int)$id, PDO::PARAM_INT);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
