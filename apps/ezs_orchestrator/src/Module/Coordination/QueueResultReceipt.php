<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

/** LAB's persisted-result attestation; no private scientific payload crosses this boundary. */
final class QueueResultReceipt
{
    public static function parse(array $input): array
    {
        $fields = ['job_id', 'claim_generation', 'lease_token', 'receipt'];
        self::exactKeys($input, $fields);
        if (!is_int($input['job_id']) || $input['job_id'] < 1
            || !is_int($input['claim_generation']) || $input['claim_generation'] < 1
            || !is_string($input['lease_token'])
            || preg_match('/^[a-f0-9]{64}$/D', $input['lease_token']) !== 1
            || !is_array($input['receipt']) || array_is_list($input['receipt'])) {
            throw new \InvalidArgumentException('invalid_result_envelope');
        }
        $receipt = $input['receipt'];
        self::exactKeys($receipt, [
            'schema', 'producer', 'external_id', 'job_id', 'claim_generation',
            'result', 'artifact_sha256', 'signature',
        ]);
        if ($receipt['schema'] !== 'ezs.lab.persisted-result.v1'
            || $receipt['producer'] !== 'lab'
            || !is_string($receipt['external_id'])
            || preg_match('/^analysis_job:[1-9][0-9]*$/D', $receipt['external_id']) !== 1
            || !is_int($receipt['job_id']) || $receipt['job_id'] !== $input['job_id']
            || !is_int($receipt['claim_generation'])
            || $receipt['claim_generation'] !== $input['claim_generation']
            || !in_array($receipt['result'], ['completed', 'failed'], true)
            || !is_string($receipt['signature'])
            || preg_match('/^[a-f0-9]{64}$/D', $receipt['signature']) !== 1) {
            throw new \InvalidArgumentException('invalid_persistence_receipt');
        }
        if ($receipt['result'] === 'completed') {
            if (!is_string($receipt['artifact_sha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $receipt['artifact_sha256']) !== 1) {
                throw new \InvalidArgumentException('missing_artifact_digest');
            }
        } elseif ($receipt['artifact_sha256'] !== null) {
            throw new \InvalidArgumentException('invalid_failed_receipt_digest');
        }
        return $input;
    }

    /** Only a LAB-owned persistence acknowledgement may authorize global completion. */
    public static function verify(array $input, array $current, string $secret): bool
    {
        $data = self::parse($input);
        $r = $data['receipt'];
        if (strlen($secret) < 32 || strlen($secret) > 256
            || (int) $current['id'] !== $data['job_id']
            || (int) $current['claim_generation'] !== $data['claim_generation']
            || (string) $current['producer'] !== 'lab'
            || !hash_equals((string) $current['external_id'], $r['external_id'])) {
            return false;
        }
        $expected = hash_hmac('sha256', self::canonical($r), $secret);
        return hash_equals($expected, $r['signature']);
    }

    /** Stable ASCII encoding: no JSON serialization or locale-dependent formatting. */
    public static function canonical(array $receipt): string
    {
        return implode("\n", [
            'ezs.lab.persisted-result.v1',
            'lab',
            (string) $receipt['external_id'],
            (string) $receipt['job_id'],
            (string) $receipt['claim_generation'],
            (string) $receipt['result'],
            (string) ($receipt['artifact_sha256'] ?? '-'),
        ]);
    }

    private static function exactKeys(array $input, array $expected): void
    {
        $keys = array_keys($input);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException('unexpected_result_fields');
        }
    }
}
