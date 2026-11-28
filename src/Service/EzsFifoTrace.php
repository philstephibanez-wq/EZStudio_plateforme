<?php

declare(strict_types=1);
namespace App\Service;

/** Diagnostic temporaire désactivé. Compatibilité des appelants FIFO R2. */
final class EzsFifoTrace
{
    public static function log(string $event, array $fields = []): void
    {
        // Aucun fichier, aucun flux parent, aucun effet sur la FIFO.
    }
}
