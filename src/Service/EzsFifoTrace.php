<?php

declare(strict_types=1);
namespace App\Service;

/** Diagnostic temporaire des jobs. Champs autorisés uniquement ; aucune charge utile / aucun secret. */
final class EzsFifoTrace
{
    public static function log(string $event, array $fields = []): void
    {
        $allowed = ['run_id','local_job_id','global_job_id','attempt','source','action','state','phase',
            'http_status','ack_status','ack_queue_id','result','reason','exception','remote_error','created','exit_code','marker','stage'];
        $record = ['time' => gmdate('c'), 'event' => preg_replace('/[^A-Z0-9_]/', '', $event), 'pid' => getmypid()];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields) || $fields[$key] === null) continue;
            $value = $fields[$key];
            if (is_int($value) || is_bool($value)) { $record[$key] = $value; continue; }
            if (!is_string($value)) continue;
            // Refuser tout message contenant chemins, espaces, URL, jetons ou données libres.
            if (preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D', $value)) $record[$key] = $value;
            else $record[$key] = 'masked';
        }
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return;
        // Destination hors dépôt ; PHP serveur et CLI peuvent avoir des TEMP différents.
        $dir = is_dir('H:/temp') && is_writable('H:/temp') ? 'H:/temp/EZS_FIFO_TRACE_R1' : sys_get_temp_dir();
        if ($dir === 'H:/temp/EZS_FIFO_TRACE_R1' && !is_dir($dir)) @mkdir($dir, 0770, true);
        $file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . ($dir === sys_get_temp_dir() ? 'ezs_fifo_trace_r1.jsonl' : 'events.jsonl');
        @file_put_contents($file, $json . "\n", FILE_APPEND | LOCK_EX);
        error_log('[EZS_FIFO_TRACE_R1] ' . $json);
    }
}
