<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

use Symfony\Component\Process\Process;

/** Prépare uniquement les caches des applications DEV dont le backend doit être créé. */
final class SymfonyDevCachePreparation
{
    /** @return list<string> Racines Symfony uniques ; aucun cache si aucun backend absent. */
    public static function rootsToPrepare(array $states, array $specs): array
    {
        $roots = [];
        foreach ($specs as $name => $spec) {
            if (!array_key_exists($name, $states)) {
                throw new \RuntimeException('EZS_CACHE_STATUS_INCOMPLETE_'.$name);
            }
            $state = $states[$name];
            if ($state === 'ATTESTED') continue;
            if ($state !== 'ABSENT') {
                throw new \RuntimeException('EZS_CACHE_START_REFUSED_UNVERIFIED_'.$name);
            }
            if (!str_ends_with(strtolower(str_replace('\\', '/', (string)$spec['exe'])), '/php-cgi.exe')) continue;
            $cwd = str_replace('\\', '/', (string)$spec['cwd']);
            if (!str_starts_with($cwd, 'H:/') || str_contains($cwd, '..')) {
                throw new \RuntimeException('EZS_CACHE_ROOT_REFUSED_'.$name);
            }
            $roots[$cwd] = true;
        }
        return array_keys($roots);
    }

    /** Échec bloquant : aucun processus n'a encore été démarré par cette commande. */
    public static function prepare(array $roots, ?callable $runner = null): void
    {
        foreach ($roots as $root) {
            if (!is_string($root) || !in_array($root, [
                'H:/EZS_orchestrator', 'H:/EZScore_dev', 'H:/EZStudio_lab', 'H:/EZStudioLab_assistant'
            ], true)) throw new \RuntimeException('EZS_CACHE_ROOT_NOT_ALLOWED');
            if ($runner !== null) { $runner($root); continue; }
            if (!is_file($root.'/bin/console')) throw new \RuntimeException('EZS_CACHE_CONSOLE_MISSING_'.$root);
            if (!is_file('H:/PHP/php-8.5-x64/php.exe')) throw new \RuntimeException('EZS_CACHE_PHP_MISSING');
            $process = new Process([
                'H:/PHP/php-8.5-x64/php.exe', 'bin/console', 'cache:clear', '--env=dev', '--no-debug', '--no-interaction'
            ], $root, ['APP_ENV' => 'dev', 'APP_DEBUG' => '0']);
            $process->setTimeout(120);
            $process->run();
            if (!$process->isSuccessful()) {
                throw new \RuntimeException('EZS_CACHE_CLEAR_FAILED_'.$root.' EXIT='.$process->getExitCode());
            }
        }
    }
}
