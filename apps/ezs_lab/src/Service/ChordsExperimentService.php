<?php

namespace EZSLab\Service;

final class ChordsExperimentService
{
    private const ALLOWED_ROLES = [
        'lead_vocals',
        'backing_vocals',
        'drums',
        'bass',
        'guitar',
        'piano',
        'other',
    ];

    public function __construct(
        private readonly AnalysisRunRegistry $runRegistry,
        private readonly string $projectDir = __DIR__.'/../..',
    ) {}

    public function stemsRuns(int $songId): array
    {
        $runs = $this->runRegistry->runsForSongItem($songId, 'stems');
        $out = [];

        foreach ($runs as $run) {
            if ((string)$run['state'] !== 'done') {
                continue;
            }

            $artifacts = array_values(array_filter(
                $this->runRegistry->artifactsForRun((int)$run['id']),
                static fn(array $a): bool =>
                    in_array((string)$a['role'], self::ALLOWED_ROLES, true)
            ));

            if ($artifacts === []) {
                continue;
            }

            $run['artifacts'] = $artifacts;
            $out[] = $run;
        }

        return $out;
    }

    public function createConfiguredRun(
        int $songId,
        int $parentStemsRunId,
        array $chordArtifactIds,
        array $noChordArtifactIds,
        string $engineName,
        string $signature,
    ): array {
        $parent = $this->runRegistry->run($parentStemsRunId);
        if (!$parent
            || (int)$parent['song_id'] !== $songId
            || (string)$parent['item'] !== 'stems'
            || (string)$parent['state'] !== 'done'
        ) {
            throw new \InvalidArgumentException('invalid_parent_stems_run');
        }

        $available = [];
        foreach ($this->runRegistry->artifactsForRun($parentStemsRunId) as $artifact) {
            $role = (string)$artifact['role'];
            if (in_array($role, self::ALLOWED_ROLES, true)) {
                $available[(string)$artifact['artifact_id']] = $artifact;
            }
        }

        $chordIds = $this->normalizeSelection($chordArtifactIds, $available);
        $noChordIds = $this->normalizeSelection($noChordArtifactIds, $available);

        if ($chordIds === []) {
            throw new \InvalidArgumentException('chord_inputs_required');
        }
        if ($noChordIds === []) {
            throw new \InvalidArgumentException('no_chord_evidence_required');
        }

        $engineName = trim($engineName) ?: 'lv-chordia';
        $signature = trim($signature) ?: 'Auto';

        $config = [
            'parent_stems_run_id' => $parentStemsRunId,
            'signature' => $signature,
            'engine' => $engineName,
            'chord_input_artifact_ids' => $chordIds,
            'no_chord_evidence_artifact_ids' => $noChordIds,
            'excluded_harmonic_artifact_ids' => array_values(array_diff(
                array_keys($available),
                $noChordIds
            )),
            'internal_no_chord_token' => 'N',
            'metric_methods_contract' => [
                'Beat This downbeat',
                'Percussive onset',
                'Bass CQT',
                'Rhythm + Bass',
                'Harmonic novelty',
                'R41-like fusion',
            ],
            'execution_contract' => 'ezstudio.chords.selection.v1',
        ];

        $run = $this->runRegistry->createRun(
            $songId,
            'chords',
            ['name' => $engineName],
            $config,
        );

        $runId = (int)$run['id'];
        $this->runRegistry->addParent($runId, $parentStemsRunId, 'stems_source');

        foreach ($chordIds as $artifactId) {
            $this->runRegistry->selectInput($runId, $artifactId, 'chord_input');
        }
        foreach ($noChordIds as $artifactId) {
            $this->runRegistry->selectInput($runId, $artifactId, 'no_chord_evidence');
        }

        $this->runRegistry->setLabel($runId, 'selection_version', '1');
        $this->runRegistry->setLabel($runId, 'execution_contract', 'ezstudio.chords.selection.v1');
        $this->runRegistry->setState($runId, 'configured');

        $requestPath = $this->writeExecutionRequest(
            $this->runRegistry->run($runId),
            $available
        );
        $this->runRegistry->setLabel($runId, 'execution_request', $requestPath);

        return $this->runRegistry->run($runId);
    }

    private function normalizeSelection(array $ids, array $available): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = trim((string)$id);
            if ($id !== '' && isset($available[$id])) {
                $out[$id] = true;
            }
        }
        return array_keys($out);
    }

    private function writeExecutionRequest(array $run, array $available): string
    {
        $runId = (int)$run['id'];
        $root = 'H:\temp\EZStudio_lab\chords'
            .DIRECTORY_SEPARATOR.sprintf('scientific-run-%06d', $runId);

        if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) {
            throw new \RuntimeException('chords_request_dir_create_failed');
        }

        $selected = [];
        foreach ($run['inputs'] as $input) {
            $selected[] = [
                'input_role' => (string)$input['input_role'],
                'artifact_id' => (string)$input['artifact_id'],
                'stem_role' => (string)$input['role'],
                'path' => (string)$input['path'],
                'sha256' => (string)$input['sha256'],
            ];
        }

        $payload = [
            'schema' => 'ezstudio.chords.selection.v1',
            'scientific_run_id' => $runId,
            'public_id' => (string)$run['public_id'],
            'song_id' => (int)$run['song_id'],
            'config' => $run['config'],
            'inputs' => $selected,
            'created_at' => gmdate('c'),
        ];

        $path = $root.DIRECTORY_SEPARATOR.'request.json';
        $tmp = $path.'.tmp.'.getmypid();
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
        )."\n";

        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('chords_request_write_failed');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('chords_request_publish_failed');
        }

        return $path;
    }
}
