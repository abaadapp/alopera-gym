<?php

namespace App\Core\Notifications;

use App\Core\Support\Money;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Documents\Models\BusinessDocument;
use App\Modules\Documents\Models\DocumentAlert;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Membership;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Services\ConversationAccess;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Website\Models\ContactMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What goes into the bell, and to whom — the events of the club's own screens, worded once. A user tied to a
 * branch hears only of their branch's documents, orders, payments and memberships (BranchContext::allows()).
 *
 * | event                                   | who                                   | handled when                     |
 * |-----------------------------------------|---------------------------------------|----------------------------------|
 * | a document alert (expiry threshold)     | documents.view, if they may see it    | acknowledged, or renewed         |
 * | a website order                         | orders.manage, of the order's branch  | prepared, delivered or cancelled |
 * | a payment waiting for confirmation      | payments.confirm                      | confirmed or rejected            |
 * | an online payment that failed           | invoices.view                         | —                                |
 * | online money left as credit (late, or   | payments.refund, of its branch        | the credit is applied or paid    |
 * | over what the invoice still owed)       |                                       | back in full                     |
 * | memberships ending within 7 days (daily)| memberships.view                      | —                                |
 * | an integration error (once a day)       | integrations.manage                   | the next successful check        |
 * | a contact-form message                  | website.manage                        | marked read                      |
 * | a WhatsApp message (once a day per chat)| whoever may see the chat               | the chat is opened               |
 * | a finance record not in the books       | accounting.view, of its branch        | it reaches the books (the sync)  |
 */
final class StaffAlerts
{
    public function __construct(private readonly StaffNotifier $notifier, private readonly TenantContext $tenants, private readonly BranchContext $branches) {}

    public function documentAlert(DocumentAlert $alert): void
    {
        $document = $alert->document ?? BusinessDocument::find($alert->document_id);
        if (! $document) {
            return;
        }
        $days = $document->daysLeft();
        $this->notifier->notify('documents.view', 'document-alert:'.$alert->id, 'document',
            $days === null || $days < 0 ? 'انتهى المستند «'.$document->name.'»' : 'المستند «'.$document->name.'» ينتهي '.($days === 0 ? 'اليوم' : 'خلال '.$days.' يوم'),
            route('club.business-documents.index', ['view' => $days !== null && $days < 0 ? 'expired' : 'expiring']),
            fn (User $u) => $document->isVisibleTo($u) && $this->branches->allows($u, $document->owningBranchId()));
    }

    public function order(Sale $sale): void
    {
        $this->notifier->notify('orders.manage', 'order:'.$sale->id, 'order',
            'طلب جديد من الموقع '.$sale->number.($sale->customer_name ? ' — '.$sale->customer_name : ''),
            route('club.sales.show', $sale),
            fn (User $u) => $this->branches->allows($u, $sale->owningBranchId()));
    }

    public function pendingPayment(Payment $payment): void
    {
        $payment->loadMissing('invoice:id,number,customer_name');
        $this->notifier->notify('payments.confirm', 'payment-pending:'.$payment->id, 'payment',
            'دفعة بانتظار التأكيد: '.Money::normalize($payment->amount).' '.$payment->currency.($payment->invoice?->customer_name ? ' — '.$payment->invoice->customer_name : ''),
            route('club.payments.index', ['status' => Payment::PENDING]),
            fn (User $u) => $this->branches->allows($u, $payment->owningBranchId()));
    }

    public function paymentFailed(PaymentIntent $intent): void
    {
        $intent->loadMissing('invoice:id,number,branch_id');
        if (! $intent->invoice) {
            return;
        }
        $this->notifier->notify('invoices.view', 'payment-failed:'.$intent->id, 'payment',
            'لم يكتمل دفع إلكتروني للفاتورة '.$intent->invoice->number,
            route('club.invoices.show', $intent->invoice_id),
            fn (User $u) => $this->branches->allows($u, $intent->invoice->owningBranchId()));
    }

    /**
     * Money a gateway confirmed that its invoice no longer took — a website order cancelled before Paymob's
     * confirmation came, an invoice voided or paid at the desk meanwhile — is the customer's credit. Someone who may
     * pay it back is told, once per payment (a repeated callback records no new payment).
     */
    public function gatewayCredit(Payment $payment): void
    {
        $payment->loadMissing('invoice:id,number,customer_name');
        $order = Sale::where('invoice_id', $payment->invoice_id)->where('source', Sale::WEBSITE)->where('status', Sale::CANCELLED)->value('number');
        $this->notifier->notify('payments.refund', 'payment-credit:'.$payment->id, 'payment',
            ($order ? 'دفعة إلكترونية وصلت بعد إلغاء الطلب '.$order : 'دفعة إلكترونية لم تُطبَّق على الفاتورة '.$payment->invoice?->number)
                .': '.Money::normalize($payment->credit_amount).' '.$payment->currency.' رصيد دائن للعميل'
                .($payment->invoice?->customer_name ? ' — '.$payment->invoice->customer_name : '').'. خصّصه لفاتورة أو رُدّه.',
            route('club.invoices.show', $payment->invoice_id),
            fn (User $u) => $this->branches->allows($u, $payment->owningBranchId()));
    }

    /** Once a day: how many memberships end within a week — for each recipient, in their own branch when tied to one. */
    public function membershipsExpiring(?CarbonImmutable $today = null): void
    {
        $today ??= CarbonImmutable::today();
        $counts = [];
        $count = function (?int $branch) use (&$counts, $today): int {
            return $counts[$branch ?? 0] ??= $this->branches->narrow(Membership::expiringWithin(7, $today), $branch)->count();
        };

        foreach ([null, ...User::ofTenant($this->tenants->require()->id)->whereNotNull('branch_id')->distinct()->pluck('branch_id')->all()] as $branch) {
            $n = $count($branch);
            if ($n === 0) {
                continue;
            }
            $this->notifier->notify('memberships.view', 'memberships-expiring:'.$today->toDateString().':'.($branch ?? 'all'), 'membership',
                $n === 1 ? 'عضوية واحدة تنتهي خلال 7 أيام' : $n.' عضويات تنتهي خلال 7 أيام',
                route('club.memberships.index', ['filter' => 'expiring']),
                fn (User $u) => ($u->branch_id ? (int) $u->branch_id : null) === $branch);
        }
    }

    public function integrationError(TenantIntegration $integration): void
    {
        $names = [TenantIntegration::PAYMOB => ['Paymob', 'club.integrations.paymob'], TenantIntegration::WHATSAPP => ['WhatsApp Business', 'club.integrations.whatsapp']];
        [$name, $route] = $names[$integration->provider] ?? [$integration->provider, 'club.integrations.index'];
        $this->notifier->notify('integrations.manage', 'integration-error:'.$integration->provider.':'.today()->toDateString(), 'integration',
            'خطأ في ربط '.$name.': '.$integration->last_error, route($route));
    }

    public function contact(ContactMessage $message): void
    {
        $this->notifier->notify('website.manage', 'contact:'.$message->id, 'contact',
            'رسالة جديدة من '.$message->name.' عبر نموذج التواصل', route('club.website.messages'));
    }

    public function inbox(Conversation $conversation): void
    {
        $who = $conversation->contact_name ?: ($conversation->member?->full_name ?? '…'.substr((string) $conversation->phone, -4));
        // Only whoever may see the chat: everyone with every chat, or the one it is assigned to.
        $this->notifier->notify(['whatsapp.view_all', 'whatsapp.view_assigned'], 'inbox:'.$conversation->id.':'.today()->toDateString(), 'inbox',
            'رسالة واتساب جديدة من '.$who, route('club.whatsapp.index', ['c' => $conversation->id]),
            fn ($user) => app(ConversationAccess::class)->canView($user, $conversation));
    }

    /**
     * An invoice, payment, credit note or expense whose posting failed: the record stands and the books miss it until
     * it is posted (accounting:sync, daily, retries). Said once per record, in the record's own club.
     */
    public function unposted(Model $record): void
    {
        [$type, $what] = match (true) {
            $record instanceof Invoice => ['invoice', 'الفاتورة '.$record->number],
            $record instanceof Payment => ['payment', 'الدفعة '.($record->receipt_number ?? '#'.$record->id)],
            $record instanceof CreditNote => ['credit_note', 'الإشعار الدائن '.$record->number],
            $record instanceof Expense => ['expense', 'المصروف '.$record->number],
            default => [null, null],
        };
        if (! $type) {
            return;
        }

        $send = fn () => $this->notifier->notify('accounting.view', 'ledger:'.$type.':'.$record->getKey(), 'ledger',
            'لم يُقيَّد '.$what.' في الدفاتر بعد، وتُعاد المحاولة في المزامنة الليلية',
            route('club.finance.journal'),
            fn (User $u) => $this->branches->allows($u, $record->branch_id ? (int) $record->branch_id : null));

        $this->tenants->id() === (int) $record->tenant_id ? $send() : $this->tenants->run(Tenant::findOrFail($record->tenant_id), $send);
    }

    /** The records the bell said were not in the books and that are now (the sync posted them, or a later save did). */
    public function settleLedger(): void
    {
        StaffNotification::query()->unread()->where('key', 'like', 'ledger:%')->distinct()->pluck('key')->each(function (string $key) {
            [, $type, $id] = explode(':', $key) + [null, null, null];
            if (JournalEntry::where(['source_type' => $type, 'source_id' => (int) $id])->exists()) {
                $this->notifier->resolve($key);
            }
        });
    }

    /**
     * Re-check what is still open and make sure it is in the bell (a repeat changes nothing): the safety net behind
     * the events, and the first fill after an upgrade. Read notifications older than 90 days are removed.
     */
    public function sync(): int
    {
        $before = StaffNotification::count();

        DocumentAlert::with('document')->whereNull('acknowledged_at')->whereHas('document', fn ($q) => $q->where('status', 'active'))
            ->each(fn (DocumentAlert $a) => $this->documentAlert($a));
        Sale::where('source', Sale::WEBSITE)->where('fulfillment', 'new')->whereNotIn('status', [Sale::CANCELLED, Sale::VOIDED])
            ->each(fn (Sale $s) => $this->order($s));
        Payment::with('invoice:id,number,customer_name')->where('status', Payment::PENDING)->each(fn (Payment $p) => $this->pendingPayment($p));
        $this->membershipsExpiring();

        StaffNotification::whereNotNull('read_at')->where('read_at', '<', now()->subDays(90))->delete();

        return max(0, StaffNotification::count() - $before);
    }
}
