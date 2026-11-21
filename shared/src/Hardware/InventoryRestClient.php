<?php
declare(strict_types=1);

namespace Shared\Hardware;

/** Read-only REST consumer for the Orchestrator inventory. Never assigns resources. */
final class InventoryRestClient
{
    private const SCHEMA = 'ezstudio.hardware-inventory.v1';
    private const PATH = '/internal/hardware/inventory';
    private const MAX_BODY = 1048576;

    /** @param null|\Closure(string,string):array{status:int,headers:array,body:string} $transport Test seam only. */
    public function __construct(private readonly string $baseUrl, private readonly string $agentId, private readonly ?\Closure $transport = null) {}

    public function read(): array
    {
        $token = (new AgentCredentials())->forAgent($this->agentId);
        if ($token === '') throw new \RuntimeException('HARDWARE_API_TOKEN_MISSING');
        $base = rtrim(trim($this->baseUrl), '/');
        $parts = parse_url($base);
        if (!is_array($parts) || !in_array(($parts['scheme'] ?? ''), ['http','https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['path'])
            || strlen($base) > 512) {
            throw new \RuntimeException('HARDWARE_API_BASE_URL_INVALID');
        }
        $url = $base . self::PATH;
        $response = $this->transport !== null
            ? ($this->transport)($url, $token)
            : self::httpGet($url, $token);
        if (!is_array($response)) throw new \RuntimeException('HARDWARE_API_RESPONSE_INVALID');
        return self::decode((int)($response['status'] ?? 0), (array)($response['headers'] ?? []), (string)($response['body'] ?? ''));
    }

    private static function httpGet(string $url, string $token): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 4, 'ignore_errors' => true,
            'follow_location' => 0, 'max_redirects' => 0,
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
        ]]);
        $handle = @fopen($url, 'rb', false, $ctx);
        if (!is_resource($handle)) throw new \RuntimeException('HARDWARE_API_CONNECTION_FAILED');
        try {
            $meta = stream_get_meta_data($handle);
            $headers = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
            $body = stream_get_contents($handle, self::MAX_BODY + 1);
        } finally {
            fclose($handle);
        }
        if (!is_string($body)) throw new \RuntimeException('HARDWARE_API_READ_FAILED');
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\d(?:\.\d)?\s+(\d{3})\b~i', (string)$header, $match)) {
                $status = (int)$match[1];
                break;
            }
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    public static function decode(int $status, array $headers, string $body): array
    {
        if ($status !== 200) {
            throw new \RuntimeException(match ($status) {
                401, 403 => 'HARDWARE_API_UNAUTHORIZED',
                503 => 'HARDWARE_API_UNAVAILABLE',
                default => 'HARDWARE_API_HTTP_ERROR_' . $status,
            });
        }
        if (strlen($body) > self::MAX_BODY) throw new \RuntimeException('HARDWARE_API_RESPONSE_TOO_LARGE');
        $cache = '';
        $contentType = '';
        foreach ($headers as $header) {
            if (!is_string($header)) continue;
            if (stripos($header, 'Cache-Control:') === 0) $cache .= ',' . trim(substr($header, 14));
            if (stripos($header, 'Content-Type:') === 0) $contentType = trim(substr($header, 13));
        }
        if (!preg_match('/(?:^|,)\s*no-store\s*(?:,|$)/i', ltrim($cache, ','))
            || preg_match('/(?:^|,)\s*public\s*(?:,|$)/i', ltrim($cache, ','))) {
            throw new \RuntimeException('HARDWARE_API_CACHE_POLICY_INVALID');
        }
        if (!preg_match('~^application/json(?:\s*;|$)~i', $contentType)) {
            throw new \RuntimeException('HARDWARE_API_CONTENT_TYPE_INVALID');
        }
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('HARDWARE_API_JSON_INVALID', 0, $e);
        }
        if (!is_array($data)
            || ($data['schema'] ?? null) !== self::SCHEMA
            || !is_string($data['node_id'] ?? null)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $data['node_id']) !== 1
            || ($data['snapshot_type'] ?? null) !== 'static_capabilities'
            || ($data['scope'] ?? null) !== 'local_node_only'
            || ($data['usage_or_availability'] ?? null) !== 'not_measured'
            || ($data['resource_assignment'] ?? null) !== 'not_supported'
            || !is_array($data['cpu'] ?? null)
            || !is_array($data['gpus'] ?? null)
            || !is_string($data['collected_at_utc'] ?? null)) {
            throw new \RuntimeException('HARDWARE_API_CONTRACT_INVALID');
        }
        return $data;
    }

}
