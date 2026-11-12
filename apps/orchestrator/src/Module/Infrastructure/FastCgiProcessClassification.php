<?php
declare(strict_types=1);
namespace Ezs\Web\Module\Infrastructure;

/** Read-only evidence classification. Never authorizes termination on its own. */
final class FastCgiProcessClassification
{
    public static function label(bool $knownCommand, bool $listening): string
    {
        if (!$knownCommand) return 'UNVERIFIED';
        return $listening ? 'ACTIVE' : 'RESIDUAL';
    }
}
