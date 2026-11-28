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

    /** @param list<int> $ids @return array<int,string> */
    public static function readSongs(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn($v)=>is_int($v)&&$v>0)));
        if (!$ids || count($ids)>100) return [];
        $root = dirname(__DIR__, 5);
        $started = microtime(true);
        $php = PHP_OS_FAMILY === 'Windows' ? 'H:/FRANKENPHP/bin/php.exe' : PHP_BINARY;
        if (!is_file($php) || !is_file($root.'/bin/console')) {
            self::songLabelTrace('cli_or_console_missing', count($ids), $started);
            return [];
        }
        try {
            $process = new Process([$php, 'bin/console', 'lab:queue:song-labels', implode(',', $ids), '--no-interaction', '--no-ansi'], $root, ['APP_ID'=>'ezs_lab']);
            $process->setTimeout(10);
            $process->run();
            if (!$process->isSuccessful()) {
                self::songLabelTrace('cli_failed', count($ids), $started, $process->getExitCode());
                return [];
            }
            if (strlen($process->getOutput())>130000) {
                self::songLabelTrace('output_too_large', count($ids), $started);
                return [];
            }
            $data=json_decode(trim($process->getOutput()),true,8,JSON_THROW_ON_ERROR);
            if (($data['schema']??null)!=='ezstudio.lab.song-labels.v2' || !is_array($data['labels']??null)) {
                self::songLabelTrace('contract_invalid', count($ids), $started);
                return [];
            }
            $out=[];
            foreach ($ids as $id) {
                $name=$data['labels'][$id]??null;
                if (is_string($name) && trim($name)!=='' && mb_strlen($name)<=300) $out[$id]=$name;
            }
            if (count($out)!==count($ids)) self::songLabelTrace('labels_partial', count($ids), $started, null, count($out));
            return $out;
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
            self::songLabelTrace('cli_timeout', count($ids), $started);
        } catch (\JsonException) {
            self::songLabelTrace('json_invalid', count($ids), $started);
        } catch (\Throwable) {
            self::songLabelTrace('resolver_exception', count($ids), $started);
        }
        return [];
    }

    /** Fixed diagnostic fields only; no payload, process output, paths or secrets. */
    private static function songLabelTrace(string $reason, int $requested, float $started, ?int $exitCode=null, ?int $resolved=null): void
    {
        if (PHP_OS_FAMILY !== 'Windows') return;
        $dir = 'H:/temp/EZS_FIFO_UI_R1B';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) return;
        $record = ['time'=>gmdate('c'),'reason'=>$reason,'requested'=>$requested,'resolved'=>$resolved,'exit_code'=>$exitCode,'elapsed_ms'=>(int)round((microtime(true)-$started)*1000)];
        $json = json_encode($record);
        if (is_string($json)) @file_put_contents($dir.'/song-labels.jsonl', $json."\n", FILE_APPEND|LOCK_EX);
    }

    /** @return array<int,string> */
    public static function read(): array
    {
        $lab = dirname(__DIR__, 4) . '/apps/ezs_lab';
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
