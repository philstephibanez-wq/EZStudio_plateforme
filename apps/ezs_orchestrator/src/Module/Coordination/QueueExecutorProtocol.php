<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

/** Strict technical boundary over the existing canonical GlobalQueueStore. */
final class QueueExecutorProtocol
{
    public static function claimInput(array $input): array
    {
        $specific = array_key_exists('job_id', $input) || array_key_exists('kind', $input);
        self::keys($input, $specific
            ? ['resource_class', 'lease_seconds', 'job_id', 'kind']
            : ['resource_class', 'lease_seconds']);
        if ($specific && (!is_int($input['job_id']) || $input['job_id'] < 1
            || $input['kind'] !== 'profile' || $input['resource_class'] !== 'gpu')) {
            throw new \InvalidArgumentException('invalid_specific_claim');
        }
        if (!in_array($input['resource_class'], ['cpu', 'gpu'], true)
            || !is_int($input['lease_seconds'])
            || $input['lease_seconds'] < 30 || $input['lease_seconds'] > 600) {
            throw new \InvalidArgumentException('invalid_claim_envelope');
        }
        return $input;
    }

    public static function heartbeatInput(array $input): array
    {
        self::keys($input, ['job_id', 'claim_generation', 'lease_token', 'lease_seconds', 'progress_percent']);
        if (!is_int($input['job_id']) || $input['job_id'] < 1
            || !is_int($input['claim_generation']) || $input['claim_generation'] < 1
            || !is_string($input['lease_token'])
            || preg_match('/^[a-f0-9]{64}$/D', $input['lease_token']) !== 1
            || !is_int($input['lease_seconds']) || $input['lease_seconds'] < 30 || $input['lease_seconds'] > 600
            || ($input['progress_percent'] !== null
                && (!is_int($input['progress_percent']) || $input['progress_percent'] < 0 || $input['progress_percent'] > 99))) {
            throw new \InvalidArgumentException('invalid_heartbeat_envelope');
        }
        return $input;
    }

    public static function claim(GlobalQueueStore $store, array $validated): ?array
    {
        if (isset($validated['job_id'])) {
            $candidate = $store->byId($validated['job_id']);
            if ($candidate === null || $candidate['producer'] !== 'lab'
                || $candidate['kind'] !== 'profile' || $candidate['resource_class'] !== 'gpu') {
                return null;
            }
        }
        $job = $store->claim($validated['resource_class'], $validated['lease_seconds'],
            $validated['job_id'] ?? null);
        if ($job === null) {
            return null;
        }
        return [
            'id' => (int) $job['id'],
            'producer' => (string) $job['producer'],
            'external_id' => (string) $job['external_id'],
            'kind' => (string) $job['kind'],
            'resource_class' => (string) $job['resource_class'],
            'claim_generation' => (int) $job['claim_generation'],
            'lease_until' => null,
            'lease_token' => (string) $job['lease_token'],
        ];
    }

    public static function heartbeat(GlobalQueueStore $store, array $validated): array
    {
        $job = $store->byId($validated['job_id']);
        if ($job === null || (int) $job['claim_generation'] !== $validated['claim_generation']) {
            throw new \DomainException('claim_generation_not_current');
        }
        // The store rechecks the secret token, state and expiration atomically.
        return $store->heartbeat(
            $validated['job_id'],
            $validated['lease_token'],
            $validated['lease_seconds'],
            $validated['progress_percent'],
        );
    }

    private static function keys(array $input, array $required): void
    {
        $present = array_keys($input);
        sort($present);
        sort($required);
        if ($present !== $required) {
            throw new \InvalidArgumentException('invalid_executor_envelope_fields');
        }
    }
}
