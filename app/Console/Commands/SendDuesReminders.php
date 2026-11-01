<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Services\MemberNotifier;
use App\Modules\Messaging\Services\TemplateCatalog;
use Carbon\CarbonImmutable;

/**
 * Payment reminders over WhatsApp for members' open invoices: some days before they are due and some days after, as
 * the club set them, each stage once per invoice and no more than the club's maximum per invoice. Off until the
 * owner turns it on; nothing is sent while WhatsApp is not connected, the template not approved by Meta, or the
 * member has not agreed to service messages (WhatsAppService decides and records why).
 */
class SendDuesReminders extends ForEachTenant
{
    protected $signature = 'finance:remind-dues {--tenant=}';

    protected $description = 'Queue WhatsApp reminders for members\' invoices due soon or overdue';

    public const TEMPLATES = ['invoice_due_soon', 'invoice_overdue'];

    protected function handleTenant(Tenant $tenant): string
    {
        if (! $tenant->setting('dues_reminders_enabled', false)) {
            return 'dues_reminders=off';
        }
        // The reminder templates exist as drafts to submit for approval; a draft is never sent.
        TemplateCatalog::seedDefaults();

        $notifier = app(MemberNotifier::class);
        $today = CarbonImmutable::today();
        $max = max(1, (int) $tenant->setting('dues_reminder_max', 3));
        $queued = 0;
        $stages = [
            'before' => array_map(fn ($d) => $today->addDays((int) $d), (array) $tenant->setting('dues_reminder_before_days', [])),
            'overdue' => array_map(fn ($d) => $today->subDays((int) $d), (array) $tenant->setting('dues_reminder_overdue_days', [])),
        ];

        foreach ($stages as $stage => $dates) {
            foreach ($dates as $due) {
                Invoice::open()->where('billable_type', 'member')->whereDate('due_date', $due)->with('billable')->each(function (Invoice $invoice) use ($notifier, $stage, $due, $today, $max, &$queued) {
                    if (self::sentFor($invoice) >= $max) {
                        return;
                    }
                    if ($notifier->duesReminder($invoice, $stage, (int) abs($due->diffInDays($today)))?->status === Message::QUEUED) {
                        $queued++;
                    }
                });
            }
        }

        return "dues_reminders_queued={$queued}";
    }

    /** Reminders that went out (or are going) for an invoice: skipped ones are not counted. */
    public static function sentFor(Invoice $invoice): int
    {
        return Message::where(['related_type' => $invoice->getMorphClass(), 'related_id' => $invoice->id])
            ->whereIn('template_key', self::TEMPLATES)->where('status', '!=', Message::SKIPPED)->count();
    }
}
