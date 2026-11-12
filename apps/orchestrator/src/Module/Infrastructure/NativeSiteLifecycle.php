<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

use Symfony\Component\Process\Process;

/** Canonical FrankenPHP lifecycle boundary. No PHP-CGI or Caddy legacy commands. */
final class NativeSiteLifecycle
{
    private const ROOT = 'H:/EZS_orchestrator';
    private const SCRIPT = self::ROOT.'/scripts/ezs_platform_control.ps1';
    private const SITES = ['dev', 'lab', 'assistant', 'prod'];

    /** @var \Closure(list<string>):array{exit_code:int,stdout:string,stderr:string} */
    private readonly \Closure $invoke;

    public function __construct(?callable $invoke = null)
    {
        $this->invoke = $invoke === null
            ? self::invokeWindows(...)
            : \Closure::fromCallable($invoke);
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private static function invokeWindows(array $argv): array
    {
        if (PHP_OS_FAMILY !== 'Windows' || !is_file(self::SCRIPT)) {
            throw new \RuntimeException('EZS_NATIVE_SITE_SCRIPT_UNAVAILABLE');
        }
        $process = new Process($argv, self::ROOT);
        $process->setTimeout(30);
        $process->run();
        return [
            'exit_code' => $process->getExitCode() ?? -1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    /** @return list<string> */
    public static function arguments(string $site, string $action): array
    {
        if (!in_array($site, self::SITES, true) || !in_array($action, ['Status', 'Preflight', 'Start', 'Stop'], true)) {
            throw new \InvalidArgumentException('EZS_NATIVE_SITE_ARGUMENT_REFUSED');
        }
        if ($site === 'prod' && $action === 'Stop') {
            throw new \RuntimeException('EZS_PROD_STOP_PROTECTED');
        }
        return [
            'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-File', self::SCRIPT, '-Site', $site, '-Action', $action,
        ];
    }

    /** Run the published, identity-checking PowerShell adapter. No shell command string. */
    public function request(string $site, string $action): string
    {
        $result = ($this->invoke)(self::arguments($site, $action));
        if (!is_array($result) || !isset($result['exit_code'], $result['stdout'], $result['stderr']) ||
            !is_int($result['exit_code']) || !is_string($result['stdout']) || !is_string($result['stderr'])) {
            throw new \RuntimeException('EZS_NATIVE_SITE_RESULT_INVALID');
        }
        if ($result['exit_code'] !== 0) {
            $safeCode = self::nativeFailureCode($result['stderr']);
            throw new \RuntimeException('EZS_NATIVE_SITE_ACTION_FAILED_'.$site.'_'.$action.'_CODE_'.$result['exit_code']
                .($safeCode === null ? '' : '_REASON_'.$safeCode));
        }
        $marker = 'EZS_PLATFORM_'.strtoupper($site).'_'.strtoupper($action).'_OK';
        if (!str_contains($result['stdout'], $marker)) {
            throw new \RuntimeException('EZS_NATIVE_SITE_UNATTESTED_SUCCESS');
        }
        return $result['stdout'];
    }

    /** A conservative whitelist, never expose untrusted subprocess output. */
    private static function nativeFailureCode(string $stderr): ?string
    {
        foreach (['DEV_MEDIA_ROOT_ENV_MISSING','FRANKENPHP_ADAPT_FAILED','FRANKENPHP_EXE_MISSING',
            'CADDY_CONFIG_MISSING','CADDY_CONFIG_FASTCGI_REFUSED','PHP_ENTRY_MISSING',
            'INTENT_MISSING','INTENT_SCHEMA_INVALID','INTENT_SITE_INVALID',
            'INTENT_STOPPED_REFUSED','FOREIGN_LISTENER','MULTIPLE_OWNED',
            'ALREADY_RUNNING_OR_STALE_OWNER_REFUSED','OWNER_RECORD_INVALID',
            'START_PROCESS_NOT_FOUND','PLATFORM_DELEGATE_MISSING',
            'ASSISTANT_CANONICAL_SCRIPT_MISSING','ASSISTANT_INTENT_MISSING',
            'ASSISTANT_START_LLAMA_CONTRACT_INVALID','ASSISTANT_STOP_LLAMA_CONTRACT_INVALID'] as $token) {
            if (preg_match('/(?<![A-Z0-9_])'.preg_quote($token,'/').'(?![A-Z0-9_])/', $stderr) === 1) {
                return $token;
            }
        }
        return null;
    }

    /** Native sites have no implicit STOP/START retry or adoption on ambiguous state. */
    public function start(string $site): array
    {
        $this->request($site, 'Preflight');
        $this->request($site, 'Start');
        return [$site => 'START_REQUESTED'];
    }

    public function stop(string $site): array
    {
        $this->request($site, 'Stop');
        return [$site => 'STOP_REQUESTED'];
    }
}
