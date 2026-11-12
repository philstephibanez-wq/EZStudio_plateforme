<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

/** Explicit one-shot child-process exercise; never wired to real queue or HTTP. */
final class SyntheticChildProcessRunner
{
    public function __construct(
        private readonly GlobalQueueStore $queue,
        private readonly ExecutorOwnershipRegistry $ownership,
        private readonly string $databasePath,
    ) {
        $path = realpath($databasePath);
        $temp = realpath(sys_get_temp_dir());
        $canonical = realpath(GlobalQueueLocation::path());
        if ($path === false || $temp === false || $path === $canonical
            || !str_starts_with(strtolower(str_replace('\\', '/', $path)),
                rtrim(strtolower(str_replace('\\', '/', $temp)), '/') . '/')) {
            throw new \InvalidArgumentException('synthetic_child_isolated_database_required');
        }
    }

    /**
     * @param \Closure(int):array $observe Independent observation of the just-started PID.
     *        Required keys: pid, node, created_at, instance_id. Never infer creation time from clock.
     * @return array{claimed:bool,job_id?:int,state?:string,pid?:int}
     */
    public function runOne(string $phpBinary, string $fixtureScript, \Closure $observe): array
    {
        if (!is_file($phpBinary) || !is_file($fixtureScript)
            || strtolower(pathinfo($fixtureScript, PATHINFO_EXTENSION)) !== 'php') {
            throw new \InvalidArgumentException('synthetic_child_executable_required');
        }
        $job = $this->queue->claim('cpu');
        if ($job === null) return ['claimed' => false];

        $id = (int) $job['id'];
        $token = (string) $job['lease_token'];
        // Claims outside the synthetic namespace are fenced, not executed.
        if ($job['producer'] !== 'EZS_SYNTHETIC' || $job['kind'] !== 'synthetic_child') {
            throw new \DomainException('synthetic_child_unexpected_claim');
        }

        $pipes = [];
        $process = proc_open([$phpBinary, $fixtureScript],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $this->queue->finish($id, $token, false);
            throw new \RuntimeException('synthetic_child_spawn_failed');
        }
        fclose($pipes[0]);
        $pid = 0;
        $registered = false;
        $closed = false;
        try {
            $status = proc_get_status($process);
            $pid = (int) ($status['pid'] ?? 0);
            if ($pid < 1 || !$status['running']) {
                throw new \RuntimeException('synthetic_child_not_running');
            }
            $identity = $observe($pid);
            if (!is_array($identity) || (int) ($identity['pid'] ?? 0) !== $pid) {
                throw new \DomainException('synthetic_child_observation_mismatch');
            }
            $this->ownership->register($id, (int) $job['claim_generation'], $token,
                (string) ($identity['node'] ?? ''), $pid,
                (string) ($identity['created_at'] ?? ''),
                (string) ($identity['instance_id'] ?? ''));
            $registered = true;
            $this->queue->heartbeat($id, $token, 120, 50, 'synthetic_child');
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_get_status($process);
            $exitCode = $status['running'] ? -1 : (int) $status['exitcode'];
            $closedCode = proc_close($process);
            $closed = true;
            if ($exitCode === -1) $exitCode = $closedCode;
            $success = $exitCode === 0 && $stdout === "EZS_SYNTHETIC_CHILD_OK\n" && $stderr === '';
            $done = $this->queue->finish($id, $token, $success);
            if (!$success) throw new \RuntimeException('synthetic_child_failed');
            return ['claimed' => true, 'job_id' => $id, 'pid' => $pid, 'state' => $done['state']];
        } catch (\Throwable $error) {
            foreach ([1, 2] as $stream) {
                if (isset($pipes[$stream]) && is_resource($pipes[$stream])) fclose($pipes[$stream]);
            }
            if (!$closed) proc_close($process); // never terminate or detach a process we cannot attest
            // Only finish as failed if ownership was actually registered and the child has exited.
            if ($registered && !$closed) {
                try { $this->queue->finish($id, $token, false); } catch (\Throwable) { /* fail closed */ }
            }
            throw $error;
        }
    }
}
