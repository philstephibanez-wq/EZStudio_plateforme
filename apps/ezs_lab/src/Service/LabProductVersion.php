<?php

declare(strict_types=1);

namespace EZSLab\Service;

/** Validation commune du VERSION LAB pour Twig et le statut REST. */
final class LabProductVersion
{
    public static function isValid(string $version): bool
    {
        return preg_match('/^(?:\d+\.\d+(?:[a-z]+\d*)?|\d+\.\d+\.\d+\.[OLAE]\d*(?:(?:a|rc)\d+)?)$/D', $version) === 1;
    }
}
