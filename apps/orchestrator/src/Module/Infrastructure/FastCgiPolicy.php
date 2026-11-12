<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Decision pure, sans processus ni stockage. */
final class FastCgiPolicy
{
    public static function shouldRecover(bool $wanted, bool $healthy, ?int $since, int $now): bool
    {
        if (!$wanted || $healthy) return false;
        return $since === null || $now - $since >= 60;
    }

    public static function isFastCgi(string $name): bool
    {
        return in_array($name, ['ezscore_dev','ezscore_preview','lab','assistant','prod_backend','prod_gateway'], true);
    }
}
