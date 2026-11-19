<?php
declare(strict_types=1);
/** php -S router maintained by EZS_orchestrator; never reads EZScore PROD data. */
$public = 'H:/EZS_maintenance/public';
$uriPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$decoded = rawurldecode($uriPath);
if (str_contains($decoded, "\0") || str_contains(str_replace('\\', '/', $decoded), '../')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    return;
}
$asset = realpath($public . '/' . ltrim($decoded, '/'));
$root = realpath($public);
if ($asset !== false && $root !== false && str_starts_with($asset, $root . DIRECTORY_SEPARATOR)
    && is_file($asset) && !str_ends_with(strtolower($asset), '.php')) {
    return false; // Let PHP's built-in server serve immutable local assets.
}
require $public . '/index.php';
