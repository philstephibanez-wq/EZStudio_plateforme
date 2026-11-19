<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** A TCP listener check only: not a health or ownership guarantee. */
final class LocalPortStatus
{
    private const ENDPOINTS = [
        ['component' => 'ezscore', 'environment' => 'dev', 'port' => 8502],
        ['component' => 'lab', 'environment' => 'dev', 'port' => 8702],
        ['component' => 'assistant', 'environment' => 'dev', 'port' => 8712],
    ];
    public function snapshot(): array
    {
        $items = [];
        foreach (self::ENDPOINTS as $endpoint) {
            $errno = 0;
            $message = '';
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $endpoint['port'], $errno, $message, 0.25, STREAM_CLIENT_CONNECT);
            $listening = is_resource($socket);
            if ($listening) {
                fclose($socket);
            }
            $items[] = $endpoint + ['tcp_listening' => $listening];
        }
        return $items;
    }
}
