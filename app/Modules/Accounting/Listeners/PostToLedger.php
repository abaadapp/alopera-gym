<?php

namespace App\Modules\Accounting\Listeners;

use App\Core\Notifications\StaffAlerts;
use App\Modules\Accounting\Services\LedgerProjector;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Observes invoices, payments, credit notes and expenses and posts them to the books once the business
 * transaction has committed. A posting problem is reported, never thrown back at the cashier: the invoice or
 * payment stands, whoever keeps the books hears of it in the bell, and accounting:sync (daily) posts whatever is missing.
 */
class PostToLedger implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly LedgerProjector $projector, private readonly StaffAlerts $alerts) {}

    public function saved(Model $record): void
    {
        $this->post($record);
    }

    public function deleted(Model $record): void
    {
        $this->post($record, deleted: true);
    }

    public function restored(Model $record): void
    {
        $this->post($record);
    }

    private function post(Model $record, bool $deleted = false): void
    {
        try {
            $this->projector->project($record, $deleted);
        } catch (Throwable $e) {
            report($e);
            rescue(fn () => $this->alerts->unposted($record));
        }
    }
}
