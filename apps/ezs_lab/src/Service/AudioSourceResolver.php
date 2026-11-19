<?php

declare(strict_types=1);

namespace EZSLab\Service;

/** Physical MP3 location belongs to configuration, not the songs table. */
final class AudioSourceResolver
{
    public function root(): string
    {
        $configured = trim((string) ($_ENV['EZSTUDIO_IMPORTS_ROOT'] ?? $_SERVER['EZSTUDIO_IMPORTS_ROOT'] ?? getenv('EZSTUDIO_IMPORTS_ROOT') ?: ''));
        $root = $configured !== '' ? $configured : dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'imports';
        if (!is_dir($root)) {
            throw new \RuntimeException('audio_import_root_missing');
        }
        return rtrim($root, '\\/');
    }

    public function findBySha256(string $digest): ?string
    {
        $digest = strtolower(trim($digest));
        if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new \InvalidArgumentException('audio_sha256_invalid');
        }
        $files = glob($this->root().DIRECTORY_SEPARATOR.$digest.'.*') ?: [];
        foreach ($files as $candidate) {
            if (!is_file($candidate)) continue;
            $ext = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
            if (!in_array($ext, ['mp3','wav','flac','ogg','oga','m4a','mp4','aac'], true)) continue;
            $actual = hash_file('sha256', $candidate);
            if ($actual !== false && hash_equals($digest, strtolower($actual))) return $candidate;
        }
        return null;
    }
}
