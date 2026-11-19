<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Lifecycle of the Assistant's exclusive local inference dependency. */
final class AssistantDependencyLifecycle
{
    /** Start the only supported model; existing healthy instance is reused. */
    public static function ensureRunning(): array
    {
        $result = (new LocalAiLifecycle())->start();
        return $result['action'] === 'STARTED' ? ['assistant_ai' => $result['pid']] : [];
    }

    /** Stops only the exact owned llama process. Never kills by process name alone. */
    public static function stopOwned(): array
    {
        $exe = LocalAiLifecycle::EXE;
        $parts = [$exe, ...LocalAiLifecycle::arguments()];
        $patternParts = array_map(static function (string $s): string {
            $quoted = preg_quote(strtolower(str_replace('/', '\\', $s)), '~');
            return '(?:"'.$quoted.'"|'.$quoted.')';
        }, $parts);
        $pattern = '~^\s*'.implode('\s+', $patternParts).'\s*$~iD';
        $get = static function () use ($exe, $pattern): array {
            $matches = [];
            foreach ((new WindowsProcessInventory())->collect() as $item) {
                if (strcasecmp(str_replace('/', '\\', (string) $item['executable']), str_replace('/', '\\', $exe)) !== 0) continue;
                if (preg_match($pattern, strtolower(str_replace('/', '\\', (string) $item['command']))) !== 1) continue;
                $matches[(int) $item['pid']] = $item;
            }
            return $matches;
        };
        $current = $get();
        if (!$current) return [];
        if (count($current) !== 1) throw new \RuntimeException('EZS_ASSISTANT_AI_MULTIPLE_OWNED');
        $pid = (int) array_key_first($current);
        $listener = (new \Symfony\Component\Process\Process(['C:/Windows/System32/netstat.exe', '-ano', '-p', 'tcp']));
        $listener->run();
        if (!$listener->isSuccessful()) throw new \RuntimeException('EZS_ASSISTANT_AI_NETSTAT_FAILED');
        $ports = WindowsPortOwnership::parseListeners($listener->getOutput());
        if (($ports[LocalAiLifecycle::PORT] ?? []) !== [$pid]) {
            throw new \RuntimeException('EZS_ASSISTANT_AI_LISTENER_NOT_ATTESTED');
        }
        usleep(150000);
        $fresh = $get();
        if (!isset($fresh[$pid]) || (string)$fresh[$pid]['created'] !== (string)$current[$pid]['created'] ||
            (string)$fresh[$pid]['command'] !== (string)$current[$pid]['command']) {
            throw new \RuntimeException('EZS_ASSISTANT_AI_IDENTITY_CHANGED');
        }
        $verify = new \Symfony\Component\Process\Process(['C:/Windows/System32/netstat.exe', '-ano', '-p', 'tcp']);
        $verify->run();
        if (!$verify->isSuccessful() || (WindowsPortOwnership::parseListeners($verify->getOutput())[LocalAiLifecycle::PORT] ?? []) !== [$pid]) {
            throw new \RuntimeException('EZS_ASSISTANT_AI_OWNER_CHANGED');
        }
        $kill = new \Symfony\Component\Process\Process(['C:/Windows/System32/taskkill.exe', '/PID', (string)$pid, '/F']);
        $kill->run();
        if (!$kill->isSuccessful()) throw new \RuntimeException('EZS_ASSISTANT_AI_TERMINATION_FAILED');
        return ['assistant_ai' => $pid];
    }
}
