<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Gpu;


final class GpuProbe
{
    public function __construct(private readonly GpuTelemetryParser $parser) {}

    public function read(): array
    {
        $binary = self::binary();
        // Lancement direct : sous Windows, bypass_shell supprime cmd.exe.
        $command = [$binary, '--query-gpu=name,utilization.gpu,memory.used,memory.total,temperature.gpu,power.draw,power.limit,clocks.sm,pstate', '--format=csv,noheader,nounits'];
        $pipes = [];
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true, 'create_new_console' => false] : []
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('gpu_probe_execution_failed');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 10;
        $timedOut = false;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $state = proc_get_status($process);
            if (!$state['running']) {
                $code = $state['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(20000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        if ($timedOut || ($code ?? -1) !== 0 && $closed !== 0) {
            throw new \RuntimeException('gpu_probe_execution_failed');
        }
        return [
            'node' => 'MAIN',
            'timestamp_utc' => gmdate('c'),
            'gpu' => $this->parser->parse($stdout),
        ];
    }
    public static function binary(): string
    {
        $configured = getenv('EZS_NVIDIA_SMI');
        if (is_string($configured) && trim($configured) !== '') {
            if (!is_file($configured)) {
                throw new \RuntimeException('gpu_probe_binary_missing');
            }
            return $configured;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $windows = getenv('WINDIR') ?: getenv('SystemRoot');
            if ($windows) {
                $candidate = rtrim($windows, '\\/') . '/System32/nvidia-smi.exe';
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
            $programFiles = getenv('ProgramFiles');
            if ($programFiles) {
                $candidate = rtrim($programFiles, '\\/') . '/NVIDIA Corporation/NVSMI/nvidia-smi.exe';
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }
        return 'nvidia-smi';
    }
}
