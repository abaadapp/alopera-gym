<?php

namespace App\Modules\Accounting;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Accounting\Console\SyncLedger;
use App\Modules\Accounting\Listeners\PostToLedger;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;

class AccountingServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        // Per request / job: it caches the club's system accounts.
        $this->app->scoped(ChartOfAccounts::class);
    }

    protected function bootModule(): void
    {
        foreach ([Invoice::class, Payment::class, CreditNote::class, Expense::class] as $model) {
            $model::observe(PostToLedger::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SyncLedger::class]);
        }
    }
}
