<?php

namespace EZSLab\Service;

final class LabCatalog
{
    public function __construct(
        private readonly Database $db,
        private readonly LabJobStore $jobs,
    ) {}

    public function songs(): array
    {
        $songs = $this->db->pdo()->query(
            'SELECT id,title,artist,source_filename,audio_sha256,created_at
             FROM songs ORDER BY id DESC'
        )->fetchAll();

        $groups = [];
        foreach ($songs as $song) {
            $hash = trim((string)$song['audio_sha256']);
            if ($hash === '') {
                $hash = 'song:'.(int)$song['id'];
            }

            if (!isset($groups[$hash])) {
                $groups[$hash] = [
                    'id' => (int)$song['id'],
                    'title' => (string)$song['title'],
                    'artist' => (string)$song['artist'],
                    'source_filename' => (string)$song['source_filename'],
                    'audio_sha256' => (string)$song['audio_sha256'],
                    'created_at' => (string)$song['created_at'],
                    'song_ids' => [],
                    'runs' => [],
                    'run_count' => 0,
                    'latest_run_id' => null,
                    'latest_chords_run_id' => null,
                    'stems_runs' => [],
                    'stems_count' => 0,
                    'stems_ready' => false,
                    'chords_count' => 0,
                    'chords_ready' => false,
                    'lyrics_count' => 0,
                    'lyrics_ready' => false,
                ];
            }

            $groups[$hash]['song_ids'][] = (int)$song['id'];
        }

        foreach ($groups as &$group) {
            $ids = $group['song_ids'];
            if ($ids !== []) {
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
                $group['chords_count'] = count(array_filter(
                    $runs,
                    static fn(array $r): bool => (string)($r['status'] ?? '') === 'done'
                ));
                $group['chords_ready'] = $group['chords_count'] > 0;

                foreach ($runs as $run) {
                    if ($group['latest_run_id'] === null) {
                        $group['latest_run_id'] = (int)$run['id'];
                    }
                    if (
                        $group['latest_chords_run_id'] === null
                        && (string)($run['status'] ?? '') === 'done'
                    ) {
                        $group['latest_chords_run_id'] = (int)$run['id'];
                    }

                    if (!empty($run['result_json'])) {
                        $result = json_decode((string)$run['result_json'], true);
                        if (is_array($result) && !empty($result['lyrics'])) {
                            ++$group['lyrics_count'];
                            $group['lyrics_ready'] = true;
                        }
                    }
                }
            }

            $audioHash = trim((string)$group['audio_sha256']);
            if ($audioHash !== '') {
                $stemRuns = $this->jobs->jobsForAudioHash($audioHash);
                $group['stems_runs'] = $stemRuns;
                $group['stems_count'] = count($stemRuns);
                $group['stems_ready'] = count(array_filter(
                    $stemRuns,
                    static fn(array $j): bool => (string)($j['state'] ?? '') === 'done'
                )) > 0;
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

    public function song(?int $songId): ?array
    {
        $songs = $this->songs();
        if ($songs === []) {
            return null;
        }

        if ($songId !== null) {
            foreach ($songs as $song) {
                if (
                    (int)$song['id'] === $songId
                    || in_array($songId, $song['song_ids'], true)
                ) {
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

    public function chordsComparison(): array
    {
        $sql = <<<'SQL'
SELECT
    ar.algorithm,
    COUNT(DISTINCT s.audio_sha256) AS songs,
    COUNT(DISTINCT ar.run_id) AS runs,
    AVG(ar.score) AS avg_score,
    AVG(ar.margin) AS avg_margin,
    SUM(CASE WHEN rv.status='approved' THEN 1 ELSE 0 END) AS approved,
    SUM(CASE WHEN rv.status='rejected' THEN 1 ELSE 0 END) AS rejected,
    SUM(CASE WHEN rv.status IS NOT NULL THEN 1 ELSE 0 END) AS reviewed
FROM algorithm_results ar
JOIN benchmark_runs br ON br.id=ar.run_id AND br.status='done'
JOIN songs s ON s.id=br.song_id
LEFT JOIN algorithm_reviews rv
  ON rv.run_id=ar.run_id AND rv.algorithm=ar.algorithm
GROUP BY ar.algorithm
ORDER BY ar.algorithm
SQL;

        $rows = $this->db->pdo()->query($sql)->fetchAll();
        foreach ($rows as &$row) {
            $reviewed = (int)$row['reviewed'];
            $approved = (int)$row['approved'];
            $row['approval_rate'] = $reviewed > 0
                ? round(100.0 * $approved / $reviewed, 1)
                : null;
        }
        unset($row);

        usort($rows, static function(array $a, array $b): int {
            $as = $a['approval_rate'] ?? -1;
            $bs = $b['approval_rate'] ?? -1;
            return $bs <=> $as;
        });

        return $rows;
    }

    public function stemsComparison(): array
    {
        $matrix = [];

        foreach ($this->jobs->all() as $job) {
            if ((string)($job['kind'] ?? '') !== 'stems') {
                continue;
            }

            $request = is_array($job['request'] ?? null) ? $job['request'] : [];
            $profile = trim((string)($request['engine_profile'] ?? 'canonical_roformer'));
            if ($profile === '') {
                $profile = 'canonical_roformer';
            }

            if (!isset($matrix[$profile])) {
                $matrix[$profile] = [
                    'engine_profile' => $profile,
                    'songs' => [],
                    'runs' => 0,
                    'done' => 0,
                    'error' => 0,
                ];
            }

            ++$matrix[$profile]['runs'];
            $hash = trim((string)($request['audio_hash'] ?? ''));
            if ($hash !== '') {
                $matrix[$profile]['songs'][$hash] = true;
            }

            $state = (string)($job['state'] ?? '');
            if ($state === 'done') {
                ++$matrix[$profile]['done'];
            } elseif ($state === 'error') {
                ++$matrix[$profile]['error'];
            }
        }

        $rows = [];
        foreach ($matrix as $row) {
            $row['songs'] = count($row['songs']);
            $rows[] = $row;
        }

        usort($rows, static fn(array $a, array $b): int => $b['runs'] <=> $a['runs']);
        return $rows;
    }
}
