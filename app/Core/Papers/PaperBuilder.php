<?php

namespace App\Core\Papers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns one kind of record into what its paper prints. Each document type has one builder, used by its screen, its
 * print page, its PDF and the template editor's preview alike.
 */
abstract class PaperBuilder
{
    /** The record, within the current club (its tenant scope); null when there is none. */
    abstract public static function find(int $id): ?Model;

    /** May this user open this record's paper? Checked on the server for every format. */
    abstract public static function allows(User $user, Model $record): bool;

    /**
     * What the paper prints (see papers/sheet.blade.php). Optional parts follow $tpl; required ones are always set.
     *
     * @param  array<string, mixed>  $tpl
     * @return array<string, mixed>
     */
    abstract public static function document(Model $record, array $tpl, Tenant $tenant): array;

    /** A made-up document for the template preview, used when the club has none yet (or the editor may not see one). */
    abstract public static function sample(Tenant $tenant, array $tpl): array;

    abstract public static function filename(Model $record): string;

    /** The club identity to print; a document stamped when issued overrides this. */
    public static function issuer(Model $record, Tenant $tenant): array
    {
        return PaperIssuer::resolve($tenant);
    }

    /** A real recent record to preview the template with, when this user may see it. */
    public static function latest(User $user): ?Model
    {
        return null;
    }

    /** @return list<array{label: string, value: string, ltr?: bool}> */
    protected static function fields(array $fields): array
    {
        return array_values(array_filter($fields, fn ($f) => $f !== null && filled($f['value'] ?? null)));
    }

    protected static function currency(Tenant $tenant, ?string $currency = null): string
    {
        return $currency ?: ($tenant->currency ?: 'OMR');
    }
}
