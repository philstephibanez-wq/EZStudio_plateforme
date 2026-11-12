<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Coordination;

final class AssistantReadinessProbe
{
    public function __construct(private readonly ?\Closure $fetch = null) {}

    public function status(): array
    {
        $web = $this->get('http://127.0.0.1:8712/api/health');
        $webReady = ($web['ok'] ?? null) === true && ($web['service'] ?? null) === 'EZStudioLab Assistant';
        $inference = $webReady ? $this->get('http://127.0.0.1:8712/api/inference/status') : [];
        return [
            'web' => $webReady,
            'inference' => ($inference['ok'] ?? null) === true && ($inference['state'] ?? null) === 'READY',
            'dispatch_verified' => false,
        ];
    }

    private function get(string $url): array
    {
        try {
            $fetch = $this->fetch ?? static function (string $uri): string {
                $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'follow_location' => 0]]);
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
