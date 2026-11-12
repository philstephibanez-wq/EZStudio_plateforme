<?php

declare(strict_types=1);

namespace EZSLab\Service;

use Symfony\Component\Process\Process;

/** One finite, opt-in publication after a persisted PROFILE terminal event. */
final class ProfileReportPublication
{
    private const SWITCH_PATH = 'H:/EZStudio_Run_Reports_state/autopush.flag';

    public function enabled(): bool
    {
        $file = (string) (getenv('EZS_RUN_REPORTS_SWITCH') ?: self::SWITCH_PATH);
        if (!is_file($file)) {
            return true; // UI default: checked, including a fresh installation.
        }
        return trim((string) file_get_contents($file)) === '1';
    }

    public function setEnabled(bool $enabled): void
    {
        $file = (string) (getenv('EZS_RUN_REPORTS_SWITCH') ?: self::SWITCH_PATH);
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('profile_reports_config_directory_unavailable');
        }
        // The existing R1 flag remains the single source of truth.
        if (file_put_contents($file, $enabled ? "1\n" : "0\n", LOCK_EX) === false) {
            throw new \RuntimeException('profile_reports_config_write_failed');
        }
    }

    public function publish(int $runId, string $producer, ?int $revisionId = null): void
    {
        if (!$this->enabled()) {
            return; // No report, no publication log, no process and no Git access.
        }
        if ($runId <= 0 || !in_array($producer, ['lab', 'assistant'], true)) {
            throw new \InvalidArgumentException('profile_reports_invalid_event');
        }
        $root = dirname(__DIR__, 2);
        $pythonw = (string) (getenv('EZS_REPORTS_PYTHONW') ?: 'H:/Python/pythoncore-3.14-64/pythonw.exe');
        if (!is_file($pythonw)) {
            error_log('PROFILE_REPORT_ONESHOT pythonw_missing');
            return;
        }
        $command = [$pythonw, $root.'/scripts/publish_profile_oneshot.py',
            '--run-id', (string) $runId, '--producer', $producer];
        if ($revisionId !== null && $revisionId > 0) {
            array_push($command, '--revision-id', (string) $revisionId);
        }
        try {
            // pythonw.exe has no console: never create a focus-stealing window.
            // Post-persistence best effort. No watcher or task is started.
            $process = new Process($command, $root);
            $process->setTimeout(120);
            $process->run();
            if (!$process->isSuccessful()) {
                error_log('PROFILE_REPORT_ONESHOT failed_rc='.$process->getExitCode());
            }
        } catch (\Throwable $e) {
            error_log('PROFILE_REPORT_ONESHOT failed_type='.get_class($e));
        }
    }
}
