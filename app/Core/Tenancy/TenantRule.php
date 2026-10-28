<?php

namespace App\Core\Tenancy;

use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Rule;

/**
 * Validation rules that only see the current tenant's rows.
 *
 * A plain `exists:members,id` accepts another club's member id; these do not.
 */
final class TenantRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('tenant_id', app(TenantContext::class)->require()->id);
    }

    public static function unique(string $table, string $column = 'NULL'): Unique
    {
        return Rule::unique($table, $column)->where('tenant_id', app(TenantContext::class)->require()->id);
    }
}
