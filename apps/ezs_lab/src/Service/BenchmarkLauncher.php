<?php

namespace EZSLab\Service;

final class BenchmarkLauncher
{
    public function __construct(
        private readonly Database $database,
        private readonly string $projectDir = __DIR__.'/../..',
        private readonly string $pythonExecutable = 'H:\Python\pythoncore-3.14-64\python.exe',
        private readonly string $dependencyRoot = 'H:\temp\EZStudio_lab\deps',
        private readonly bool $keepUploads = true,
    ) {}

    public function launch(int $runId, string $audioPath, string $signature): void
    {
        // R2: the permanent EZS_orchestrator service is the only worker.
        // EZStudio_lab only persists a target-owned queued job here.
        $this->database->queueAnalysisJob($runId, $audioPath, $signature);
    }

    /**
     * @param list<string> $workerArgs
     */
    private function launchDetachedWindows(int $runId, string $worker, array $workerArgs): void
    {
        $projectDir = realpath($this->projectDir) ?: $this->projectDir;
        $startScript = $projectDir.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'start-benchmark-worker.ps1';

        if (!is_file($startScript)) {
            throw new \RuntimeException('Launcher PowerShell introuvable: '.$startScript);
        }

        $logDir = dirname($this->dependencyRoot).DIRECTORY_SEPARATOR.'launcher';
        if (!is_dir($logDir) && !mkdir($logDir, 0777, true) && !is_dir($logDir)) {
            throw new \RuntimeException('Impossible de créer le dossier launcher: '.$logDir);
        }

        $stdout = $logDir.DIRECTORY_SEPARATOR.'run-'.$runId.'.stdout.log';
        $stderr = $logDir.DIRECTORY_SEPARATOR.'run-'.$runId.'.stderr.log';

        $powershell = $this->findPowerShell();

        $command = [
            $powershell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy', 'Bypass',
            '-File', $startScript,
            '-Python', $this->pythonExecutable,
            '-Worker', $worker,
            '-Database', $this->database->path(),
            '-RunId', (string)$runId,
            '-Audio', $workerArgs[5],
            '-Signature', $workerArgs[7],
            '-Deps', $this->dependencyRoot,
            '-KeepUpload', $this->keepUploads ? '1' : '0',
            '-Stdout', $stdout,
            '-Stderr', $stderr,
        ];

        $nullIn = fopen('NUL', 'r');
        $nullOut = fopen('NUL', 'a');
        if ($nullIn === false || $nullOut === false) {
            throw new \RuntimeException('Impossible d’ouvrir NUL.');
        }

        try {
            /*
             * Critical Windows detachment rule:
             * do NOT attach PHP pipes to the bootstrap.
             *
             * A pipe can remain open through the process tree and make
             * stream_get_contents()/proc_close() wait for the Python worker.
             * The bootstrap has its own worker stdout/stderr files already.
             */
            $process = proc_open(
                $command,
                [
                    0 => $nullIn,
                    1 => $nullOut,
                    2 => $nullOut,
                ],
                $pipes,
                $projectDir,
                null,
                ['bypass_shell' => true],
            );

            if (!is_resource($process)) {
                throw new \RuntimeException('Impossible de lancer le bootstrap PowerShell du worker.');
            }

            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                throw new \RuntimeException(
                    sprintf('Échec du bootstrap async PowerShell (exit=%d)', $exitCode)
                );
            }
        } finally {
            fclose($nullIn);
            fclose($nullOut);
        }
    }

    /**
     * @param list<string> $workerArgs
     */
    private function launchDetachedPosix(int $runId, string $worker, array $workerArgs): void
    {
        $logDir = dirname($this->dependencyRoot).DIRECTORY_SEPARATOR.'launcher';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $stdout = $logDir.DIRECTORY_SEPARATOR.'run-'.$runId.'.stdout.log';
        $stderr = $logDir.DIRECTORY_SEPARATOR.'run-'.$runId.'.stderr.log';

        $args = array_merge([$this->pythonExecutable, $worker], $workerArgs);
        $command = implode(' ', array_map('escapeshellarg', $args))
            .' > '.escapeshellarg($stdout)
            .' 2> '.escapeshellarg($stderr)
            .' < /dev/null &';

        $process = proc_open(
            ['/bin/sh', '-c', $command],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
            ],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Impossible de lancer le worker Python.');
        }

        proc_close($process);
    }

    private function findPowerShell(): string
    {
        $systemRoot = (string)(getenv('SystemRoot') ?: 'C:\Windows');
        $candidate = $systemRoot.DIRECTORY_SEPARATOR.'System32'
            .DIRECTORY_SEPARATOR.'WindowsPowerShell'
            .DIRECTORY_SEPARATOR.'v1.0'
            .DIRECTORY_SEPARATOR.'powershell.exe';

        return is_file($candidate) ? $candidate : 'powershell.exe';
    }
}
