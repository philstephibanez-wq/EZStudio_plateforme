<?php

declare(strict_types=1);

namespace EZSLab\Service;

final class ProfileExternalReviewService
{
    private const SCHEMA = 'ezstudio.profile-external-review.v1';

    public function __construct(private readonly Database $db, private readonly ?LocalProfileQueue $localQueue = null) {}

    public function ensureSchema(): void
    {
        $this->db->pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS profile_external_revisions (
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
CREATE INDEX IF NOT EXISTS idx_profile_external_revisions_run_status
ON profile_external_revisions(run_id,status,updated_at);
SQL);
    }

    public function queue(int $limit = 100): array
    {
        // LEGACY_FIFO_GATE_R1: historic Assistant receives the canonical LAB head,
        // and only the public final assertions belonging to that run.
        $head = $this->legacyAssistantAutoReviewHead();
        if ($head === null) return [];
        $runId = (int)$head['scientific_run_id'];
        try {
            $final = $this->assertionsForRun($runId);
            return [LegacyProfileFinalProjection::fromFinal($runId, $final)];
        } catch (\DomainException $e) {
            // A missing recording identity does not permit a claim or a skip.
            return [];
        }
    }

    private function legacyAssistantAutoReviewHead(): ?array
    {
        $pdo = $this->db->pdo();
        if ((int)$pdo->query(LocalProfileQueue::activeSql())->fetchColumn() > 0) return null;
        $head = $pdo->query(LocalProfileQueue::headSql())->fetch(\PDO::FETCH_ASSOC);
        if (!$head || (string)$head['kind'] !== 'assistant.profile.review') return null;
        $paused = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='profile_ai_maintenance'")->fetchColumn();
        if ($paused && (int)$pdo->query('SELECT paused FROM profile_ai_maintenance WHERE id=1')->fetchColumn() === 1) return null;
        return $head;
    }

    public function reviewItemForRun(int $runId): ?array
    {
        $stmt = $this->db->pdo()->prepare(<<<'SQL'
SELECT sr.id AS run_id,sr.public_id,sr.song_id,sr.state,sr.engine_name,sr.engine_version,
       sr.model_name,sr.checkpoint,sr.config_json,sr.metrics_json,sr.diagnostics_json,
       sr.finished_at,s.title,s.artist,s.audio_sha256
FROM scientific_runs sr JOIN songs s ON s.id=sr.song_id
WHERE sr.id=? AND sr.item='profile' AND sr.state='done'
LIMIT 1
SQL);
        $stmt->execute([$runId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'schema'=>'ezstudio.profile-review-queue-item.v1',
            'run_id'=>(int)$row['run_id'], 'public_id'=>(string)$row['public_id'],
            'song_id'=>(int)$row['song_id'], 'title'=>(string)$row['title'],
            'artist'=>(string)$row['artist'],'audio_sha256'=>(string)$row['audio_sha256'],
            'state'=>(string)$row['state'],
            'engine'=>['name'=>(string)$row['engine_name'],'version'=>(string)$row['engine_version'],
                'model'=>(string)$row['model_name'],'checkpoint'=>(string)$row['checkpoint']],
            'config'=>$this->decodeObject((string)$row['config_json']),
            'metrics'=>$this->decodeObject((string)$row['metrics_json']),
            'diagnostics'=>$this->decodeObject((string)$row['diagnostics_json']),
            'finished_at'=>$row['finished_at']!==null?(string)$row['finished_at']:null,
        ];
    }

    public function save(array $payload): array
    {
        $this->ensureSchema();

        $runId = filter_var(
            $payload['run_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($runId === false) {
            throw new \InvalidArgumentException('profile_external_review_run_id_required');
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT id,item,state FROM scientific_runs WHERE id=?"
        );
        $stmt->execute([(int)$runId]);
        $run = $stmt->fetch();
        if (!$run || (string)$run['item'] !== 'profile') {
            throw new \RuntimeException('profile_external_review_profile_run_required');
        }
        if ((string)$run['state'] !== 'done') {
            throw new \RuntimeException('profile_external_review_run_not_done');
        }

        $status = strtolower(trim((string)($payload['status'] ?? 'completed')));
        if (!in_array($status, ['draft', 'completed'], true)) {
            throw new \InvalidArgumentException('profile_external_review_status_invalid');
        }

        $identity = is_array($payload['identity'] ?? null) ? $payload['identity'] : [];
        $sources = is_array($payload['sources'] ?? null) ? array_values($payload['sources']) : [];
        $consensus = is_array($payload['consensus'] ?? null) ? $payload['consensus'] : [];
        $comparison = is_array($payload['comparison'] ?? null) ? $payload['comparison'] : [];
        // Advisory PROFILE coverage is metadata, never an external validation.
        $ai = is_array($comparison['assistant_ai'] ?? null) ? $comparison['assistant_ai'] : [];
        $coverage = is_array($ai['coverage'] ?? null) ? $ai['coverage'] : [];
        if (($coverage['schema'] ?? null) === 'ezs.profile-ai-coverage.v1') {
            $scored = filter_var($coverage['scored_count'] ?? null, FILTER_VALIDATE_INT);
            $total = filter_var($coverage['total_count'] ?? null, FILTER_VALIDATE_INT);
            if ($scored === false || $total !== 5 || $scored < 0 || $scored > 5) {
                throw new \InvalidArgumentException('profile_ai_coverage_invalid');
            }
            $comparison['assistant_ai']['coverage_summary'] = [
                'scored' => $scored,
                'total' => 5,
                'complete' => $scored === 5,
                'advisory_only' => true,
            ];
        }


        if ($status === 'completed' && $sources === []) {
            throw new \InvalidArgumentException('profile_external_review_completed_requires_sources');
        }

        $confidence = $payload['confidence'] ?? null;
        if (!is_numeric($confidence)) {
            throw new \InvalidArgumentException('profile_external_review_confidence_required');
        }
        $confidence = (float)$confidence;
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException('profile_external_review_confidence_out_of_range');
        }

        $reviewer = trim((string)($payload['reviewer'] ?? 'assistant'));
        if ($reviewer === '') {
            $reviewer = 'assistant';
        }
        $reviewer = mb_substr($reviewer, 0, 100);

        // LEGACY_FIFO_COMPLETION_R1: historical Assistant posts the completed
        // evidence to /profile/reviews; do not allow it to skip a queued head.
        $legacyAssistant = $reviewer === 'assistant-auto' && $status === 'completed';
        if ($legacyAssistant) {
            $head = $this->legacyAssistantAutoReviewHead();
            if ($head === null || (int)$head['scientific_run_id'] !== (int)$runId) {
                throw new \RuntimeException('legacy_assistant_not_fifo_head');
            }
        }

        $canonical = json_encode(
            [
                'identity' => $identity,
                'sources' => $sources,
                'consensus' => $consensus,
                'comparison' => $comparison,
                'confidence' => $confidence,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($canonical)) {
            throw new \RuntimeException('profile_external_review_json_encode_failed');
        }

        $revisionKey = trim((string)($payload['revision_key'] ?? ''));
        if ($revisionKey === '') {
            $revisionKey = 'profile-'.$runId.'-'.substr(hash('sha256', $canonical), 0, 16);
        }
        $revisionKey = mb_substr($revisionKey, 0, 120);

        $persist = function () use ($runId, $revisionKey, $reviewer, $status, $identity, $sources, $consensus, $comparison, $confidence): array {
            $now = gmdate('c');
            $upsert = $this->db->pdo()->prepare(
                'INSERT INTO profile_external_revisions(
                    run_id,revision_key,reviewer,status,
                    identity_json,sources_json,consensus_json,comparison_json,
                    confidence,schema_version,created_at,updated_at
                 ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
                 ON CONFLICT(run_id,revision_key) DO UPDATE SET
                    reviewer=excluded.reviewer,
                    status=excluded.status,
                    identity_json=excluded.identity_json,
                    sources_json=excluded.sources_json,
                    consensus_json=excluded.consensus_json,
                    comparison_json=excluded.comparison_json,
                    confidence=excluded.confidence,
                    schema_version=excluded.schema_version,
                    updated_at=excluded.updated_at'
            );
            $upsert->execute([
                (int)$runId,
                $revisionKey,
                $reviewer,
                $status,
                json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($consensus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($comparison, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $confidence,
                self::SCHEMA,
                $now,
                $now,
            ]);

            $select = $this->db->pdo()->prepare(
                'SELECT * FROM profile_external_revisions
                 WHERE run_id=? AND revision_key=?'
            );
            $select->execute([(int)$runId, $revisionKey]);
            $row = $select->fetch();
            if (!$row) {
                throw new \RuntimeException('profile_external_review_persist_failed');
            }

            return $this->hydrate($row);
        };
        if ($legacyAssistant) {
            return (new ProfileAiRequestService($this->db, $this->localQueue))->completeLegacyReview((int)$runId, $persist);
        }
        // An Assistant draft must not be persisted after an operator cancellation.
        // The immediate transaction serializes status check and revision write
        // against the existing cancellation transaction on the SAME LAB SQLite DB.
        if ($reviewer === 'assistant-lab-ui-qwen') {
            $pdo=$this->db->pdo();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $stmt=$pdo->prepare("SELECT status FROM analysis_jobs WHERE scientific_run_id=? AND kind='assistant.profile.review' AND dispatch_owner='lab' ORDER BY id DESC LIMIT 1");
                $stmt->execute([(int)$runId]);
                if ($stmt->fetchColumn()!=='running') throw new \RuntimeException('expertise_not_running_review_rejected');
                $revision=$persist();
                $pdo->commit();
                return $revision;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
        return $persist();
    }

    public function latestReviewForDisplay(?int $runId = null): ?array
    {
        $this->ensureSchema();
        $sql = <<<'SQL'
SELECT r.*, sr.public_id, sr.config_json, s.title, s.artist
FROM profile_external_revisions r
JOIN scientific_runs sr ON sr.id = r.run_id
JOIN songs s ON s.id = sr.song_id
WHERE sr.item = 'profile' AND sr.state = 'done'
  AND (? IS NULL OR r.run_id = ?)
ORDER BY r.id DESC LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$runId, $runId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $revision = $this->hydrate($row);
        $configuration = $this->decodeObject((string)$row['config_json']);
        // Preset reference only; omit scientific configuration from the response.
        $preset = $configuration['preset_id'] ?? $configuration['preset_number'] ?? null;
        if (!is_scalar($preset) || trim((string)$preset) === '') {
            $preset = null;
        }
        $verification = $revision['comparison']['final_verification'] ?? [];
        $assertions = $verification['assertions'] ?? [];
        $byKey = [];
        foreach ($assertions as $assertion) {
            if (is_array($assertion) && isset($assertion['key'])) {
                $byKey[(string)$assertion['key']] = $assertion;
            }
        }
        $investigations = $verification['web_investigation']['items'] ?? [];
        $values = [];
        foreach ($investigations as $item) {
            if (is_array($item) && isset($item['key'])) $values[(string)$item['key']] = $item;
        }
        $reviews = $verification['local_web_review']['items'] ?? [];
        $ai = [];
        foreach ($reviews as $item) {
            if (is_array($item) && isset($item['key'])) $ai[(string)$item['key']] = $item;
        }
        $keys = [
            'duration'=>'Durée', 'tempo'=>'Tempo', 'meter'=>'Mesure',
            'key'=>'Tonalité', 'genre'=>'Genre', 'instrument:0'=>'Instruments',
            'lead_vocal'=>'Voix principale', 'backing_vocals'=>'Voix d’accompagnement',
            'vocal_harmonies'=>'Harmonies vocales', 'choir'=>'Chœurs',
        ];
        $items = [];
        foreach ($keys as $key => $label) {
            $assertion = $byKey[$key] ?? [];
            $review = $ai[$key] ?? [];
            $candidate = $values[$key] ?? [];
            $verdict = strtolower((string)($assertion['verdict'] ?? 'non_trouve'));
            $items[] = [
                'key'=>$key, 'label'=>$label,
                'lab_value'=>$candidate['value'] ?? $assertion['value'] ?? null,
                'external_value'=>in_array($verdict, ['bon','faux'], true) ? ($assertion['reference_value'] ?? null) : null,
                'verdict'=>$verdict === 'bon' ? 'BON' : ($verdict === 'faux' ? 'FAUX' : ($verdict === 'sans_valeur_lab' ? 'SANS VALEUR LAB' : 'NON TROUVÉ')),
                'reason'=>$assertion['reason'] ?? $review['explanation'] ?? null,
                'proposed_verdict'=>$assertion['proposed_verdict'] ?? null,
                'documentary_observation'=>$assertion['documentary_observation'] ?? null,
                'references'=>$assertion['references'] ?? [],
                'sources'=>array_values(array_unique(array_filter(
                    $assertion['citations'] ?? $review['citations'] ?? [],
                    static fn($url) => is_string($url) && str_starts_with($url, 'https://')
                ))),
            ];
        }
        return [
            'run_id'=>(int)$row['run_id'],
            'analysis_reference'=>(string)$row['public_id'],
            'preset_reference'=>$preset === null ? null : (string)$preset,
            'review_id'=>$revision['id'],
            'review_status'=>$revision['status'],
            'identity_declared'=>['title'=>(string)$row['title'], 'artist'=>(string)$row['artist']],
            'identity_confirmed'=>false,
            'documentary_catalogue_suggestions'=>$this->documentaryDisplayProjection(
                $revision['comparison']['documentary_catalogue_suggestions'] ?? null
            ),
            'items'=>$items,
            'final_assertions_schema'=>'ezstudio.profile-final-assertions.v1',
            'final_assertions'=>$assertions,
            'final_assertions_count'=>count($assertions),
        ];
    }

    /** A strict, bounded advisory projection for the Assistant dashboard. */
    private function documentaryDisplayProjection(mixed $input): ?array
    {
        if (!is_array($input) || ($input['schema'] ?? '') !==
            'ezstudio.assistant.catalogue-suggestions.v1') {
            return null;
        }
        $source = static function (mixed $value): array {
            if (!is_array($value)) return [];
            $out = [];
            foreach (array_slice($value, 0, 12) as $url) {
                if (!is_string($url) || strlen($url) > 600 ||
                    !str_starts_with($url, 'https://')) continue;
                $parsed = parse_url($url);
                if (!is_array($parsed) || empty($parsed['host']) ||
                    isset($parsed['user']) || isset($parsed['pass'])) continue;
                $out[] = $url;
            }
            return array_values(array_unique($out));
        };
        $field = static function (mixed $item) use ($source): ?array {
            if (!is_array($item)) return null;
            $value = $item['value'] ?? null;
            if (!is_string($value) || trim($value) === '') return null;
            $status = $item['status'] ?? 'candidate';
            if (!in_array($status, ['candidate','corroborated','unknown'], true)) {
                $status = 'candidate';
            }
            return ['value'=>mb_substr(trim($value), 0, 180),
                    'status'=>$status, 'sources'=>$source($item['sources'] ?? [])];
        };
        $people = static function (mixed $items) use ($field): array {
            if (!is_array($items)) return [];
            $result = [];
            foreach (array_slice($items, 0, 30) as $item) {
                $entry = $field($item);
                if ($entry !== null) $result[] = $entry;
            }
            return $result;
        };
        $work = is_array($input['work'] ?? null) ? $input['work'] : [];
        $recording = is_array($input['recording'] ?? null) ? $input['recording'] : [];
        return [
            'schema'=>'ezstudio.assistant.catalogue-suggestions.v1',
            'advisory_only'=>true,
            'identity_verified'=>false,
            'recording_identity_verified'=>false,
            'work'=>[
                'full_title'=>$field($work['full_title'] ?? null),
                'lyricists'=>$people($work['lyricists'] ?? []),
                'composers'=>$people($work['composers'] ?? []),
            ],
            'recording'=>[
                'performers'=>$people($recording['performers'] ?? []),
                'arrangers'=>$people($recording['arrangers'] ?? []),
                'musician_credits'=>array_values(array_filter(array_map(
                    static function ($credit) use ($source) {
                        if (!is_array($credit) || !is_string($credit['name'] ?? null) ||
                            !is_string($credit['role'] ?? null)) return null;
                        return ['name'=>mb_substr(trim($credit['name']), 0, 180),
                                'role'=>mb_substr(trim($credit['role']), 0, 80),
                                'status'=>'candidate',
                                'sources'=>$source([$credit['source'] ?? null])];
                    }, array_slice(is_array($recording['musician_credits'] ?? null) ?
                        $recording['musician_credits'] : [], 0, 30)
                ))),
            ],
            'consulted_identity_sources'=>$source($input['consulted_identity_sources'] ?? []),
        ];
    }

    /** Public assertions only, derived from the finished scientific run itself. */
    public function assertionsForRun(int $runId): array
    {
        $stmt = $this->db->pdo()->prepare(<<<'SQL'
SELECT sr.id,sr.config_json,sr.metrics_json,sr.diagnostics_json,s.title,s.artist
FROM scientific_runs sr JOIN songs s ON s.id=sr.song_id
WHERE sr.id=? AND sr.item='profile' AND sr.state='done'
LIMIT 1
SQL);
        $stmt->execute([$runId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('profile_scientific_run_not_ready');
        }
        $title = trim((string)$row['title']);
        $artist = trim((string)$row['artist']);
        if ($title === '') {
            throw new \RuntimeException('profile_title_required');
        }
        $metrics = $this->decodeObject((string)$row['metrics_json']);
        $config = $this->decodeObject((string)$row['config_json']);
        $diagnostics = $this->decodeObject((string)$row['diagnostics_json']);
        $profileView = is_array($diagnostics['profile_view'] ?? null) ? $diagnostics['profile_view'] : [];
        // No diagnostics, engine parameters, checkpoints, paths or stems cross this boundary.
        $paths = [
            'duree'=>['duration_seconds','duration_s','duration'],
            'tempo'=>['tempo_bpm','bpm','tempo'],
            'mesure'=>['time_signature','meter'],
            'tonalite'=>['key','tonality'],
            'genre'=>['genre','primary_genre'],
            'instruments'=>['instruments','instrumentation'],
            'voix_principale'=>['lead_vocal'],
            'voix_accompagnement'=>['backing_vocals'],
            'harmonies_vocales'=>['vocal_harmonies'],
            'choeurs'=>['choir'],
        ];
        $items = [];
        foreach ($paths as $item => $candidates) {
            $value = null;
            foreach ($candidates as $candidate) {
                if (array_key_exists($candidate, $metrics)) {
                    $raw = $metrics[$candidate];
                    if (is_scalar($raw) && !is_bool($raw) && trim((string)$raw) !== '') {
                        $value = $raw;
                    } elseif (is_array($raw) && array_is_list($raw)) {
                        $filtered = array_values(array_filter($raw, static fn($v) =>
                            is_scalar($v) && !is_bool($v) && trim((string)$v) !== ''
                        ));
                        $value = $filtered === [] ? null : $filtered;
                    }
                    if ($value !== null) break;
                }
            }
            $items[$item] = $value;
        }
        // Read only consolidated musical conclusions, not engine/module outputs.
        // "possible" and other uncertain decisions are not final assertions.
        $finalScalar = static function (mixed $value): mixed {
            if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || (is_string($value) && in_array(mb_strtolower($value),
                ['possible','inconclusive','indéterminé','indetermine','not_scored','unknown','none'],true))) return null;
            return $value;
        };
        $tempo = $metrics['tempo']['bpm'] ?? null;
        if (is_numeric($tempo) && (float)$tempo > 0 && (float)$tempo <= 400) {
            $items['tempo'] = (float)$tempo;
        } elseif (!is_scalar($items['tempo'] ?? null)) {
            $items['tempo'] = null;
        }
        $items['mesure'] = $finalScalar($metrics['time_signature']['label'] ?? $metrics['time_signature']['suggested_label'] ?? $items['mesure']);
        $items['tonalite'] = $finalScalar($metrics['key']['label'] ?? $profileView['tonal']['label'] ?? $items['tonalite']);

        // Instrument candidates are not finalized instruments. Only explicit
        // accepted/confirmed decisions may cross the boundary.
        $confirmed = [];
        foreach (($profileView['instrumentation']['candidates'] ?? []) as $candidate) {
            if (!is_array($candidate)) continue;
            $decision = strtolower(trim((string)($candidate['decision'] ?? $candidate['status'] ?? '')));
            if (!in_array($decision,['accepted','confirmed','detected'],true)) continue;
            $label = $finalScalar($candidate['label'] ?? null);
            if (is_string($label) && !in_array($label,$confirmed,true)) $confirmed[] = $label;
        }
        $items['instruments'] = $confirmed === [] ? null : $confirmed;
        $choirs = $profileView['choirs']['decision'] ?? null;
        $items['choeurs'] = $choirs === 'detected' ? 'présents' :
            ($choirs === 'not_detected' || $choirs === 'absent' ? 'absents' : null);
        // Tagging/voice scores are candidate suggestions, not final assertions.
        // They remain only in LAB; emit no genre/voice inferred from rankings.
        foreach (['genre','voix_principale','voix_accompagnement','harmonies_vocales'] as $candidateOnly) {
            if (!isset($metrics['final_musical_assertions'])) $items[$candidateOnly] = null;
        }
        // Public, typed inventory. Historical "items" remains untouched for existing clients.
        // This intentionally does NOT enumerate arbitrary metrics or private diagnostics.
        $assertions = [];
        $seen = [];
        $emit = static function (string $id, string $dimension, mixed $value) use (&$assertions, &$seen): void {
            if (!preg_match('/^[a-z][a-z0-9_.:-]{0,119}$/', $id) || isset($seen[$id])
                || !is_scalar($value) || is_bool($value) || trim((string)$value) === '') {
                return;
            }
            $seen[$id] = true;
            $assertions[] = ['id'=>$id,'dimension'=>$dimension,'value'=>$value];
        };
        foreach ($items as $dimension=>$value) {
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $index=>$member) {
                    $emit($dimension.':'.$index, $dimension, $member);
                }
            } elseif ($value !== null) {
                $emit($dimension, $dimension, $value);
            }
        }
        // Future public final outputs: extensible contract with explicit whitelist of
        // published FINAL assertions, never raw model/module/preset diagnostic data.
        foreach (($metrics['final_musical_assertions'] ?? []) as $entry) {
            if (!is_array($entry) || !isset($entry['id'],$entry['dimension'],$entry['value'])
                || !is_string($entry['id']) || !is_string($entry['dimension'])) {
                continue;
            }
            $emit($entry['id'], $entry['dimension'], $entry['value']);
        }
        $preset = $config['preset_id'] ?? $config['preset_number'] ?? null;
        if (!is_string($preset) && !is_int($preset)) $preset = null;
        return [
            'expertise_type'=>'profile', 'analysis_id'=>$runId,
            'preset_id'=>$preset, 'title'=>$title, 'artist'=>$artist === '' ? null : $artist,
            'items'=>$items,
            'final_assertions_schema'=>'ezstudio.profile-final-assertions.v1',
            'final_assertions'=>$assertions,
            'final_assertions_count'=>count($assertions),
        ];
    }

        public function revisionsForRun(int $runId): array
    {
        $this->ensureSchema();
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM profile_external_revisions
             WHERE run_id=? ORDER BY id DESC'
        );
        $stmt->execute([$runId]);

        return array_map(
            fn(array $row): array => $this->hydrate($row),
            $stmt->fetchAll()
        );
    }

    private function hydrate(array $row): array
    {
        return [
            'schema' => (string)$row['schema_version'],
            'id' => (int)$row['id'],
            'run_id' => (int)$row['run_id'],
            'revision_key' => (string)$row['revision_key'],
            'reviewer' => (string)$row['reviewer'],
            'status' => (string)$row['status'],
            'identity' => $this->decodeObject((string)$row['identity_json']),
            'sources' => $this->decodeList((string)$row['sources_json']),
            'consensus' => $this->decodeObject((string)$row['consensus_json']),
            'comparison' => $this->decodeObject((string)$row['comparison_json']),
            'confidence' => (float)$row['confidence'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }

    private function decodeObject(string $json): array
    {
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }

    private function decodeList(string $json): array
    {
        $value = json_decode($json, true);
        return is_array($value) ? array_values($value) : [];
    }
}
