<?php

namespace App\Modules\Accounting\Services;

use App\Core\Tenancy\TenantContext;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which of the club's cash boxes and bank accounts have had their opening balance set by someone: an opening posted,
 * or one set on purpose to zero (on record in the audit log). Read only: nothing is ever posted or made up here.
 */
class OpeningBalances
{
    /** @return Collection<int, Account> the open cash boxes and bank accounts nobody has set an opening for */
    public function unreviewed(): Collection
    {
        return Account::money()->postable()->whereIn('kind', Account::PAYING_KINDS)->whereNotIn('id', $this->reviewed())->orderBy('code')->get();
    }

    /** @return list<int> */
    public function reviewed(): array
    {
        $alias = array_search(Account::class, Relation::morphMap(), true) ?: class_basename(Account::class);

        return JournalEntry::where(['source_type' => 'account', 'source_event' => 'opening'])->pluck('source_id')
            ->merge(DB::table('audit_logs')->where(['tenant_id' => app(TenantContext::class)->require()->id, 'event' => 'accounting.opening_balance_set', 'subject_type' => $alias])->pluck('subject_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
