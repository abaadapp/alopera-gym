<?php

namespace App\Core\Support;

use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * One form, one result: a form sends a key it made when it opened (`client_key`), and a double click, a retried request
 * or the same form sent twice gets the first submission's result instead of a second invoice or payment. Requests with
 * the same key wait for each other; only a result is kept (a refused submission can be corrected and sent again).
 * Without a key the work simply runs.
 */
final class SubmittedOnce
{
    /** What a request carries: letters, digits and dashes, as crypto.randomUUID() makes them. */
    public const RULE = ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'];

    /**
     * @template T
     *
     * @param  Closure(): T  $work  returns something the cache can keep (ids and strings, not models)
     * @return T
     */
    public static function run(?string $key, string $scope, Closure $work): mixed
    {
        if (! $key) {
            return $work();
        }

        $cacheKey = 'once:'.$scope.':'.app(TenantContext::class)->id().':'.$key;
        $done = Cache::get($cacheKey);
        if ($done !== null) {
            return $done;
        }

        return Cache::lock($cacheKey.':lock', 30)->block(15, function () use ($cacheKey, $work) {
            $done = Cache::get($cacheKey);
            if ($done !== null) {
                return $done;
            }
            $result = $work();
            Cache::put($cacheKey, $result, now()->addDay());

            return $result;
        });
    }
}
