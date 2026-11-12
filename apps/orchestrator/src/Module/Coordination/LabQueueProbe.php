<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

/** Read-only LAB queue probe. No claim, command, or side effects. */
final class LabQueueProbe
{
    public function __construct(private readonly ?\Closure $fetch = null, private readonly string $labRoot = 'H:\\EZStudio_lab') {}

    public function status(): array
    {
        $token = $this->resolveToken();
        if ($token === '') {
            return ['connected' => false, 'reason' => 'token_not_configured', 'jobs' => [], 'source' => 'lab_queue_live'];
        }

        try {
            $fetch = $this->fetch ?? static function (string $url, array $headers): string {
                $context = stream_context_create(['http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headers),
                    'timeout' => 2,
                    'ignore_errors' => false,
                    'follow_location' => 0,
                ]]);
                $body = @file_get_contents($url, false, $context);
                if ($body === false) {
                    throw new \RuntimeException('lab_http_unavailable');
                }
                return $body;
            };

            $body = $fetch('http://127.0.0.1:8702/internal/analysis/desktop/jobs/queue', [
                'X-EZScore-Analysis-Token: ' . $token,
                'Accept: application/json',
            ]);
            if (strlen($body) > 2097152) {
                throw new \RuntimeException('lab_response_too_large');
            }
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['schema_version'] ?? null) !== 'ezstudio.analysis-desktop.v1' || !is_array($data['jobs'] ?? null) || !array_is_list($data['jobs'])) {
                throw new \RuntimeException('lab_queue_schema_mismatch');
            }
            $items = [];
            foreach (array_slice($data['jobs'], 0, 100) as $job) {
                if (!is_array($job)) {
                    continue;
                }
                $id = filter_var($job['id'] ?? $job['job_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    continue;
                }
                $items[] = [
                    'id' => (int) $id,
                    'kind' => substr((string) ($job['kind'] ?? '-'), 0, 64),
                    'status' => substr((string) ($job['status'] ?? 'queued'), 0, 32),
                    'priority' => is_int($job['priority'] ?? null) ? $job['priority'] : null,
                ];
            }
            return ['connected' => true, 'reason' => null, 'jobs' => $items, 'source' => 'lab_queue_live'];
        } catch (\Throwable $e) {
            // No secret, response body, or exception details exposed to the UI.
            return ['connected' => false, 'reason' => 'lab_queue_unavailable_or_invalid', 'jobs' => [], 'source' => 'lab_queue_live'];
        }
    }
    /** LAB owns the token. Read in memory only; never log or persist it. */
    private function resolveToken(): string
    {
        $explicit = trim((string) (getenv('EZS_LAB_ANALYSIS_TOKEN') ?: ''));
        if ($explicit !== '') {
            return $this->validToken($explicit);
        }
        $path = rtrim($this->labRoot, '/\\\\') . DIRECTORY_SEPARATOR . '.env.local';
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        try {
            while (($line = fgets($handle, 16384)) !== false) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                if (trim($key) !== 'EZSTUDIO_ANALYSIS_WORKER_TOKEN') {
                    continue;
                }
                $value = trim($value);
                if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                    $value = substr($value, 1, -1);
                }
                return $this->validToken(trim($value));
            }
        } finally {
            fclose($handle);
        }
        return '';
    }

    private function validToken(string $token): string
    {
        return strlen($token) <= 4096 && !preg_match('/[\\r\\n\\x00-\\x1F\\x7F]/', $token) ? $token : '';
    }

}
