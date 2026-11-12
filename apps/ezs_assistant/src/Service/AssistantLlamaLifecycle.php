<?php
declare(strict_types=1);

namespace EZSAssistant\Service;

/** One-shot event handler for the Assistant's own inference dependency. Not a supervisor. */
final class AssistantLlamaLifecycle
{
    private static function root(): string { return dirname(__DIR__, 2); }
    private static function llama(): string { return self::root().'\\runtime\\ai\\bin\\llama-server.exe'; }
    private static function original_task(): string { return self::root().'\\runtime\\state\\ai-gpu-task-original-161.xml'; }
    private static function path_log(): string { return self::root().'\\runtime\\state\\assistant-llama-event.log'; }
    private static function intent(): string { return self::root().'\\runtime\\state\\assistant-local-ai-intent.json'; }

    public function run(): int
    {
        try {
            $wmi = $this->wmi();
            $state = $this->serviceState($wmi);
            if ($state === 'Stopped') {
                $this->stopAll($wmi);
                return 0;
            }
            if ($state !== 'Running') {
                $this->log('SKIP_SERVICE_TRANSITION state='.$state);
                return 0;
            }
            if (!$this->shouldStart()) {
                $this->log('SKIP_INTENT_STOPPED');
                return 0;
            }
            if (!$this->webReady()) {
                $this->log('SKIP_ASSISTANT_WEB_NOT_READY');
                return 2;
            }
            if ($this->llamaReady()) {
                $this->log('ALREADY_READY');
                return 0;
            }
            $native = $this->ownProcesses($wmi);
            if (count($native) > 0) {
                $this->log('EXISTING_LLAMA_LOADING count='.count($native));
                return $this->waitReady($wmi) ? 0 : 3;
            }
            if ($this->tcpOpen(8720)) {
                throw new \RuntimeException('PORT_8720_OCCUPIED_UNHEALTHY_REFUSED');
            }
            [$exe, $args] = $this->originalGpuCommand();
            $args = $this->resolveNvidiaDevice($exe, $args);
            if ($this->serviceState($wmi) !== 'Running') {
                $this->log('SKIP_SERVICE_CHANGED_BEFORE_START');
                return 0;
            }
            $shell = new \COM('WScript.Shell');
            $logFile = self::root().'\\runtime\\state\\assistant-llama-native-'.gmdate('Ymd_His').'.log';
            if (preg_match('~(?:^|\s)--log-file(?:\s|=)~i', $args) === 1) {
                throw new \RuntimeException('LLAMA_LOG_ARGUMENT_CONFLICT');
            }
            $line = '"'.$exe.'" '.$args.' --log-file "'.$logFile.'" -lv 4';
            $this->log('LLAMA_NATIVE_LOG='.$logFile);
            // 0 = SW_HIDE, false = asynchronous, native process remains independent.
            // Use PHP COM, not an external VBS or PowerShell bootstrap.
            $shell->Run($line, 0, false);
            $this->log('NATIVE_START_REQUESTED_WITHOUT_CONSOLE');
            if (!$this->waitReady($wmi)) {
                throw new \RuntimeException('LLAMA_READY_TIMEOUT');
            }
            $this->log('LLAMA_READY_8720');
            return 0;
        } catch (\Throwable $e) {
            $this->log('EVENT_FAILED '.$e->getMessage());
            return 2;
        }
    }

    /** Explicit, one-shot shutdown from the WinSW stop command. Never infer STOP from SCM state. */
    public function stopOwned(): int
    {
        try {
            $this->stopAll($this->wmi());
            return 0;
        } catch (\Throwable $e) {
            $this->log('EXPLICIT_STOP_FAILED '.$e->getMessage());
            return 2;
        }
    }

    public static function decide(string $service, bool $intentRunning): string
    {
        return match ($service) {
            'Stopped' => 'STOP',
            'Running' => $intentRunning ? 'START' : 'SKIP',
            default => 'SKIP',
        };
    }

    private function wmi(): \COM
    {
        if (!class_exists('COM')) {
            throw new \RuntimeException('PHP_COM_DOTNET_EXTENSION_REQUIRED');
        }
        return new \COM('winmgmts://');
    }

    private function serviceState(\COM $wmi): string
    {
        foreach ($wmi->ExecQuery("SELECT State FROM Win32_Service WHERE Name='EZS_FrankenPHP'") as $service) {
            return (string) $service->State;
        }
        throw new \RuntimeException('FRANKENPHP_SERVICE_NOT_FOUND');
    }

    /** @return array<int,object> */
    private function ownProcesses(\COM $wmi): array
    {
        $found = [];
        foreach ($wmi->ExecQuery("SELECT ProcessId, ExecutablePath FROM Win32_Process WHERE Name='llama-server.exe'") as $process) {
            $path = str_replace('/', '\\', (string) $process->ExecutablePath);
            if (strcasecmp($path, self::llama()) !== 0) {
                continue;
            }
            $found[] = $process;
        }
        return $found;
    }

    /** Only the exact native Assistant Llama is eligible for shutdown. */
    public static function isOwnedStopTarget(string $path, string $commandLine): bool
    {
        if (strcasecmp(str_replace('/', '\\', $path), self::llama()) !== 0) {
            return false;
        }
        // Check the listening endpoint, selected GPU and model cache. No other Llama.
        return preg_match('~(?:^|\s)--host\s+127\.0\.0\.1(?:\s|$)~i', $commandLine) === 1
            && preg_match('~(?:^|\s)--port\s+8720(?:\s|$)~i', $commandLine) === 1
            && preg_match('~(?:^|\s)-dev\s+Vulkan[0-9]+(?:\s|$)~i', $commandLine) === 1
            && (self::hasConfiguredLocalModel($commandLine)
                || stripos($commandLine, self::root().'\\runtime\\models-cache\\assistant\\') !== false);
    }

    private static function hasConfiguredLocalModel(string $commandLine): bool
    {
        $file = self::root().'\\runtime\\state\\local-model.json';
        if (!is_file($file)) return false;
        $cfg = json_decode((string) file_get_contents($file), true);
        if (!is_array($cfg) || ($cfg['schema'] ?? '') !== 'ezs.assistant.model-location.v1') return false;
        $model = (string) ($cfg['model'] ?? '');
        $root = rtrim((string) ($cfg['root'] ?? ''), '\\');
        $local = static function(string $p): bool {
            return strlen($p) >= 4 && ctype_alpha($p[0]) && $p[1] === ':' && $p[2] === '\\'
                && !str_contains($p, '..') && !str_contains($p, '/')
                && !str_starts_with($p, '\\\\');
        };
        if (!$local($root) || !$local($model) || stripos($model, $root.'\\') !== 0
            || !str_ends_with(strtolower($model), '.gguf')) return false;
        return stripos($commandLine, $model) !== false;
    }

    /** Requery by PID just before taskkill, to reject stale/reused PIDs. */
    private function stopTargetIdentity(\COM $wmi, int $pid): ?string
    {
        if ($pid < 1) return null;
        foreach ($wmi->ExecQuery('SELECT ProcessId,ExecutablePath,CommandLine,CreationDate FROM Win32_Process WHERE ProcessId='.$pid) as $p) {
            if (!self::isOwnedStopTarget((string)$p->ExecutablePath, (string)$p->CommandLine)) {
                throw new \RuntimeException('LLAMA_STOP_IDENTITY_MISMATCH pid='.$pid);
            }
            $created = (string)$p->CreationDate;
            if ($created === '') throw new \RuntimeException('LLAMA_CREATION_DATE_UNAVAILABLE pid='.$pid);
            return $created;
        }
        return null;
    }

    private function killVerifiedPid(\COM $wmi, int $pid, string $created): void
    {
        // A second WMI read protects against ordinary PID reuse before terminating.
        $now = $this->stopTargetIdentity($wmi, $pid);
        if ($now === null) return; // Process exited naturally.
        if (!hash_equals($created, $now)) {
            throw new \RuntimeException('LLAMA_STOP_PID_REUSED pid='.$pid);
        }
        $systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
        $exe = rtrim($systemRoot, '\\/').'\\System32\\taskkill.exe';
        if (!is_file($exe)) throw new \RuntimeException('TASKKILL_EXE_MISSING');
        // Native Windows taskkill avoids SWbemObjectEx.Terminate(0) PHP COM type mismatch.
        // /PID only, no /T: never kill unrelated child processes or the web service.
        $proc = @proc_open([$exe, '/PID', (string)$pid, '/F'],
            [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes,
            self::root(), null, ['bypass_shell'=>true]);
        if (!is_resource($proc)) throw new \RuntimeException('LLAMA_TASKKILL_START_FAILED pid='.$pid);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $rc = proc_close($proc);
        // Taskkill may exit just before WMI publishes the disappearance.
        $remaining = $this->stopTargetIdentity($wmi, $pid);
        for ($attempt = 0; $remaining !== null && $attempt < 10; ++$attempt) {
            usleep(200000); // At most 2 seconds, only during this one STOP call.
            $remaining = $this->stopTargetIdentity($wmi, $pid);
        }
        if ($remaining !== null) {
            throw new \RuntimeException('LLAMA_TASKKILL_FAILED pid='.$pid.' rc='.$rc.' detail='.substr(trim((string)$err.' '.(string)$out),0,160));
        }
        $this->log('LLAMA_VERIFIED_PID_TERMINATED pid='.$pid.' taskkill_rc='.$rc);
    }

    private function stopAll(\COM $wmi): void
    {
        $count = 0;
        foreach ($this->ownProcesses($wmi) as $process) {
            $pid = (int)$process->ProcessId;
            $created = $this->stopTargetIdentity($wmi, $pid);
            if ($created === null) continue;
            $this->killVerifiedPid($wmi, $pid, $created);
            ++$count;
        }
        $this->log('FRANKENPHP_STOP_LLAMA_TERMINATED_COUNT='.$count);
        if (count($this->ownProcesses($wmi)) !== 0) {
            throw new \RuntimeException('LLAMA_PROCESSES_REMAIN_AFTER_STOP');
        }
    }

    private function shouldStart(): bool
    {
        if (!is_file(self::intent())) {
            throw new \RuntimeException('AI_INTENT_MISSING_REFUSED');
        }
        $intent = json_decode((string) file_get_contents(self::intent()), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($intent) || ($intent['schema'] ?? '') !== 'ezstudio.assistant.local-ai-intent.v1' || !in_array($intent['state'] ?? '', ['RUNNING','STOPPED'], true)) {
            throw new \RuntimeException('AI_INTENT_INVALID_REFUSED');
        }
        return $intent['state'] === 'RUNNING';
    }

    /** Resolve the NVIDIA device from llama.cpp, never assume Vulkan numeric order. */
    private function resolveNvidiaDevice(string $exe, string $args): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // Execute only our verified binary. Never call a shell or a second server.
        $process = @proc_open([$exe, '--list-devices'], $descriptors, $pipes,
            self::root(), null, ['bypass_shell' => true, 'create_new_console' => false]);
        if (!is_resource($process)) {
            throw new \RuntimeException('LLAMA_GPU_DEVICE_PROBE_FAILED');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + 10.0;
        $exitCode = null;
        do {
            foreach ([1, 2] as $fd) {
                $chunk = stream_get_contents($pipes[$fd]);
                if (is_string($chunk)) $output .= $chunk;
            }
            if (strlen($output) > 16384) {
                @proc_terminate($process);
                break;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        if ($exitCode === null) @proc_terminate($process);
        foreach ([1, 2] as $fd) fclose($pipes[$fd]);
        proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException('LLAMA_GPU_DEVICE_PROBE_UNAVAILABLE');
        }
        $device = self::nvidiaDeviceFromList($output);
        $args = preg_replace('~(?<!\\S)-dev\\s+Vulkan1(?=\\s|$)~i',
            '-dev '.$device, $args, 1, $count);
        if ($count !== 1 || !is_string($args)) {
            throw new \RuntimeException('LLAMA_GPU_DEVICE_REWRITE_FAILED');
        }
        $this->log('LLAMA_GPU_SELECTED='.$device.' name=NVIDIA GeForce RTX 2060');
        return $args;
    }

    /** Pure parser: refuse missing, duplicate or ambiguous NVIDIA GPUs. */
    private static function nvidiaDeviceFromList(string $output): string
    {
        preg_match_all('~^\\s*(Vulkan[0-9]+):\\s*NVIDIA GeForce RTX 2060\\s*\\(~im',
            $output, $matches);
        if (count($matches[1]) !== 1) {
            throw new \RuntimeException('LLAMA_NVIDIA_DEVICE_NOT_UNIQUE_OR_MISSING');
        }
        return $matches[1][0];
    }

    /** @return array{string,string} */
    private function originalGpuCommand(): array
    {
        if (!is_file(self::original_task())) {
            throw new \RuntimeException('ORIGINAL_GPU_TASK_XML_MISSING');
        }
        $xml = new \DOMDocument();
        if (!$xml->load(self::original_task(), LIBXML_NONET)) {
            throw new \RuntimeException('ORIGINAL_GPU_TASK_XML_INVALID');
        }
        $xpath = new \DOMXPath($xml);
        $exe = trim((string) $xpath->evaluate("string(//*[local-name()='Exec']/*[local-name()='Command'])"));
        $args = trim((string) $xpath->evaluate("string(//*[local-name()='Exec']/*[local-name()='Arguments'])"));
        if (strcasecmp($exe, self::llama()) !== 0) {
            throw new \RuntimeException('GPU_EXECUTABLE_MISMATCH');
        }
        foreach (['~(?:^|\s)-dev\s+Vulkan1(?:\s|$)~i', '~(?:^|\s)-ngl\s+99(?:\s|$)~i',
                  '~(?:^|\s)-c\s+4096(?:\s|$)~i', '~(?:^|\s)--host\s+127\.0\.0\.1(?:\s|$)~i',
                  '~(?:^|\s)--port\s+8720(?:\s|$)~i'] as $pattern) {
            if (preg_match($pattern, $args) !== 1) {
                throw new \RuntimeException('GPU_ARGUMENTS_MISMATCH');
            }
        }
        if (preg_match('~(?:^|\s)-m\s+(?:"([^"]+)"|(\S+))~i', $args, $match) !== 1) {
            throw new \RuntimeException('GPU_MODEL_ARGUMENT_MISSING');
        }
        $model = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
        $legacy = self::root().'\\runtime\\models-cache\\assistant\\';
        $configFile = self::root().'\\runtime\\state\\local-model.json';
        if (!is_file($configFile)) throw new \RuntimeException('GPU_LOCAL_MODEL_CONFIG_MISSING');
        $settings = json_decode((string) file_get_contents($configFile), true, 16);
        if (!is_array($settings) || ($settings['schema'] ?? '') !== 'ezs.assistant.model-location.v1') {
            throw new \RuntimeException('GPU_LOCAL_MODEL_CONFIG_INVALID');
        }
        $canonical = rtrim((string) ($settings['root'] ?? ''), '\\');
        $selected = (string) ($settings['model'] ?? '');
        $localAbsolute = static function (string $p): bool {
            return strlen($p) >= 4 && ctype_alpha($p[0]) && $p[1] === ':' && $p[2] === '\\'
                && !str_contains($p, '..') && !str_contains($p, '/')
                && !str_starts_with($p, '\\\\');
        };
        if (!$localAbsolute($canonical) || !$localAbsolute($selected)
            || stripos($selected, $canonical.'\\') !== 0
            || !str_ends_with(strtolower($selected), '.gguf')) {
            throw new \RuntimeException('GPU_LOCAL_MODEL_CONFIG_PATH_INVALID');
        }
        $model = $selected;
        $args = preg_replace_callback('~(?<!\\S)-m\\s+(?:"[^"]+"|\\S+)~i',
            static fn ($m) => '-m "'.$model.'"', $args, 1, $count);
        if ($count !== 1 || !is_string($args)) throw new \RuntimeException('GPU_MODEL_REWRITE_FAILED');
        if (stripos($model, $canonical) !== 0 || strpos(substr($model, strlen($canonical)), '..') !== false
            || !str_ends_with(strtolower($model), '.gguf') || !is_file($model)) {
            throw new \RuntimeException('GPU_CANONICAL_MODEL_INVALID');
        }
        return [$exe, $args];
    }

    private function tcpOpen(int $port): bool
    {
        $errno = 0; $err = '';
        $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $err, 1);
        if (!is_resource($socket)) return false;
        fclose($socket);
        return true;
    }

    private function webReady(): bool
    {
        for ($i = 0; $i < 20; ++$i) {
            $response = $this->getJson('http://127.0.0.1:8713/api/health');
            if (($response['ok'] ?? false) === true && ($response['service'] ?? null) === 'EZStudioLab Assistant') {
                return true;
            }
            sleep(2);
        }
        return false;
    }

    private function llamaReady(): bool
    {
        return ($this->getJson('http://127.0.0.1:8720/health')['status'] ?? null) === 'ok';
    }

    private function waitReady(\COM $wmi): bool
    {
        for ($i = 0; $i < 50; ++$i) {
            if ($this->serviceState($wmi) === 'Stopped') {
                $this->stopAll($wmi);
                throw new \RuntimeException('SERVICE_STOPPED_DURING_LLAMA_START');
            }
            if ($this->llamaReady()) return true;
            sleep(2);
        }
        return false;
    }

    private function getJson(string $url): ?array
    {
        $context = stream_context_create(['http'=>['timeout'=>2, 'ignore_errors'=>false]]);
        $content = @file_get_contents($url, false, $context);
        if (!is_string($content)) return null;
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    private function log(string $event): void
    {
        $directory = dirname(self::path_log());
        if (!is_dir($directory)) @mkdir($directory, 0770, true);
        @file_put_contents(self::path_log(), gmdate('c').' '.$event.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
