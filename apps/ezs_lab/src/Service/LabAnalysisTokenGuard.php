<?php

declare(strict_types=1);

namespace EZSLab\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class LabAnalysisTokenGuard
{
    public function __construct(
        private readonly string $projectDir = __DIR__.'/../..',
    ) {
    }

    public function assertAuthorized(Request $request): void
    {
        $expected = $this->configuredToken();
        if ($expected === '') {
            throw new ServiceUnavailableHttpException(
                null,
                'EZSTUDIO_ANALYSIS_WORKER_TOKEN is not configured.'
            );
        }

        $provided = trim((string)$request->headers->get('X-EZScore-Analysis-Token', ''));
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new AccessDeniedHttpException('Invalid analysis worker token.');
        }
    }

    public function configuredToken(): string
    {
        $runtime = trim((string)($_ENV['EZSTUDIO_ANALYSIS_WORKER_TOKEN']
            ?? $_SERVER['EZSTUDIO_ANALYSIS_WORKER_TOKEN']
            ?? getenv('EZSTUDIO_ANALYSIS_WORKER_TOKEN')
            ?: ''));

        if ($runtime !== '') {
            return $runtime;
        }

        $root = realpath($this->projectDir) ?: $this->projectDir;
        $path = rtrim($root, '\\/').DIRECTORY_SEPARATOR.'.env.local';
        if (!is_file($path)) {
            return '';
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return '';
        }

        foreach ($lines as $raw) {
            $line = trim((string)$raw);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            if (trim($key) !== 'EZSTUDIO_ANALYSIS_WORKER_TOKEN') {
                continue;
            }
            $value = trim($value);
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            return trim($value);
        }

        return '';
    }
}
