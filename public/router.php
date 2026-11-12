<?php

declare(strict_types=1);

/*
 * Router dedicated to PHP's built-in development server.
 *
 * Existing files under public/ (JS, CSS, MP3, images...) MUST be served
 * directly by the built-in server. Only application routes are delegated
 * to Symfony's front controller.
 */
if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if (is_string($requestPath) && $requestPath !== '/') {
        $decodedPath = rawurldecode($requestPath);
        $candidate = __DIR__.str_replace('/', DIRECTORY_SEPARATOR, $decodedPath);

        $publicRoot = realpath(__DIR__);
        $realCandidate = realpath($candidate);

        if (
            $publicRoot !== false
            && $realCandidate !== false
            && str_starts_with($realCandidate, $publicRoot.DIRECTORY_SEPARATOR)
            && is_file($realCandidate)
        ) {
            return false;
        }
    }
}

require __DIR__.'/index.php';
