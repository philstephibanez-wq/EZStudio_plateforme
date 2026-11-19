<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'ezs:infrastructure', description: 'Diagnostic et préflight infrastructure (lecture seule)')]
final class InfrastructureCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'status|preflight|fastcgi-incident|observe-fastcgi', 'status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        if (!in_array($action, ['status', 'preflight', 'fastcgi-incident', 'observe-fastcgi'], true)) {
            $output->writeln('EZS_PHP_UNKNOWN_ACTION');
            return Command::INVALID;
        }
        $root = dirname(__DIR__, 3);
        try {
            if ($action === 'preflight') {
                $files = [
                    $root . '/public/index.php', $root . '/vendor/autoload.php',
                    $root . '/config/caddy/Caddyfile.dev-root-candidate',
                    $root . '/config/caddy/Caddyfile.prod',
                    $root . '/config/caddy/Caddyfile.prod-internal-candidate',
                    'H:/EZScore/public/index.php',
                ];
                foreach ($files as $file) {
                    if (!is_file($file)) throw new \RuntimeException('EZS_PHP_MISSING:' . $file);
                }
                foreach (['Caddyfile.dev-root-candidate', 'Caddyfile.prod', 'Caddyfile.prod-internal-candidate'] as $name) {
                    $process = new Process(['H:/Caddy/caddy.exe', 'validate', '--config', $root . '/config/caddy/' . $name, '--adapter', 'caddyfile'], $root);
                    $process->run();
                    if (!$process->isSuccessful()) {
                        throw new \RuntimeException('EZS_PHP_CADDY_INVALID:' . $name . ' ' . trim($process->getErrorOutput()));
                    }
                }
                $output->writeln('EZS_PHP_INFRA_PREFLIGHT_OK');
                return Command::SUCCESS;
            }
            $dev = self::state($root . '/runtime/v2-dev/processes.json');
            if ($action === 'fastcgi-incident') {
                $output->writeln(self::encode(FastcgiIncidentReport::collect($root, $dev)));
                return Command::SUCCESS;
            }
            $prod = self::state($root . '/runtime/v2-prod/processes.json');
            $spec = [];
            foreach ([
                ['dev_php', $dev, 'php_pid', 8791, 'php-cgi.exe'],
                ['dev_caddy', $dev, 'caddy_pid', 8790, 'caddy.exe'],
                ['prod_php', $prod, 'php_pid', 8512, 'php-cgi.exe'],
                ['prod_internal', $prod, 'internal_pid', 8511, 'caddy.exe'],
                ['prod_gateway', $prod, 'gateway_pid', 8510, 'php-cgi.exe'],
                ['prod_edge', $prod, 'edge_pid', 8501, 'caddy.exe'],
            ] as [$name, $state, $key, $port, $image]) {
                if (is_array($state) && isset($state[$key]) && is_numeric($state[$key])) {
                    $spec[$name] = [$port, (int) $state[$key], $image];
                }
            }
            $ownership = WindowsPortOwnership::snapshot($spec);
            if ($action === 'observe-fastcgi') {
                $check = $ownership['checks']['dev_php'] ?? [];
                $observed = FastcgiTransitionJournal::observe(
                    'H:/temp/ezs-orchestrator',
                    isset($check['expected_pid']) ? (int) $check['expected_pid'] : null,
                    $check['listener_pids'] ?? [], $check['image'] ?? null
                );
                $output->writeln(self::encode($observed));
                return Command::SUCCESS;
            }
            $output->writeln(self::encode([
                'schema' => 'ezs.infrastructure.status.php.v1',
                'dev' => ['state_present' => $dev !== null, 'listeners_ready' => self::port(8790) && self::port(8791), 'ports' => [8790, 8791]],
                'prod' => ['state_present' => $prod !== null, 'listeners_ready' => self::port(8501) && self::port(8510) && self::port(8511) && self::port(8512), 'ports' => [8501, 8510, 8511, 8512]],
                'ownership' => $ownership,
                'warning' => 'Port/PID/image diagnostics only: full process identity remains unverified; never use this result to terminate processes',
            ]));
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln($exception->getMessage());
            return Command::FAILURE;
        }
    }

    private static function state(string $file): ?array
    {
        if (!is_file($file)) return null;
        $raw = file_get_contents($file);
        if (!is_string($raw)) throw new \RuntimeException('EZS_PHP_INVALID_STATE:' . $file);
        try { $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new \RuntimeException('EZS_PHP_INVALID_STATE:' . $file); }
        return is_array($data) ? $data : null;
    }

    private static function port(int $number): bool
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $number, $errno, $message, 0.5);
        if (!is_resource($socket)) return false;
        fclose($socket);
        return true;
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
