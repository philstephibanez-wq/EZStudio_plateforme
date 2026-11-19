<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ezs:gateway:monitor', description: 'Observer la Gateway PROD sans mutation')]
final class GatewayMonitorCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'once ou watch', 'once')
            ->addArgument('seconds', InputArgument::OPTIONAL, 'Intervalle de watch (2..3600)', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        $seconds = filter_var((string) $input->getArgument('seconds'), FILTER_VALIDATE_INT);
        if (!in_array($action, ['once', 'watch'], true) || ($action === 'watch' && ($seconds === false || $seconds < 2 || $seconds > 3600))) {
            $output->writeln('EZS_GATEWAY_MONITOR_BAD_ACTION');
            return Command::INVALID;
        }
        do {
            $snapshot = self::snapshot(dirname(__DIR__, 3));
            $output->writeln(json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if ($action === 'once') {
                return $snapshot['prod_gateway']['healthy'] ? Command::SUCCESS : Command::FAILURE;
            }
            if (!$snapshot['prod_gateway']['healthy']) {
                fwrite(STDERR, "EZS_GATEWAY_MONITOR_ALERT\n");
            }
            sleep((int) $seconds);
        } while (true);
    }

    public static function snapshot(string $root): array
    {
        $raw = @file_get_contents($root . '/runtime/v2-prod/processes.json');
        $state = is_string($raw) ? json_decode($raw, true) : null;
        $pid = is_array($state) ? (int) ($state['gateway_pid'] ?? 0) : 0;
        $headers = [];
        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true, 'follow_location' => 0]]);
        $body = @file_get_contents('http://127.0.0.1:8510/__ezscore_gateway_status', false, $context);
        $headers = $http_response_header ?? [];
        $http = preg_match('~^HTTP/\S+\s+(\d{3})~', (string) ($headers[0] ?? ''), $m) ? (int) $m[1] : 0;
        $json = is_string($body) ? json_decode($body, true) : null;
        $valid = $http === 200 && is_array($json) && ($json['ok'] ?? false) === true
            && ($json['protocol'] ?? '') === 'ezscore.online-gateway.v3' && ($json['backend_port'] ?? null) === 8511;
        $identity = $pid > 0
            ? WindowsPortOwnership::snapshot(['prod_gateway' => [8510, $pid, 'python.exe']])
            : ['available' => false, 'reason' => 'prod_state_missing'];
        $identityOk = ($identity['checks']['prod_gateway']['verified'] ?? false) === true;
        return [
            'schema' => 'ezs.gateway.monitor.v1', 'timestamp_utc' => gmdate('c'), 'mode' => 'READ_ONLY',
            'prod_gateway' => [
                'port' => 8510, 'pid_recorded' => $pid, 'http' => $http,
                'protocol_valid' => $valid, 'maintenance' => $valid ? ($json['maintenance'] ?? null) : null,
                'port_pid_image_verified' => $identityOk, 'healthy' => $identityOk && $valid,
            ],
            'limitations' => [
                'No full executable path, command-line or process start-time attestation',
                'No lifecycle actions; Python PROD remains authoritative',
                'SSO and real media streaming are not verified',
            ],
            'safe_to_cutover' => false,
        ];
    }
}
