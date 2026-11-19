<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Gpu;

final class GpuTelemetryParser
{
    public function parse(string $output): array
    {
        $line = trim(explode("\n", trim($output))[0] ?? '');
        $fields = str_getcsv($line, ',', '"', '');
        if (!in_array(count($fields), [5, 9], true)) {
            throw new \UnexpectedValueException('gpu_invalid_field_count');
        }
        [$name, $usage, $used, $total, $temperature] = array_map('trim', $fields);
        if ($name === '' || !is_numeric($usage) || !is_numeric($used) || !is_numeric($total) || !is_numeric($temperature) || (float)$total <= 0) {
            throw new \UnexpectedValueException('gpu_invalid_measurements');
        }
        $optional = array_map('trim', array_slice($fields, 5));
        $power = $optional[0] ?? null;
        $limit = $optional[1] ?? null;
        $clock = $optional[2] ?? null;
        $pstate = $optional[3] ?? null;
        return [
            'name' => $name,
            'utilization_percent' => (float)$usage,
            'memory_used_mib' => (float)$used,
            'memory_total_mib' => (float)$total,
            'temperature_c' => (float)$temperature,
            'memory_percent' => round((float)$used / (float)$total * 100, 2),
            'power_w' => is_numeric($power) ? (float)$power : null,
            'power_limit_w' => is_numeric($limit) ? (float)$limit : null,
            'sm_clock_mhz' => is_numeric($clock) ? (float)$clock : null,
            'pstate' => $pstate,
        ];
    }
}
