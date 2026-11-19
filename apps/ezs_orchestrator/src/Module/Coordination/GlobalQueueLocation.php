<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Coordination;

/** Canonical file location; computing the path has no filesystem side effect. */
final class GlobalQueueLocation
{
    public static function path(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'data'
            . DIRECTORY_SEPARATOR . 'queue' . DIRECTORY_SEPARATOR . 'global.sqlite';
    }
}
