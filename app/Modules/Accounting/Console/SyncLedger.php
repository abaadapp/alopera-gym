<?php

namespace App\Modules\Accounting\Console;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Accounting\Services\LedgerProjector;
use Illuminate\Console\Command;

/**
 * Builds each club's books from its finance records, and catches up anything the live posting missed. Safe to
 * run again and again: a record already in the books is not posted twice, and no record is changed.
 */
class SyncLedger extends Command
{
    protected $signature = 'accounting:sync {--club= : only this club (slug)}';

    protected $description = 'Post invoices, payments, credit notes and expenses that are not in the books yet';

    public function handle(TenantContext $tenants, LedgerProjector $projector): int
    {
        $clubs = Tenant::query()->when($this->option('club'), fn ($q, $slug) => $q->where('slug', $slug))->orderBy('id')->get();

        foreach ($clubs as $club) {
            $counts = $tenants->run($club, fn () => $projector->syncClub());
            $this->line(sprintf('%s: %d invoices, %d payments, %d credit notes, %d expenses checked — %d entries added', $club->slug, $counts['invoices'], $counts['payments'], $counts['credit_notes'], $counts['expenses'], $counts['entries_added']));
        }

        return self::SUCCESS;
    }
}
