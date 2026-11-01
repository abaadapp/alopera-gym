<?php

namespace App\Modules\Accounting\Rules;

use App\Modules\Accounting\Services\AccountingPeriods;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A date a form posts to the books with: not inside a closed accounting period. The ledger refuses such a date anyway;
 * this says so on the field, before anything is saved.
 */
final class OpenPeriod implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $date = rescue(fn () => CarbonImmutable::parse((string) $value), null, false);
        $periods = app(AccountingPeriods::class);
        if ($date && $periods->isLocked($date)) {
            $fail($periods->closedMessage());
        }
    }
}
