<?php

namespace App\Core\Support;

use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One form, one result: a form sends a key it made when it opened (`client_key`), and a double click, a retried request
 * or the same form sent twice gets the first submission's result instead of a second invoice or payment. Requests with
 * the same key wait for each other; only a result is kept (a refused submission can be corrected and sent again).
 * Without a key the work simply runs.
 *
 * The key is kept in the database, in the work's own transaction (submitted_once_keys): a second request with the same
 * key waits on the first one's row and then gets its result, or runs if the first was refused. Nothing depends on the
 * cache, so clearing it, a lock outliving its time, or a worker stopped after the commit cannot record the work twice.
 */
final class SubmittedOnce
{
    /** What a request carries: letters, digits and dashes, as crypto.randomUUID() makes them. */
    public const RULE = ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'];

    /**
     * @template T
     *
     * @param  Closure(): T  $work  returns what JSON keeps as it is (ids, strings, booleans and lists of them, not models)
     * @return T
     */
    public static function run(?string $key, string $scope, Closure $work): mixed
    {
        if (! $key) {
            return $work();
        }

        $tenantId = app(TenantContext::class)->require()->id;
        $scope = Str::limit($scope, 120, '');

        return DB::transaction(function () use ($key, $scope, $work, $tenantId) {
            // Waits for a request holding the same key until it commits (nothing inserted) or rolls back (inserted).
            $mine = SubmittedOnceKey::query()->insertOrIgnore([
                'tenant_id' => $tenantId, 'scope' => $scope, 'client_key' => $key, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = fn () => SubmittedOnceKey::where('scope', $scope)->where('client_key', $key);
            if ($mine === 0) {
                return $row()->sole()->result;
            }

            $result = $work();
            $row()->update(['result' => json_encode($result)]);

            return $result;
        });
    }
}
