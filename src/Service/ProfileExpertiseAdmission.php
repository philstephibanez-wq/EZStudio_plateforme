<?php

declare(strict_types=1);

namespace App\Service;

/** N3/N4: suspension of NEW local PROFILE expertise only, not execution. */
final class ProfileExpertiseAdmission
{
    public const ERROR = 'expertise_locale_suspendue';
    public const SUSPENDED = true;

    public static function blocksKind(mixed $kind): bool
    {
        return self::SUSPENDED && in_array($kind, [
            'profile.review', 'profile.direct_research',
            'assistant.profile.review', 'expertise.profile',
        ], true);
    }

    public static function assertOpen(): void
    {
        if (self::SUSPENDED) {
            throw new \DomainException(self::ERROR);
        }
    }
}
