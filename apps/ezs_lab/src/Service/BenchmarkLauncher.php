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
}
