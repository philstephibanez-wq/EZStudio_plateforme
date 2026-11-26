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
        $webReady = $portOpen && is_file(dirname(__DIR__, 5) . '/apps/ezs_assistant/config/bundles.php');
        return [
            'web' => $webReady,
            'inference' => false,
            'inference_required' => false,
            'dispatch_verified' => false,
        ];
    }

}
