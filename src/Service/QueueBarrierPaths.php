<?php

declare(strict_types=1);

namespace App\Service;

/** Production paths remain stable for offline operator tooling. */
final class QueueBarrierPaths
{
    public static function isTest(): bool
    {
        return ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV')) === 'test';
    }

    public static function pause(string $root): string
    {
        return rtrim($root, '/\\').'/var/orchestrator_queue_paused_on_error'.(self::isTest() ? '_test' : '').'.json';
    }

    public static function stoppedCancellation(string $root): string
    {
        return rtrim($root, '/\\').'/var/orchestrator_queue_stopped_cancel'.(self::isTest() ? '_test' : '').'.json';
    }
}
