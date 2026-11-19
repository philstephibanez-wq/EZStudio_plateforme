<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'ezs:gateway:preflight', description: 'Préflight Gateway en lecture seule, sans bascule PROD')]
final class GatewayPreflightCommand extends Command
{
    public function __construct(private readonly string $projectDir = '')
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'candidate ou cutover');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = (string) $input->getArgument('target');
        if (!in_array($target, ['candidate', 'cutover'], true)) {
            $output->writeln('<error>EZS_GATEWAY_PREFLIGHT_INVALID_TARGET</error>');
            return Command::INVALID;
        }
        $root = $this->projectDir !== '' ? $this->projectDir : dirname(__DIR__, 3);
        $result = $target === 'candidate' ? self::candidate($root) : self::cutover($root);
        $output->writeln(json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $result['success'] ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return array{data:array<string,mixed>,success:bool} */
    public static function candidate(string $root, ?callable $validate = null): array
    {
        $config = $root . '/config/caddy/Caddyfile.gateway-php-candidate';
        $router = $root . '/runtime_components/prod_gateway_php/router.php';
        $requirements = [
            'PHP FastCGI candidate must listen on loopback 18515 and be supervised.',
            'Caddy candidate must bind only loopback 18514, never the public 8501.',
            'FastCGI routing must preserve original REQUEST_URI including query string.',
            'Run functional SSO and media verification before changing 8510.',
            'Keep Python launcher and rollback until a real cutover is approved.',
        ];
        $files = is_file($config) && is_file($router);
        $syntax = null;
        $caddy = 'H:/Caddy/caddy.exe';
        if ($files && is_file($caddy)) {
            if ($validate !== null) {
                $syntax = $validate($config);
            } else {
                $process = new Process([$caddy, 'validate', '--config', $config, '--adapter', 'caddyfile'], $root);
                try {
                    $process->run();
                    $syntax = ['valid' => $process->isSuccessful(), 'error' => trim($process->getErrorOutput())];
                } catch (\Throwable $e) {
                    $syntax = ['valid' => false, 'error' => $e->getMessage()];
                }
            }
        }
        return ['data' => [
            'schema' => 'ezs.gateway.fastcgi.candidate.v1', 'mode' => 'READ_ONLY',
            'files_present' => $files, 'caddy_validation' => $syntax,
            'unverified_requirements' => $requirements, 'prod_cutover_authorized' => false,
        ], 'success' => $files && ($syntax === null || $syntax['valid'])];
    }

    /** @return array{data:array<string,mixed>,success:bool} */
    public static function cutover(string $root, ?callable $get = null): array
    {
        $stateFile = $root . '/runtime/v2-prod/processes.json';
        $gateway = $root . '/runtime_components/prod_gateway_php/router.php';
        $legacy = $root . '/runtime_components/prod_gateway/server.py';
        $state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
        $get ??= static function (string $uri): array {
            $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true, 'follow_location' => 0]]);
            $body = @file_get_contents($uri, false, $ctx);
            $headers = $http_response_header ?? [];
            $code = preg_match('~^HTTP/\\S+ (\\d{3})~', (string) ($headers[0] ?? ''), $match) ? (int) $match[1] : 0;
            return ['http' => $code, 'body' => is_string($body) ? $body : null];
        };
        $status = $get('http://127.0.0.1:8510/__ezscore_gateway_status');
        $payload = is_string($status['body'] ?? null) ? json_decode($status['body'], true) : null;
        $expected = is_array($payload) && ($payload['protocol'] ?? null) === 'ezscore.online-gateway.v3'
            && ($payload['ok'] ?? null) === true && ($payload['backend_port'] ?? null) === 8511;
        $data = [
            'schema' => 'ezs.gateway.cutover.preflight.v1', 'mode' => 'READ_ONLY_NO_CUTOVER',
            'files' => ['php_candidate' => is_file($gateway), 'python_rollback' => is_file($legacy)],
            'prod_state_present' => is_array($state), 'prod_gateway_status_http' => $status['http'] ?? 0,
            'prod_gateway_protocol_valid' => $expected,
            'prod_maintenance' => $expected ? ($payload['maintenance'] ?? null) : null,
            'cutover_blockers' => [
                'No validated production PHP gateway process manager / persistent launch',
                'PROD lifecycle still launches and verifies Python gateway on 8510',
                'Infrastructure ownership diagnostic still expects python.exe on 8510',
                'Real SSO, media auth and streaming tests not approved',
                'No exercised rollback procedure with verified process identity',
            ],
            'can_cutover' => false,
        ];
        return ['data' => $data, 'success' => $expected && is_array($state) && is_file($gateway) && is_file($legacy)];
    }
}
