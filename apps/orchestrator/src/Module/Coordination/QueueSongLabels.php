<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

use Symfony\Component\Process\Process;

/** Reads LAB-owned labels through its public Symfony CLI, not through its database. */
final class QueueSongLabels
{
    /** @return array<int,string> */
    public static function parse(string $json): array
    {
        if (strlen($json) > 32768) {
            throw new \InvalidArgumentException('queue_labels_payload_too_large');
        }
        $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 'ezstudio.lab.queue-labels.v1'
            || !is_array($data['labels'] ?? null)) {
            throw new \InvalidArgumentException('queue_labels_contract_invalid');
        }
        $labels = [];
        foreach ($data['labels'] as $jobId => $name) {
            if (!ctype_digit((string) $jobId) || (int) $jobId < 1
                || !is_string($name) || mb_strlen($name) > 300) {
                continue;
            }
            $labels[(int) $jobId] = $name;
        }
        return $labels;
    }

    /** @return array<int,string> */
    public static function read(): array
    {
        $lab = 'H:/EZStudio_lab';
        if (!is_file($lab.'/bin/console')) return [];
        try {
            $process = new Process([PHP_BINARY, 'bin/console', 'lab:queue:song-labels', '--no-interaction'], $lab);
            $process->setTimeout(5);
            $process->run();
            if (!$process->isSuccessful()) return [];
            return self::parse(trim($process->getOutput()));
        } catch (\Throwable) {
            // A title lookup failure cannot block FIFO observability or execution.
            return [];
        }
    }
}
