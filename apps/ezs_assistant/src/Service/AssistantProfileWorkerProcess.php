<?php
declare(strict_types=1);

namespace EZSAssistant\Service;

/** PROFILE FIFO belongs to the existing Assistant WinSW service, not Task Scheduler.
 * The worker speaks only the LAB REST contract. No shell or second queue.
 */
final class AssistantProfileWorkerProcess
{
    private static function root(): string { return dirname(__DIR__, 2); }
    private static function python(): string { return (string)(getenv('EZS_PYTHON_BIN') ?: 'python'); }
    private static function worker(): string { return self::root().'\runtime\profile_ai_requests_worker.py'; }
    private static function path_log(): string { return self::root().'\var\log\profile_fifo_service.log'; }

    /** @var resource|null */
    private $process = null;
    private ?string $stopFile = null;

    /** Native Windows service launch with no interactive shell. */
    public function start(): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || !class_exists('COM')) {
            $this->log('START_REFUSED_PLATFORM_OR_COM');
            return false;
        }
        if (!is_file(self::python()) || !is_file(self::worker())) {
            $this->log('START_REFUSED_MISSING_PYTHON_OR_WORKER');
            return false;
        }
        try {
            // Refuse parallel workers, including an old scheduled-task instance.
            $wmi = new \COM('winmgmts://');
            foreach ($wmi->ExecQuery("SELECT ExecutablePath,CommandLine FROM Win32_Process WHERE Name='python.exe' OR Name='pythonw.exe'") as $p) {
                $command = str_replace('/', '\\', (string)$p->CommandLine);
                if (stripos($command, self::root().'\\runtime\\profile_ai_requests_worker.py') !== false ||
                    stripos($command, self::root().'\\runtime\\profile_worker_task.py') !== false ||
                    stripos($command, self::root().'\\app\\profile_worker_supervisor.py') !== false) {
                    $this->log('START_REFUSED_EXISTING_PROFILE_WORKER');
                    return false;
                }
            }
        } catch (\Throwable $e) {
            $this->log('START_REFUSED_PROCESS_ATTESTATION_UNAVAILABLE');
            return false;
        }

        $state = self::root().'\\runtime\\state';
        $logDir = dirname(self::path_log());
        if ((!is_dir($state) && !@mkdir($state, 0770, true)) ||
            (!is_dir($logDir) && !@mkdir($logDir, 0770, true))) {
            $this->log('START_REFUSED_STORAGE_UNAVAILABLE');
            return false;
        }
        $stopFile = $state.'\\profile-service-stop-'.bin2hex(random_bytes(16)).'.signal';
        if (file_exists($stopFile)) return false;
        // This code is executed by WinSW's non-interactive PHP service (Session 0).
        // No shell invocation; stdout/stderr go directly to durable files.
        $cmd = [self::python(), '-B', '-u', self::worker(),
                '--poll-seconds', '8', '--service-stop-file', $stopFile];
        $proc = @proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['file', self::path_log(), 'a'],
            2 => ['file', self::path_log(), 'a'],
        ], $pipes, self::root(), null, [
            'bypass_shell' => true,
            'create_process_group' => true,
        ]);
        if (!is_resource($proc)) {
            $this->log('START_FAILED_NATIVE_PROC_OPEN');
            return false;
        }
        fclose($pipes[0]);
        $status = proc_get_status($proc);
        if (!($status['running'] ?? false) || (int)($status['pid'] ?? 0) < 1) {
            proc_close($proc);
            $this->log('START_FAILED_WORKER_EXITED_EARLY');
            return false;
        }
        $this->process = $proc;
        $this->stopFile = $stopFile;
        $this->log('STARTED_NATIVE_SERVICE_CHILD pid='.(int)$status['pid']);
        return true;
    }

    public function requestStop(): void
    {
        if ($this->stopFile === null) return;
        // Idempotent, read only between jobs; never cancels an in-flight expertise.
        if (@file_put_contents($this->stopFile, "stop\n", LOCK_EX) === false) {
            $this->log('STOP_SIGNAL_WRITE_FAILED');
        } else {
            $this->log('STOP_REQUESTED_DRAIN_IN_PROGRESS');
        }
    }

    /** Do not kill a worker with a claimed LAB job. WinSW owns the parent service. */
    public function drain(int $seconds = 25): bool
    {
        if (!is_resource($this->process)) return true;
        $this->requestStop();
        $deadline = microtime(true) + $seconds;
        do {
            $status = proc_get_status($this->process);
            if (!($status['running'] ?? false)) {
                proc_close($this->process);
                $this->process = null;
                if ($this->stopFile !== null) @unlink($this->stopFile);
                $this->log('STOPPED_DRAINED');
                return true;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);
        // The child can still finish its claimed job and then exit on the stop file.
        // The remaining child retains the stop marker to drain safely.
        $this->log('STOP_PENDING_ACTIVE_WORKER_NO_FORCE_KILL');
        return false;
    }

    private function log(string $message): void
    {
        $dir = dirname(self::path_log());
        if (!is_dir($dir)) @mkdir($dir, 0770, true);
        @file_put_contents(self::path_log(), gmdate('c').' '.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
