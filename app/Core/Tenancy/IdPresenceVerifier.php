<?php

namespace App\Core\Tenancy;

use Illuminate\Validation\DatabasePresenceVerifier;

/**
 * `exists` on an id column (TenantRule::exists, Rule::exists('users')): a value that is not a whole positive number
 * that fits the column is a record that does not exist — refused as invalid, never sent to PostgreSQL, which rejects
 * it with an error (a page that breaks instead of a field marked wrong).
 */
final class IdPresenceVerifier extends DatabasePresenceVerifier
{
    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = [])
    {
        if ($column === 'id' && ! self::isId($value)) {
            return 0;
        }

        return parent::getCount($collection, $column, $value, $excludeId, $idColumn, $extra);
    }

    public function getMultiCount($collection, $column, array $values, array $extra = [])
    {
        if ($column === 'id') {
            $values = array_values(array_filter($values, self::isId(...)));
            if ($values === []) {
                return 0;
            }
        }

        return parent::getMultiCount($collection, $column, $values, $extra);
    }

    /** At most 18 digits: well inside bigint, far beyond any row. */
    private static function isId(mixed $value): bool
    {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1);
    }
}
