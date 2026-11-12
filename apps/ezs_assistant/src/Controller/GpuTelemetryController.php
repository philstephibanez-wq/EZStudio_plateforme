<?php

namespace EZSAssistant\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class GpuTelemetryController
{
    #[Route('/assistant/gpu/telemetry', name: 'assistant_gpu_telemetry', methods: ['GET'])]
    public function telemetry(): JsonResponse
    {
        $binary = getenv('EZSTUDIO_NVIDIA_SMI') ?: 'nvidia-smi';
        $query = implode(',', [
            'name',
            'utilization.gpu',
            'memory.used',
            'memory.total',
            'temperature.gpu',
            'power.draw',
            'power.limit',
            'clocks.sm',
            'pstate',
        ]);

        $command = [
            $binary,
            '--query-gpu='.$query,
            '--format=csv,noheader,nounits',
        ];

        $pipes = [];
        $process = @proc_open(
            $command,
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']],
            $pipes
        );

        if (!is_resource($process)) {
            return new JsonResponse([
                'available' => false,
                'error' => 'nvidia_smi_start_failed',
                'timestamp_ms' => (int) round(microtime(true) * 1000),
            ], 503);
        }

        fclose($pipes[0]);
        $stdout = trim((string) stream_get_contents($pipes[1]));
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || $stdout === '') {
            return new JsonResponse([
                'available' => false,
                'error' => 'nvidia_smi_failed',
                'detail' => $stderr !== '' ? mb_substr($stderr, 0, 500) : null,
                'timestamp_ms' => (int) round(microtime(true) * 1000),
            ], 503);
        }

        $line = preg_split('/\R/', $stdout)[0] ?? '';
        $fields = array_map('trim', str_getcsv($line));

        if (count($fields) < 9) {
            return new JsonResponse([
                'available' => false,
                'error' => 'nvidia_smi_unexpected_output',
                'timestamp_ms' => (int) round(microtime(true) * 1000),
            ], 503);
        }

        [$name,$gpu,$memUsed,$memTotal,$temp,$power,$powerLimit,$clockSm,$pstate] = $fields;

        $memUsedF = is_numeric($memUsed) ? (float) $memUsed : 0.0;
        $memTotalF = is_numeric($memTotal) ? (float) $memTotal : 0.0;
        $powerF = is_numeric($power) ? (float) $power : 0.0;
        $powerLimitF = is_numeric($powerLimit) ? (float) $powerLimit : 0.0;

        return new JsonResponse([
            'available' => true,
            'timestamp_ms' => (int) round(microtime(true) * 1000),
            'gpu' => [
                'name' => $name,
                'utilization_percent' => is_numeric($gpu) ? (float) $gpu : null,
                'memory_used_mib' => $memUsedF,
                'memory_total_mib' => $memTotalF,
                'memory_percent' => $memTotalF > 0.0 ? round(($memUsedF / $memTotalF) * 100.0, 2) : 0.0,
                'temperature_c' => is_numeric($temp) ? (float) $temp : null,
                'power_w' => $powerF,
                'power_limit_w' => $powerLimitF,
                'power_percent' => $powerLimitF > 0.0 ? round(($powerF / $powerLimitF) * 100.0, 2) : 0.0,
                'sm_clock_mhz' => is_numeric($clockSm) ? (float) $clockSm : null,
                'pstate' => $pstate,
            ],
        ]);
    }
}
