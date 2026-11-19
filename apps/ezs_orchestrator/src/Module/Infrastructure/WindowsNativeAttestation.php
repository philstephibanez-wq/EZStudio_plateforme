<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Infrastructure;

/**
 * Validates a read-only Windows identity snapshot.
 * Source-only experiment: no probe executable is launched or installed.
 */
final class WindowsNativeAttestation
{
    public static function validateResult(mixed $data, int $pid, int $port): array
    {
        $valid = is_array($data)
            && ($data['schema'] ?? null) === 'ezs.windows.attestation.v1'
            && ($data['verified'] ?? null) === true
            && $pid > 0 && $port > 0 && $port <= 65535
            && ($data['pid'] ?? null) === $pid
            && ($data['port'] ?? null) === $port
            && is_string($data['image_path'] ?? null)
            && preg_match('~^[a-zA-Z]:[\\\\/]~', $data['image_path']) === 1
            && preg_match('/^[1-9][0-9]*$/D', (string)($data['creation_filetime'] ?? '')) === 1
            && ($data['command_line_attested'] ?? null) === false
            && ($data['lifecycle_authorized'] ?? null) === false;
        if (!$valid) {
            return ['verified'=>false,'reason'=>'attestation_validation_failed','safe_to_cutover'=>false];
        }
        return [
            'verified'=>true,
            'pid'=>$pid,
            'port'=>$port,
            'image_path'=>$data['image_path'],
            'creation_filetime'=>$data['creation_filetime'],
            'command_line_attested'=>false,
            'safe_to_cutover'=>false,
        ];
    }
}
