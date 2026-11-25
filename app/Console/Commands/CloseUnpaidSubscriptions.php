<?php

namespace App\Console\Commands;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\HomeGym;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\PtSubscription;
use Illuminate\Console\Command;

/**
 * Once, after «a membership or a training package is sold only when it is paid» (owner's decision, 2026-11-25): the
 * sales made before it that are still «بانتظار الدفع». Each one nothing was paid on has its invoice voided, which
 * withdraws the waiting membership or package (ActivatePaidItems::handleVoided) — as if it had never been sold. One
 * with money on it (or a transfer awaiting confirmation, a Paymob review) is only listed: finance settles it by hand.
 *
 * Lists by default; --apply does it.
 */
class CloseUnpaidSubscriptions extends Command
{
    protected $signature = 'gym:close-unpaid-subscriptions {--apply : void the unpaid ones (without it, only list them)}';

    protected $description = 'Withdraw memberships and training packages still waiting for payment, voiding their unpaid invoices';

    public function handle(TenantContext $tenants, InvoiceService $invoices): int
    {
        $apply = (bool) $this->option('apply');
        foreach (Tenant::query()->when(HomeGym::id(), fn ($q, $home) => $q->whereKey($home))->orderBy('id')->get() as $club) {
            $tenants->run($club, function () use ($club, $apply, $invoices) {
                $ids = Membership::where('status', Membership::PENDING)->whereNotNull('invoice_id')->pluck('invoice_id')
                    ->merge(PtSubscription::where('status', PtSubscription::PENDING)->whereNotNull('invoice_id')->pluck('invoice_id'))->unique();
                foreach (Invoice::whereIn('id', $ids)->orderBy('id')->get() as $invoice) {
                    $line = "{$club->slug}: {$invoice->number} — {$invoice->customer_name} — {$invoice->total} {$invoice->currency}";
                    if (! $apply) {
                        $this->line($line.' (بانتظار الدفع)');

                        continue;
                    }
                    try {
                        $invoices->void($invoice, 'سحب البيع غير المدفوع: الاشتراكات تُباع بعد الدفع فقط');
                        $this->info($line.' — أُلغيت الفاتورة وسُحب الاشتراك');
                    } catch (BusinessRuleException $e) {
                        $this->warn($line.' — لم تُلغَ: '.$e->getMessage());
                    }
                }
            });
        }
        if (! $apply) {
            $this->comment('للتنفيذ: أعد الأمر مع --apply');
        }

        return self::SUCCESS;
    }
}
