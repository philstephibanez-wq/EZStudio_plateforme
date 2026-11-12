<?php

declare(strict_types=1);

namespace Ezs\Web\Module\Coordination;

/** Validates the immutable technical admission envelope; no scientific payload. */
final class QueueAdmission
{
    public static function parse(array $input): array
    {
        $required = ['external_id', 'idempotency_key', 'kind', 'resource_class', 'priority'];
        $keys = array_keys($input);
        sort($keys);
        $expected = $required;
        sort($expected);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException('invalid_admission_fields');
        }
        foreach (['external_id', 'idempotency_key', 'kind', 'resource_class'] as $field) {
            if (!is_string($input[$field]) || $input[$field] === '') {
                throw new \InvalidArgumentException('invalid_admission_field_'.$field);
            }
        }
        if (!is_int($input['priority'])) {
            throw new \InvalidArgumentException('invalid_admission_priority');
        }
        if (!in_array($input['resource_class'], ['cpu', 'gpu'], true)) {
            throw new \InvalidArgumentException('invalid_resource_class');
        }
        if ($input['priority'] < 0 || $input['priority'] > 100) {
            throw new \InvalidArgumentException('invalid_admission_priority');
        }
        foreach (['external_id', 'idempotency_key', 'kind'] as $field) {
            $s = $input[$field];
            if (strlen($s) > 128 || preg_match('/[\x00-\x1f\x7f]/', $s)) {
                throw new \InvalidArgumentException('invalid_admission_field_'.$field);
            }
        }
        return $input;
    }

    public static function publicRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'producer' => (string)$row['producer'],
            'external_id' => (string)$row['external_id'],
            'kind' => (string)$row['kind'],
            'resource_class' => (string)$row['resource_class'],
            'priority' => (int)$row['priority'],
            'state' => (string)$row['state'],
        ];
    }
}
