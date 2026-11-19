<?php
declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

/**
 * Strict identity-bound negative process attestation; no process is stopped here.
 * The probe must be supplied by an independent supervisor, not by the job producer.
 */
final class ExecutorStopAttestor
{
    /**
     * @param \Closure(array):array $probe trusted observer, returning independently observed fields
     * @param array<string,mixed> $ownership attested at claim/spawn, from a trusted durable registry
     */
    public function __construct(private readonly \Closure $probe, private readonly array $ownership)
    {
    }

    /** @param array<string,mixed> $candidate expiredLeases/recoverExpired candidate */
    public function attest(array $candidate): bool
    {
        // An unknown observation is not evidence of a stopped executor.
        foreach (['id', 'claim_generation'] as $field) {
            if (!isset($candidate[$field]) || !is_numeric($candidate[$field])) {
                return false;
            }
        }
        foreach (['id', 'claim_generation', 'node', 'pid', 'created_at', 'instance_id'] as $field) {
            if (!isset($this->ownership[$field])) {
                return false;
            }
        }
        if ((int) $candidate['id'] !== (int) $this->ownership['id']
            || (int) $candidate['claim_generation'] !== (int) $this->ownership['claim_generation']
            || (int) $this->ownership['pid'] <= 0
            || !is_string($this->ownership['node']) || $this->ownership['node'] === ''
            || !is_string($this->ownership['created_at']) || $this->ownership['created_at'] === ''
            || !is_string($this->ownership['instance_id']) || $this->ownership['instance_id'] === '') {
            return false;
        }
        try {
            $observation = ($this->probe)($this->ownership);
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($observation) || ($observation['status'] ?? null) !== 'stopped_confirmed') {
            return false;
        }
        foreach (['node', 'pid', 'created_at', 'instance_id'] as $field) {
            if (!isset($observation[$field]) || (string) $observation[$field] !== (string) $this->ownership[$field]) {
                return false;
            }
        }
        return true;
    }
}
