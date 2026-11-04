<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Console\Commands\SendDuesReminders;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Services\MemberNotifier;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Messaging\Services\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Outstanding balances and arrears with aging (spec 1.5), and the payment reminders: whether they are on, the log of
 * every reminder with its status, and a reminder sent by hand (once a day per invoice).
 */
class DuesController extends Controller
{
    /** Why a reminder was not sent, in words. */
    private const SKIPS = Message::SKIP_REASONS;

    public function __invoke(Request $request, TenantContext $tenants): Response
    {
        $open = BranchContext::scope(Invoice::open())->orderBy('due_date')->get();
        $today = today();

        $aging = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '60_plus' => 0.0];
        foreach ($open as $invoice) {
            $days = $invoice->due_date && $invoice->due_date->lt($today) ? (int) $invoice->due_date->diffInDays($today) : 0;
            $bucket = match (true) {
                $days === 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                default => '60_plus',
            };
            $aging[$bucket] += (float) $invoice->balance();
        }

        return Inertia::render('Finance/Dues', [
            'invoices' => $open->when($request->boolean('overdue'), fn ($c) => $c->filter(fn ($i) => $i->due_date && $i->due_date->lt($today)))
                ->take(200)->values()->map(fn (Invoice $i) => InvoiceController::row($i) + [
                    'days_overdue' => $i->due_date && $i->due_date->lt($today) ? (int) $i->due_date->diffInDays($today) : 0,
                ]),
            'aging' => array_map(fn ($v) => round($v, 3), $aging),
            'total' => round(array_sum($aging), 3),
            'filters' => ['overdue' => $request->boolean('overdue')],
            'reminders' => [
                'enabled' => (bool) $tenants->require()->setting('dues_reminders_enabled', false),
                'whatsapp' => app(WhatsAppService::class)->enabled(),
                'log' => Message::with('member:id,full_name')->whereIn('template_key', SendDuesReminders::TEMPLATES)->where('related_type', 'invoice')->latest('id')->limit(30)->get()
                    ->map(fn (Message $m) => [
                        'id' => $m->id, 'at' => $m->created_at->format('Y-m-d H:i'), 'member' => $m->member?->full_name, 'invoice_id' => $m->related_id,
                        'invoice' => Invoice::whereKey($m->related_id)->value('number'), 'kind' => $m->template_key === 'invoice_overdue' ? 'متأخرة' : 'قبل الاستحقاق',
                        'status' => $m->status, 'reason' => self::SKIPS[$m->error_code] ?? $m->error_code, 'by_hand' => str_contains((string) $m->dedupe_key, ':hand:'),
                    ]),
            ],
        ]);
    }

    /** A reminder of one member's open invoice, sent by hand: once a day, through the same rules as the automatic ones. */
    public function remind(Invoice $invoice, MemberNotifier $notifier, WhatsAppService $whatsapp): RedirectResponse
    {
        if (! $whatsapp->enabled()) {
            return back()->with('error', 'واتساب غير مربوط بعد؛ لم يُرسل شيء. اربطه من «التطبيقات التكاملية».');
        }
        if ($invoice->billable_type !== 'member') {
            return back()->with('error', 'التذكير يُرسل لفواتير الأعضاء فقط.');
        }
        TemplateCatalog::seedDefaults();
        $overdue = $invoice->due_date !== null && $invoice->due_date->lt(today());
        $message = $notifier->duesReminder($invoice, $overdue ? 'overdue' : 'before', $invoice->due_date ? (int) abs($invoice->due_date->diffInDays(today())) : 0, byHand: true);

        return match (true) {
            $message === null => back()->with('error', 'أُرسل تذكير لهذه الفاتورة اليوم، أو أنها لم تعد مستحقة.'),
            $message->status === Message::SKIPPED => back()->with('error', 'لم يُرسل التذكير: '.(self::SKIPS[$message->error_code] ?? $message->error_code).'.'),
            default => back()->with('success', 'أُضيف التذكير إلى قائمة الإرسال. تتابع حالته في سجل التذكيرات.'),
        };
    }
}
