<?php

namespace EZSLab\Service;

final class ProfileValidationService
{
    private const VERDICTS = ['ok', 'ko', 'unknown'];
    private const CERTAINTIES = ['', 'certain', 'probable', 'uncertain'];
    private const VALIDATION_SCHEMA = 'ezstudio.profile-human.v2';

    public function __construct(private readonly Database $db) {}

    public function ensureSchema(): void
    {
        $this->db->pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS profile_human_validations (
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
    PRIMARY KEY(run_id, subject_key),
    FOREIGN KEY(run_id) REFERENCES scientific_runs(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_profile_human_validation_subject
ON profile_human_validations(subject_key, verdict);

CREATE INDEX IF NOT EXISTS idx_profile_human_validation_phase_revision
ON profile_human_validations(phase_revision_ref, verdict);

CREATE INDEX IF NOT EXISTS idx_profile_human_validation_module_revision
ON profile_human_validations(module_revision_ref, verdict);
SQL);

        $this->ensureColumn(
            'profile_human_validations',
            'missing_expected_json',
            "TEXT NOT NULL DEFAULT '[]'"
        );
        $this->ensureColumn(
            'profile_human_validations',
            'reviewer_certainty',
            "TEXT NOT NULL DEFAULT ''"
        );
        $this->ensureColumn(
            'profile_human_validations',
            'validation_schema',
            "TEXT NOT NULL DEFAULT 'ezstudio.profile-human.v2'"
        );

        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS idx_profile_human_validation_certainty
             ON profile_human_validations(reviewer_certainty, verdict)'
        );
    }

    public function subjectsForRun(array $run): array
    {
        if ((string)($run['item'] ?? '') !== 'profile') {
            throw new \InvalidArgumentException('profile_run_required');
        }

        $subjects = [];
        $metrics = is_array($run['metrics'] ?? null) ? $run['metrics'] : [];
        $diagnostics = is_array($run['diagnostics'] ?? null) ? $run['diagnostics'] : [];
        $tagging = is_array($diagnostics['tagging'] ?? null) ? $diagnostics['tagging'] : [];
        $view = is_array($diagnostics['profile_view'] ?? null) ? $diagnostics['profile_view'] : [];

        $tempo = $metrics['tempo']['bpm'] ?? null;
        if (is_numeric($tempo)) {
            $subjects[] = $this->subject(
                'phase:tempo', 'tempo', 'Tempo',
                ['bpm' => (float)$tempo],
                number_format((float)$tempo, 2, '.', '').' BPM'
            );
        }

        $meter = $metrics['time_signature']['label']
            ?? $metrics['time_signature']['suggested_label']
            ?? null;
        if (is_string($meter) && trim($meter) !== '') {
            $payload = is_array($metrics['time_signature'] ?? null)
                ? $metrics['time_signature']
                : ['label' => $meter];
            $text = trim((string)($payload['label'] ?? ''));
            if ($text === '') {
                $text = trim((string)($payload['suggested_label'] ?? ''));
            }
            if (isset($payload['confidence']) && is_numeric($payload['confidence'])) {
                $text .= ' · score '.number_format((float)$payload['confidence'] * 100, 1).' %';
            }
            $subjects[] = $this->subject(
                'phase:time_signature', 'time_signature', 'Signature',
                $payload,
                $text !== '' ? $text : $this->compactJson($payload)
            );
        }

        $keyPayload = is_array($metrics['key'] ?? null) ? $metrics['key'] : [];
        $key = $keyPayload['label'] ?? ($view['tonal']['label'] ?? null);
        $candidate = $keyPayload['candidate_label'] ?? ($view['tonal']['candidate_label'] ?? null);
        if ((is_string($key) && trim($key) !== '') || (is_string($candidate) && trim($candidate) !== '')) {
            if ($keyPayload === []) {
                $keyPayload = [
                    'label' => $key,
                    'candidate_label' => $candidate,
                    'support' => $view['tonal']['support'] ?? null,
                    'total_sources' => $view['tonal']['total_sources'] ?? null,
                    'agreement_ratio' => $view['tonal']['agreement_ratio'] ?? null,
                ];
            }
            $text = trim((string)($key ?? $candidate ?? ''));
            $support = $keyPayload['support'] ?? ($view['tonal']['support'] ?? null);
            $total = $keyPayload['total_sources'] ?? ($view['tonal']['total_sources'] ?? null);
            if (is_numeric($support) && is_numeric($total) && (int)$total > 0) {
                $text .= ' · consensus '.(int)$support.'/'.(int)$total;
            }
            $subjects[] = $this->subject('phase:key', 'key', 'Tonalité finale', $keyPayload, $text);
        }

        foreach ([
            'genre' => 'Genre',
            'voice' => 'Voix',
            'mood' => 'Ambiance',
        ] as $family => $label) {
            $best = $this->bestScoredRow($tagging[$family] ?? []);
            if ($best === null) continue;
            $sourceLabel = trim((string)($best['label'] ?? ''));
            if ($sourceLabel === '') continue;
            $text = $sourceLabel;
            if (isset($best['score']) && is_numeric($best['score'])) {
                $text .= ' · score '.number_format((float)$best['score'] * 100, 1).' %';
            }
            $subjects[] = $this->subject(
                'phase:'.$family,
                $family,
                $label,
                $best,
                $text
            );
        }

        $instrumentation = is_array($view['instrumentation'] ?? null)
            ? $view['instrumentation']
            : [];
        $instrumentCandidates = is_array($instrumentation['candidates'] ?? null)
            ? $instrumentation['candidates']
            : [];
        $instrumentLabels = [];
        foreach ($instrumentCandidates as $candidateRow) {
            if (!is_array($candidateRow)) {
                continue;
            }
            $candidateLabel = trim((string)($candidateRow['label'] ?? ''));
            if ($candidateLabel !== '') {
                $instrumentLabels[] = $this->musicianInstrumentLabel($candidateLabel);
            }
        }
        $subjects[] = $this->subject(
            'phase:instrumentation',
            'instrumentation',
            'Instruments',
            $instrumentation,
            $instrumentLabels !== []
                ? implode(', ', array_slice($instrumentLabels, 0, 8))
                : 'Aucun instrument suffisamment confirmé'
        );

        $choirs = is_array($view['choirs'] ?? null)
            ? $view['choirs']
            : [];
        if ($choirs !== []) {
            $choirDecision = (string)($choirs['decision'] ?? 'inconclusive');
            $choirText = match ($choirDecision) {
                'detected' => 'Chœurs détectés',
                'possible' => 'Chœurs possibles',
                default => 'Pas de preuve suffisante',
            };
            $subjects[] = $this->subject(
                'phase:choirs',
                'choirs',
                'Chœurs',
                $choirs,
                $choirText
            );
        }

        $audioSet = $this->bestAudioSetConclusion($view['audioset']['agreements'] ?? []);
        if ($audioSet !== null) {
            $sourceLabel = trim((string)($audioSet['label'] ?? ''));
            $text = $sourceLabel;
            $decision = trim((string)($audioSet['decision'] ?? ''));
            if ($decision !== '') $text .= ' · '.$decision;
            if (isset($audioSet['confidence']) && is_numeric($audioSet['confidence'])) {
                $text .= ' · score '.number_format((float)$audioSet['confidence'] * 100, 1).' %';
            }
            $subjects[] = $this->subject(
                'phase:audioset',
                'audioset',
                'Élément sonore',
                $audioSet,
                $this->musicianAudioLabel($sourceLabel)
            );
        }

        return $subjects;
    }

    public function validationsForRun(int $runId): array
    {
        $this->ensureSchema();
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM profile_human_validations WHERE run_id=? ORDER BY subject_key'
        );
        $stmt->execute([$runId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['predicted'] = json_decode((string)$row['predicted_json'], true) ?: [];
            $row['missing_expected'] = json_decode((string)($row['missing_expected_json'] ?? '[]'), true) ?: [];
            $row['missing_expected_text'] = implode('; ', array_values(array_filter(array_map('strval', $row['missing_expected']))));
            $out[(string)$row['subject_key']] = $row;
        }
        return $out;
    }

    public function save(array $run, array $annotations): void
    {
        $this->ensureSchema();
        if ((string)($run['item'] ?? '') !== 'profile') {
            throw new \InvalidArgumentException('profile_run_required');
        }
        $runId = (int)($run['id'] ?? 0);
        if ($runId <= 0) {
            throw new \InvalidArgumentException('profile_run_id_required');
        }

        $allowed = [];
        foreach ($this->subjectsForRun($run) as $subject) {
            $allowed[(string)$subject['subject_key']] = $subject;
        }

        [$phaseRevision, $moduleRevisions] = $this->presetRefs($runId);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $now = gmdate('c');
            $upsert = $pdo->prepare(
                'INSERT INTO profile_human_validations(
                    run_id,subject_key,scope,item_key,module_key,
                    predicted_json,verdict,human_value,
                    missing_expected_json,reviewer_certainty,comment,
                    phase_revision_ref,module_revision_ref,
                    validation_schema,created_at,updated_at
                 ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON CONFLICT(run_id,subject_key) DO UPDATE SET
                    predicted_json=excluded.predicted_json,
                    verdict=excluded.verdict,
                    human_value=excluded.human_value,
                    missing_expected_json=excluded.missing_expected_json,
                    reviewer_certainty=excluded.reviewer_certainty,
                    comment=excluded.comment,
                    phase_revision_ref=excluded.phase_revision_ref,
                    module_revision_ref=excluded.module_revision_ref,
                    validation_schema=excluded.validation_schema,
                    updated_at=excluded.updated_at'
            );

            foreach ($annotations as $annotation) {
                if (!is_array($annotation)) continue;
                $subjectKey = trim((string)($annotation['subject'] ?? ''));
                $verdict = strtolower(trim((string)($annotation['verdict'] ?? '')));
                if ($subjectKey === '' || $verdict === '') continue;
                if (!isset($allowed[$subjectKey])) {
                    throw new \RuntimeException('profile_validation_subject_unknown:'.$subjectKey);
                }
                if (!in_array($verdict, self::VERDICTS, true)) {
                    throw new \RuntimeException('profile_validation_verdict_invalid');
                }

                $certainty = strtolower(trim((string)($annotation['reviewer_certainty'] ?? '')));
                if (!in_array($certainty, self::CERTAINTIES, true)) {
                    throw new \RuntimeException('profile_validation_certainty_invalid');
                }

                $subject = $allowed[$subjectKey];
                $moduleKey = $subject['module_key'];
                $moduleRevision = is_string($moduleKey) && isset($moduleRevisions[$moduleKey])
                    ? $moduleRevisions[$moduleKey]
                    : null;

                $humanValue = mb_substr(trim((string)($annotation['human_value'] ?? '')), 0, 500);
                $missingExpected = $this->normalizeExpectedList((string)($annotation['missing_expected'] ?? ''));
                $comment = mb_substr(trim((string)($annotation['comment'] ?? '')), 0, 2000);

                $upsert->execute([
                    $runId,
                    $subjectKey,
                    (string)$subject['scope'],
                    (string)$subject['item_key'],
                    $moduleKey,
                    json_encode($subject['predicted'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    $verdict,
                    $humanValue,
                    json_encode($missingExpected, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    $certainty,
                    $comment,
                    $phaseRevision,
                    $moduleRevision,
                    self::VALIDATION_SCHEMA,
                    $now,
                    $now,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function statsForRun(int $runId, array $subjectKeys = []): array
    {
        $this->ensureSchema();
        $subjectKeys = array_values(array_unique(array_filter(
            array_map(static fn(mixed $value): string => trim((string)$value), $subjectKeys),
            static fn(string $value): bool => $value !== ''
        )));

        $sql = "SELECT
                    COUNT(*) AS reviewed,
                    SUM(CASE WHEN verdict='ok' THEN 1 ELSE 0 END) AS ok_count,
                    SUM(CASE WHEN verdict='ko' THEN 1 ELSE 0 END) AS ko_count,
                    SUM(CASE WHEN verdict='unknown' THEN 1 ELSE 0 END) AS unknown_count
                FROM profile_human_validations
                WHERE run_id=?";
        $params = [$runId];
        if ($subjectKeys !== []) {
            $marks = implode(',', array_fill(0, count($subjectKeys), '?'));
            $sql .= " AND subject_key IN ($marks)";
            foreach ($subjectKeys as $subjectKey) $params[] = $subjectKey;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        $ok = (int)($row['ok_count'] ?? 0);
        $ko = (int)($row['ko_count'] ?? 0);
        $known = $ok + $ko;
        return [
            'reviewed' => (int)($row['reviewed'] ?? 0),
            'ok' => $ok,
            'ko' => $ko,
            'unknown' => (int)($row['unknown_count'] ?? 0),
            'known' => $known,
            'accuracy' => $known > 0 ? $ok / $known : null,
        ];
    }

    private function subject(
        string $subjectKey,
        string $itemKey,
        string $label,
        array $predicted,
        string $predictedText,
    ): array {
        [$question, $correctionHint, $humanValueLabel, $missingLabel] = match ($itemKey) {
            'tempo' => [
                'Le tempo vous paraît-il correct ?',
                'Ex. 120 BPM',
                'Votre correction',
                null,
            ],
            'time_signature' => [
                'La mesure proposée est-elle correcte ?',
                'Ex. 4/4',
                'Votre correction',
                null,
            ],
            'key' => [
                'La tonalité vous paraît-elle correcte ?',
                'Ex. Sol mineur',
                'Votre correction',
                null,
            ],
            'genre' => [
                'Ce genre décrit-il bien le morceau ?',
                'Ex. rock/pop',
                'Genre que vous choisiriez',
                'Genre important non proposé',
            ],
            'instrumentation' => [
                'Les instruments proposés correspondent-ils à ce que vous entendez ?',
                'Ex. piano; synthé; basse; batterie',
                'Instruments que vous entendez',
                'Instrument important non proposé',
            ],
            'choirs' => [
                'Entendez-vous des chœurs ou des voix d’accompagnement ?',
                'Ex. chœurs au refrain; voix doublée',
                'Ce que vous entendez',
                null,
            ],
            'voice' => [
                'La voix principale est-elle bien décrite ?',
                'Ex. chanteur principal; chœurs',
                'Ce que vous entendez',
                'Élément vocal important non proposé',
            ],
            'mood' => [
                'Cette ambiance correspond-elle au morceau ?',
                'Ex. mélancolique',
                'Ambiance que vous choisiriez',
                null,
            ],
            'audioset' => [
                'Cet élément sonore est-il réellement présent ?',
                'Ex. chant',
                'Votre correction',
                'Élément sonore important non proposé',
            ],
            default => [
                'Cette proposition vous paraît-elle correcte ?',
                'Votre correction',
                'Votre correction',
                null,
            ],
        };

        return [
            'subject_key' => $subjectKey,
            'scope' => 'phase',
            'item_key' => $itemKey,
            'module_key' => null,
            'label' => $label,
            'question' => $question,
            'correction_hint' => $correctionHint,
            'human_value_label' => $humanValueLabel,
            'missing_label' => $missingLabel,
            'predicted' => $predicted,
            'predicted_text' => $predictedText,
        ];
    }

    private function musicianInstrumentLabel(string $label): string
    {
        $key = mb_strtolower(trim($label));
        return match ($key) {
            'acoustic guitar' => 'guitare acoustique',
            'electric guitar' => 'guitare électrique',
            'guitar' => 'guitare',
            'bass guitar' => 'basse',
            'acoustic bass' => 'contrebasse',
            'piano' => 'piano',
            'electric piano' => 'piano électrique',
            'keyboard' => 'clavier',
            'organ' => 'orgue',
            'synthesizer' => 'synthé',
            'drum kit' => 'batterie',
            'snare drum' => 'caisse claire',
            'kick drum' => 'grosse caisse',
            'cymbal' => 'cymbales',
            'percussion' => 'percussions',
            'violin' => 'violon',
            'cello' => 'violoncelle',
            'string section' => 'cordes',
            'brass section' => 'cuivres',
            'trumpet' => 'trompette',
            'trombone' => 'trombone',
            'saxophone' => 'saxophone',
            'flute' => 'flûte',
            'clarinet' => 'clarinette',
            'harmonica' => 'harmonica',
            'accordion' => 'accordéon',
            'harp' => 'harpe',
            'mandolin' => 'mandoline',
            'ukulele' => 'ukulélé',
            default => $label,
        };
    }

    private function musicianAudioLabel(string $label): string
    {
        $key = mb_strtolower(trim($label));
        return match ($key) {
            'singing' => 'chant',
            'speech' => 'parole',
            'music' => 'musique',
            'guitar' => 'guitare',
            'piano' => 'piano',
            'drum' => 'batterie',
            'drums' => 'batterie',
            'bass guitar' => 'basse',
            default => $label,
        };
    }

    private function bestScoredRow(mixed $rows): ?array
    {
        if (!is_array($rows)) return null;
        $best = null;
        $bestScore = -INF;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') continue;
            $score = isset($row['score']) && is_numeric($row['score']) ? (float)$row['score'] : -INF;
            if ($best === null || $score > $bestScore) {
                $best = $row;
                $bestScore = $score;
            }
        }
        return $best;
    }

    private function bestAudioSetConclusion(mixed $rows): ?array
    {
        if (!is_array($rows)) return null;
        $priority = ['accepted' => 4, 'contextual' => 3, 'generic' => 2, 'uncertain' => 1];
        $best = null;
        $bestPriority = -1;
        $bestScore = -INF;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') continue;
            $decision = (string)($row['decision'] ?? '');
            $rank = $priority[$decision] ?? 0;
            $score = isset($row['confidence']) && is_numeric($row['confidence'])
                ? (float)$row['confidence']
                : (isset($row['support']) && is_numeric($row['support']) ? (float)$row['support'] : -INF);
            if ($best === null || $rank > $bestPriority || ($rank === $bestPriority && $score > $bestScore)) {
                $best = $row;
                $bestPriority = $rank;
                $bestScore = $score;
            }
        }
        return $best;
    }

    private function presetRefs(int $runId): array
    {
        $tables = $this->tableNames();
        if (!isset($tables['scientific_run_preset']) || !isset($tables['preset_phase_revisions'])) {
            return [null, []];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT rr.id,rr.revision_ref
             FROM scientific_run_preset sg
             JOIN preset_phase_revisions rr ON rr.id=sg.phase_revision_id
             WHERE sg.run_id=?'
        );
        $stmt->execute([$runId]);
        $phase = $stmt->fetch();
        if (!$phase) return [null, []];

        $modules = [];
        if (isset($tables['preset_phase_revision_modules']) && isset($tables['preset_module_revisions'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT g.module_key,g.revision_ref
                 FROM preset_phase_revision_modules rg
                 JOIN preset_module_revisions g ON g.id=rg.module_revision_id
                 WHERE rg.phase_revision_id=?'
            );
            $stmt->execute([(int)$phase['id']]);
            foreach ($stmt->fetchAll() as $row) {
                $modules[(string)$row['module_key']] = (string)$row['revision_ref'];
            }
        }
        return [(string)$phase['revision_ref'], $modules];
    }

    private function tableNames(): array
    {
        $rows = $this->db->pdo()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
        $out = [];
        foreach ($rows as $name) $out[(string)$name] = true;
        return $out;
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $stmt = $this->db->pdo()->query('PRAGMA table_info('.$table.')');
        $columns = [];
        foreach ($stmt->fetchAll() as $row) $columns[(string)$row['name']] = true;
        if (!isset($columns[$column])) {
            $this->db->pdo()->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);
        }
    }

    private function normalizeExpectedList(string $value): array
    {
        $parts = preg_split('/[;\r\n]+/u', $value) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $part = mb_substr($part, 0, 200);
            if (!in_array($part, $out, true)) $out[] = $part;
        }
        return array_slice($out, 0, 20);
    }

    private function compactJson(array $value): string
    {
        $text = json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($text)) return '—';
        return mb_strlen($text) > 500 ? mb_substr($text, 0, 497).'...' : $text;
    }
}
