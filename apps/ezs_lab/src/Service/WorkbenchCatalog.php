<?php

namespace EZSLab\Service;

final class WorkbenchCatalog
{
    public function __construct(private readonly Database $db) {}

    public function songs(): array
    {
        $songs = $this->db->pdo()->query(
            'SELECT id,title,artist,source_filename,audio_sha256,created_at
             FROM songs
             ORDER BY id DESC'
        )->fetchAll();

        $groups = [];

        foreach ($songs as $song) {
            $hash = (string)$song['audio_sha256'];
            if (!isset($groups[$hash])) {
                $groups[$hash] = [
                    'id' => (int)$song['id'],
                    'title' => (string)$song['title'],
                    'artist' => (string)$song['artist'],
                    'source_filename' => (string)$song['source_filename'],
                    'audio_sha256' => $hash,
                    'created_at' => (string)$song['created_at'],
                    'song_ids' => [],
                    'runs' => [],
                    'run_count' => 0,
                    'latest_run_id' => null,
                    'latest_chords_run_id' => null,
                    'stems_ready' => false,
                    'chords_ready' => false,
                    'lyrics_ready' => false,
                ];
            }
            $groups[$hash]['song_ids'][] = (int)$song['id'];
        }

        foreach ($groups as &$group) {
            $ids = $group['song_ids'];
            if ($ids === []) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT r.*
                 FROM benchmark_runs r
                 WHERE r.song_id IN ($placeholders)
                 ORDER BY r.id DESC"
            );

            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 1, $id, \PDO::PARAM_INT);
            }

            $stmt->execute();
            $runs = $stmt->fetchAll();

            $group['runs'] = $runs;
            $group['run_count'] = count($runs);

            foreach ($runs as $run) {
                $runId = (int)$run['id'];

                if ($group['latest_run_id'] === null) {
                    $group['latest_run_id'] = $runId;
                }

                if ((string)$run['status'] === 'done' && $group['latest_chords_run_id'] === null) {
                    $group['latest_chords_run_id'] = $runId;
                    $group['chords_ready'] = true;
                }

                if (!empty($run['result_json'])) {
                    $result = json_decode((string)$run['result_json'], true);

                    if (is_array($result)) {
                        $stems = $result['no_chord_benchmark']['stems'] ?? null;
                        if (is_array($stems) && !empty($stems['available'])) {
                            $group['stems_ready'] = true;
                        }

                        $lyrics = $result['lyrics'] ?? null;
                        if (is_array($lyrics) && !empty($lyrics)) {
                            $group['lyrics_ready'] = true;
                        }
                    }
                }
            }
        }
        unset($group);

        $rows = array_values($groups);

        usort(
            $rows,
            static fn(array $a, array $b): int =>
                strcasecmp($a['title'].' '.$a['artist'], $b['title'].' '.$b['artist'])
        );

        return $rows;
    }

    public function selectedSong(?int $songId): ?array
    {
        $songs = $this->songs();

        if ($songs === []) {
            return null;
        }

        if ($songId !== null) {
            foreach ($songs as $song) {
                if ((int)$song['id'] === $songId || in_array($songId, $song['song_ids'], true)) {
                    return $song;
                }
            }
        }

        return $songs[0];
    }


    public function sourcePath(array $song): ?string
    {
        $ids = $song['song_ids'] ?? [(int)$song['id']];
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT source_path FROM songs WHERE id IN ($placeholders) ORDER BY id DESC"
            );
            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 1, (int)$id, \PDO::PARAM_INT);
            }
            $stmt->execute();
            foreach ($stmt->fetchAll() as $row) {
                $path = trim((string)($row['source_path'] ?? ''));
                if ($path !== '' && is_file($path)) return $path;
            }
        }
        foreach ($song['runs'] ?? [] as $run) {
            $path = trim((string)($run['input_path'] ?? ''));
            if ($path !== '' && is_file($path)) return $path;
        }
        return null;
    }

    public function latestRun(?array $song): ?array
    {
        if (!$song || empty($song['runs'])) {
            return null;
        }

        return $song['runs'][0] ?? null;
    }

    public function latestChordsRun(?array $song): ?array
    {
        if (!$song) {
            return null;
        }

        foreach ($song['runs'] as $run) {
            if ((string)$run['status'] === 'done') {
                return $run;
            }
        }

        return null;
    }
}
