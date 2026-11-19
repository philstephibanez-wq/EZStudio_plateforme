<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

use Symfony\Component\Process\Process;

/**
 * Native command boundary. PHP-CGI is deliberately not an authorized executable.
 * The remaining allowlist is preserved for existing consumers.
 */
final class NativeCommandRunner
{
    private const ALLOWED = [
        'git.exe' => true,
        'caddy.exe' => true,
        'php.exe' => true,
        'nvidia-smi.exe' => true,
    ];

    /** @param list<string> $arguments
     *  @return array{exit_code:int,stdout:string,stderr:string}
     */
    public function run(string $executable, array $arguments, string $cwd, float $timeout = 15.0): array
    {
        if ($executable === '' || !is_dir($cwd) || $timeout <= 0 || $timeout > 300) {
            throw new \InvalidArgumentException('NATIVE_COMMAND_INVALID');
        }
        $name = strtolower(basename(str_replace('\\', '/', $executable)));
        if (!isset(self::ALLOWED[$name]) || strpbrk($executable, "\r\n\0") !== false) {
            throw new \InvalidArgumentException('NATIVE_COMMAND_NOT_ALLOWED');
        }
        foreach ($arguments as $arg) {
            if (!is_string($arg) || strpbrk($arg, "\r\n\0") !== false) {
                throw new \InvalidArgumentException('NATIVE_ARGUMENT_INVALID');
            }
        }
        $process = new Process(array_merge([$executable], $arguments), $cwd);
        $process->setTimeout($timeout);
        $process->run();
        return [
            'exit_code' => $process->getExitCode() ?? -1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }
}
