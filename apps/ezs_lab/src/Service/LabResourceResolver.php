<?php

declare(strict_types=1);

namespace EZSLab\Service;

/** Resolve deployment-local file locations from immutable business identities.
 * No result of this class may be persisted in an application database.
 */
final class LabResourceResolver
{
    public function __construct(private readonly AudioSourceResolver $audioSources) {}

    public function audio(string $sha256): string
    {
        $path = $this->audioSources->findBySha256($sha256);
        if ($path === null) {
            throw new \RuntimeException('lab_audio_identity_not_resolvable');
        }
        return $path;
    }

    public function storageRoot(): string
    {
        $raw = trim((string) ($_ENV['EZSTUDIO_STORAGE_ROOT'] ?? $_SERVER['EZSTUDIO_STORAGE_ROOT'] ?? getenv('EZSTUDIO_STORAGE_ROOT') ?: ''));
        return rtrim($raw !== '' ? $raw : dirname(__DIR__, 2).'/var/storage', '\\/');
    }

    public function profileOutput(int $scientificRunId, string $audioSha256): string
    {
        $this->assertHash($audioSha256);
        if ($scientificRunId < 1) throw new \InvalidArgumentException('scientific_run_id_invalid');
        return $this->storageRoot().DIRECTORY_SEPARATOR.'profile'.DIRECTORY_SEPARATOR.strtolower($audioSha256)
            .DIRECTORY_SEPARATOR.sprintf('run-%06d', $scientificRunId).DIRECTORY_SEPARATOR.'profile.json';
    }

    public function stemsStorage(string $audioSha256): string
    {
        $this->assertHash($audioSha256);
        return $this->storageRoot().DIRECTORY_SEPARATOR.'stems'.DIRECTORY_SEPARATOR.strtolower($audioSha256);
    }

    public function chordsRequest(int $scientificRunId): string
    {
        if ($scientificRunId < 1) throw new \InvalidArgumentException('scientific_run_id_invalid');
        $root = trim((string) ($_ENV['EZSTUDIO_CHORDS_REQUESTS_ROOT'] ?? $_SERVER['EZSTUDIO_CHORDS_REQUESTS_ROOT'] ?? getenv('EZSTUDIO_CHORDS_REQUESTS_ROOT') ?: ''));
        if ($root === '') $root = dirname(__DIR__, 2).'/var/tmp/chords';
        return rtrim($root, '\\/').DIRECTORY_SEPARATOR.sprintf('scientific-run-%06d', $scientificRunId).DIRECTORY_SEPARATOR.'request.json';
    }

    public function artifact(array $row): string
    {
        $role = (string) ($row['role'] ?? '');
        $run = (int) ($row['run_id'] ?? 0);
        $sha = strtolower((string) ($row['sha256'] ?? ''));
        $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
        if (!is_array($metadata)) $metadata = [];
        $candidate = null;
        if ($role === 'profile_json') {
            $candidate = $this->profileOutput($run, (string) ($metadata['audio_sha256'] ?? ''));
        } elseif (in_array($role, ['lead_vocals', 'backing_vocals', 'drums', 'bass', 'guitar', 'piano', 'other'], true)) {
            $audio = (string) ($row['audio_sha256'] ?? '');
            $runName = (string) ($metadata['source_stems_run'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9._-]+$/D', $runName) || $runName === '.' || $runName === '..') {
                throw new \RuntimeException('stem_run_identity_invalid');
            }
            $candidate = $this->stemsStorage($audio).DIRECTORY_SEPARATOR.'runs'.DIRECTORY_SEPARATOR.$runName.DIRECTORY_SEPARATOR.$role.'.wav';
        }
        if ($candidate === null || !is_file($candidate)) throw new \RuntimeException('artifact_resource_not_resolvable');
        $this->assertHash($sha);
        $actual = hash_file('sha256', $candidate);
        if (!is_string($actual) || !hash_equals($sha, strtolower($actual))) {
            throw new \RuntimeException('artifact_sha256_mismatch');
        }
        return $candidate;
    }

    private function assertHash(string $hash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/Di', $hash)) {
            throw new \InvalidArgumentException('resource_sha256_invalid');
        }
    }
}
