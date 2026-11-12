<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

/**
 * Registre d'observation uniquement. Un enregistrement ne confère aucun droit
 * de terminaison sur un processus Windows.
 */
final class EcosystemProcessRegistry
{
    private const SCHEMA = 'ezs.process-registry.v1';

    public function __construct(private readonly string $file)
    {
        if (basename($file) === '' || is_link($file)) {
            throw new \InvalidArgumentException('EZS_REGISTRY_INVALID_PATH');
        }
    }

    /** @return list<array<string,mixed>> */
    public function read(): array
    {
        return $this->withLock(false, fn(): array => $this->readUnlocked());
    }

    /**
     * Écrit un relevé déjà attesté par l'appelant ; ne découvre aucun processus.
     * Refuse PID nuls, doublons et identités incomplètes.
     * @param list<array<string,mixed>> $records
     */
    public function replace(array $records): void
    {
        $checked = self::validate($records);
        $this->withLock(true, function () use ($checked): void {
            $dir = dirname($this->file);
            $temp = tempnam($dir, '.ezs-registry-');
            if ($temp === false) throw new \RuntimeException('EZS_REGISTRY_TEMP_FAILED');
            try {
                $payload = json_encode(['schema'=>self::SCHEMA, 'records'=>$checked], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if (file_put_contents($temp, $payload) === false) throw new \RuntimeException('EZS_REGISTRY_WRITE_FAILED');
                if (!rename($temp, $this->file)) throw new \RuntimeException('EZS_REGISTRY_RENAME_FAILED');
            } finally {
                if (is_file($temp)) @unlink($temp);
            }
        });
    }

    /** @param list<array<string,mixed>> $observed
     * @return array{matched:array,missing:array,changed:array,untracked:array,ambiguous:array}
     */
    public function compare(array $observed): array
    {
        return EcosystemProcessEvidence::reconcile($this->read(), $observed);
    }

    /** @return list<array<string,mixed>> */
    private function readUnlocked(): array
    {
        if (!file_exists($this->file)) return [];
        if (is_link($this->file) || !is_file($this->file)) throw new \RuntimeException('EZS_REGISTRY_NOT_REGULAR_FILE');
        $raw = file_get_contents($this->file);
        if ($raw === false) throw new \RuntimeException('EZS_REGISTRY_READ_FAILED');
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('EZS_REGISTRY_INVALID_JSON', 0, $e);
        }
        if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA || !isset($data['records']) || !is_array($data['records']) || !array_is_list($data['records'])) {
            throw new \RuntimeException('EZS_REGISTRY_INVALID_SCHEMA');
        }
        return self::validate($data['records']);
    }

    /** @param array<mixed> $records @return list<array<string,mixed>> */
    private static function validate(array $records): array
    {
        if (!array_is_list($records)) throw new \InvalidArgumentException('EZS_REGISTRY_NOT_A_LIST');
        $seen = [];
        foreach ($records as $record) {
            if (!is_array($record) || !isset($record['pid']) || !is_int($record['pid']) || $record['pid'] <= 0 || isset($seen[$record['pid']])) {
                throw new \InvalidArgumentException('EZS_REGISTRY_INVALID_PID');
            }
            $seen[$record['pid']] = true;
            foreach (['created','executable','command'] as $key) {
                if (!isset($record[$key]) || !is_string($record[$key]) || trim($record[$key]) === '') {
                    throw new \InvalidArgumentException('EZS_REGISTRY_INCOMPLETE_IDENTITY');
                }
            }
        }
        return $records;
    }

    private function withLock(bool $write, callable $callback): mixed
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) throw new \RuntimeException('EZS_REGISTRY_PARENT_MISSING');
        $lock = fopen($this->file . '.lock', 'c+b');
        if ($lock === false) throw new \RuntimeException('EZS_REGISTRY_LOCK_UNAVAILABLE');
        try {
            if (!flock($lock, $write ? LOCK_EX : LOCK_SH)) throw new \RuntimeException('EZS_REGISTRY_LOCK_FAILED');
            try { return $callback(); }
            finally { flock($lock, LOCK_UN); }
        } finally { fclose($lock); }
    }
}
