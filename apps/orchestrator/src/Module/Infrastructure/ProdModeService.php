<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

final class ProdModeService
{
    private const CONFIG = 'H:/EZScore/var/runtime/online-gateway.json';
    private const LOCK = 'H:/EZS_orchestrator/runtime/v2-prod/php-mode.lock';
    private const STATUS = 'http://127.0.0.1:8510/__ezscore_gateway_status';
    private const BACKEND = 'http://127.0.0.1:8511/fr/catalog';
    private const PUBLIC = 'http://127.0.0.1:8501/fr/catalog';

    private function get(string $url): array
    {
        $headers = "Host: ezscore.logandplay.com\r\n";
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true, 'header' => $headers]]);
        $body = @file_get_contents($url, false, $ctx);
        $head = $http_response_header ?? [];
        $code = preg_match('~^HTTP/\\S+ (\\d{3})~', (string) ($head[0] ?? ''), $m) ? (int) $m[1] : 0;
        return ['status' => $code, 'body' => is_string($body) ? $body : ''];
    }

    public function inspect(): array
    {
        $gateway = $this->get(self::STATUS);
        $data = json_decode($gateway['body'], true);
        $valid = $gateway['status'] === 200 && is_array($data)
            && ($data['protocol'] ?? '') === 'ezscore.online-gateway.v3'
            && ($data['ok'] ?? false) === true
            && ($data['backend_port'] ?? null) === 8511;
        $backend = $this->get(self::BACKEND);
        $backendReady = in_array($backend['status'], [200, 301, 302, 303, 307, 308, 401, 403], true)
            && strlen($backend['body']) > 0;
        return ['gateway_online' => $valid, 'maintenance' => $valid ? ($data['maintenance'] ?? null) : null,
            'backend_ready' => $backendReady, 'backend_http' => $backend['status']];
    }

    public function apply(string $action): array
    {
        if (!in_array($action, ['normal', 'maintenance'], true)) {
            throw new \InvalidArgumentException('invalid_prod_action');
        }
        $handle = @fopen(self::LOCK, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new \RuntimeException('prod_mode_lock_unavailable');
        }
        try {
            $before = $this->inspect();
            if (!$before['gateway_online']) throw new \RuntimeException('prod_gateway_unavailable');
            if ($action === 'normal' && !$before['backend_ready']) throw new \RuntimeException('prod_backend_unavailable');
            $previousRaw = @file_get_contents(self::CONFIG);
            $previous = is_string($previousRaw) ? json_decode($previousRaw, true) : null;
            if (!is_array($previous) || !is_bool($previous['maintenance'] ?? null)
                || ($previous['backend_port'] ?? null) !== 8511) {
                throw new \RuntimeException('prod_gateway_config_invalid');
            }
            $desired = $action === 'maintenance';
            if ($previous['maintenance'] !== $desired) {
                $updated = $previous;
                $updated['maintenance'] = $desired;
                $json = json_encode($updated, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
                $temp = self::CONFIG.'.php-'.bin2hex(random_bytes(8));
                try {
                    if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json)) {
                        throw new \RuntimeException('prod_mode_write_failed');
                    }
                    if (!@rename($temp, self::CONFIG)) throw new \RuntimeException('prod_mode_replace_failed');
                } finally {
                    if (is_file($temp)) @unlink($temp);
                }
            }
            $status = $this->inspect();
            if (!$status['gateway_online'] || $status['maintenance'] !== $desired) {
                throw new \RuntimeException('prod_mode_not_confirmed');
            }
            if ($desired) {
                $public = $this->get(self::PUBLIC);
                if ($public['status'] !== 503 || strlen($public['body']) === 0) {
                    throw new \RuntimeException('prod_maintenance_http_not_confirmed');
                }
            }
            return ['ok' => true, 'action' => $action,
                'result' => $desired ? 'EZS_PROD_MAINTENANCE_OK' : 'EZS_PROD_NORMAL_REQUESTED_VERIFY_SSO_MEDIA'];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
