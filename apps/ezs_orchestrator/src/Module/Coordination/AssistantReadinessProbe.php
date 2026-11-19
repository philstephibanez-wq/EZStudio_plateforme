<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Coordination;

final class AssistantReadinessProbe
{
    public function __construct(private readonly ?\Closure $fetch = null) {}

    public function status(): array
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:8000', $errno, $errstr, 0.3, STREAM_CLIENT_CONNECT);
        $portOpen = is_resource($socket);
        if ($portOpen) {
            fclose($socket);
        }
        $webReady = $portOpen && is_file(dirname(__DIR__, 4) . '/apps/ezs_assistant/config/bundles.php');
        $llama = $webReady ? $this->get('http://127.0.0.1:8720/health') : [];
        return [
            'web' => $webReady,
            'inference' => ($llama['status'] ?? null) === 'ok',
            'dispatch_verified' => false,
        ];
    }

    private function get(string $url): array
    {
        try {
            $fetch = $this->fetch ?? static function (string $uri): string {
                $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 2, 'follow_location' => 0]]);
                $body = @file_get_contents($uri, false, $context);
                if ($body === false) {
                    throw new \RuntimeException('unavailable');
                }
                return $body;
            };
            $body = $fetch($url);
            if (!is_string($body) || strlen($body) > 65536) {
                return [];
            }
            $json = json_decode($body, true, 12, JSON_THROW_ON_ERROR);
            return is_array($json) ? $json : [];
        } catch (\Throwable) {
            return [];
        }
    }
}