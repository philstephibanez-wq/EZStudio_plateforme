<?php
declare(strict_types=1);

namespace App\Service;

final class SharedGpuTelemetryService
{
    public function snapshot(): array
    {
        $root = dirname(__DIR__, 2);
        $cacheFile = $root . '/var/gpu_telemetry_shared.json';
        $nowMs = (int) round(microtime(true) * 1000);
        $cached = is_file($cacheFile) ? json_decode((string) @file_get_contents($cacheFile), true) : null;

        if (is_array($cached) && ($cached['available'] ?? false) === true && ($nowMs - (int) ($cached['timestamp_ms'] ?? 0)) < 1200) {
            return $cached;
        }

        $gpu = $this->queryNvidiaSmi();
        if ($gpu === null) {
            return ['available' => false, 'error' => 'nvidia_smi_unavailable', 'timestamp_ms' => $nowMs];
        }

        $history = is_array($cached['history'] ?? null) ? $cached['history'] : [];
        $history[] = [
            't' => $nowMs,
            'util' => $gpu['utilization_percent'],
            'memory' => $gpu['memory_percent'],
            'memoryUsed' => $gpu['memory_used_mib'],
            'memoryTotal' => $gpu['memory_total_mib'],
            'power' => $gpu['power_w'],
            'powerLimit' => $gpu['power_limit_w'],
            'temp' => $gpu['temperature_c'],
            'clock' => $gpu['sm_clock_mhz'],
            'pstate' => $gpu['pstate'],
            'name' => $gpu['name'],
        ];
        $history = array_slice($history, -60);

        $payload = ['available' => true, 'timestamp_ms' => $nowMs, 'gpu' => $gpu, 'history' => $history];
        if (!is_dir(dirname($cacheFile))) { @mkdir(dirname($cacheFile), 0775, true); }
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE)) !== false) { @rename($tmp, $cacheFile); }
        return $payload;
    }

    private function queryNvidiaSmi(): ?array
    {
        $bin = getenv('EZSTUDIO_NVIDIA_SMI') ?: (is_file('C:/Windows/System32/nvidia-smi.exe') ? 'C:/Windows/System32/nvidia-smi.exe' : 'nvidia-smi');
        $cmd = [$bin, '--query-gpu=name,utilization.gpu,memory.used,memory.total,temperature.gpu,power.draw,power.limit,clocks.sm,pstate', '--format=csv,noheader,nounits'];
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) { return null; }
        fclose($pipes[0]);
        $out = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($proc) !== 0 || $out === '') { return null; }
        $f = array_map('trim', str_getcsv(preg_split('/\R/', $out)[0] ?? ''));
        if (count($f) < 9) { return null; }
        $mu = is_numeric($f[2]) ? (float) $f[2] : 0.0; $mt = is_numeric($f[3]) ? (float) $f[3] : 0.0;
        $pw = is_numeric($f[5]) ? (float) $f[5] : 0.0; $pl = is_numeric($f[6]) ? (float) $f[6] : 0.0;
        return [
            'name' => $f[0], 'utilization_percent' => is_numeric($f[1]) ? (float) $f[1] : null,
            'memory_used_mib' => $mu, 'memory_total_mib' => $mt, 'memory_percent' => $mt > 0 ? round(($mu / $mt) * 100, 2) : 0.0,
            'temperature_c' => is_numeric($f[4]) ? (float) $f[4] : null, 'power_w' => $pw, 'power_limit_w' => $pl,
            'power_percent' => $pl > 0 ? round(($pw / $pl) * 100, 2) : 0.0,
            'sm_clock_mhz' => is_numeric($f[7]) ? (float) $f[7] : null, 'pstate' => $f[8],
        ];
    }
}