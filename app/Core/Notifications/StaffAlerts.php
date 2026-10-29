<?php

namespace App\Core\Notifications;

use App\Core\Support\Money;
use App\Core\Tenancy\TenantContext;
use App\Models\StaffNotification;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Documents\Models\BusinessDocument;
use App\Modules\Documents\Models\DocumentAlert;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Membership;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Website\Models\ContactMessage;
use Carbon\CarbonImmutable;

/**
 * What goes into the bell, and to whom — the events of the club's own screens, worded once:
 *
 * | event                                   | who                                   | handled when                     |
 * |-----------------------------------------|---------------------------------------|----------------------------------|
 * | a document alert (expiry threshold)     | documents.view, if they may see it    | acknowledged, or renewed         |
 * | a website order                         | orders.manage, of the order's branch  | prepared, delivered or cancelled |
 * | a payment waiting for confirmation      | payments.confirm                      | confirmed or rejected            |
 * | an online payment that failed           | invoices.view                         | —                                |
 * | memberships ending within 7 days (daily)| memberships.view                      | —                                |
 * | an integration error (once a day)       | integrations.manage                   | the next successful check        |
 * | a contact-form message                  | website.manage                        | marked read                      |
 * | a WhatsApp message (once a day per chat)| whatsapp.inbox                        | the chat is opened               |
 */
final class StaffAlerts
{
    public function __construct(private readonly StaffNotifier $notifier, private readonly TenantContext $tenants) {}

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
            fn (User $u) => $document->isVisibleTo($u));
    }

    public function order(Sale $sale): void
    {
        $this->notifier->notify('orders.manage', 'order:'.$sale->id, 'order',
            'طلب جديد من الموقع '.$sale->number.($sale->customer_name ? ' — '.$sale->customer_name : ''),
            route('club.sales.orders.index'),
            fn (User $u) => ! $u->branch_id || ! $sale->branch_id || (int) $u->branch_id === (int) $sale->branch_id);
    }

    public function pendingPayment(Payment $payment): void
    {
        $payment->loadMissing('invoice:id,number,customer_name');
        $this->notifier->notify('payments.confirm', 'payment-pending:'.$payment->id, 'payment',
            'دفعة بانتظار التأكيد: '.Money::normalize($payment->amount).' '.$payment->currency.($payment->invoice?->customer_name ? ' — '.$payment->invoice->customer_name : ''),
            route('club.payments.index', ['status' => Payment::PENDING]));
    }

    public function paymentFailed(PaymentIntent $intent): void
    {
        $intent->loadMissing('invoice:id,number');
        if (! $intent->invoice) {
            return;
        }
        $this->notifier->notify('invoices.view', 'payment-failed:'.$intent->id, 'payment',
            'لم يكتمل دفع إلكتروني للفاتورة '.$intent->invoice->number,
            route('club.invoices.show', $intent->invoice_id));
    }

    /** Once a day: how many memberships end within a week — for each recipient, in their own branch when tied to one. */
    public function membershipsExpiring(?CarbonImmutable $today = null): void
    {
        $today ??= CarbonImmutable::today();
        $counts = [];
        $count = function (?int $branch) use (&$counts, $today): int {
            return $counts[$branch ?? 0] ??= Membership::expiringWithin(7, $today)
                ->when($branch, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branch)->orWhereNull('branch_id')))->count();
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
        $this->notifier->notify('whatsapp.inbox', 'inbox:'.$conversation->id.':'.today()->toDateString(), 'inbox',
            'رسالة واتساب جديدة من '.$who, route('club.inbox.show', $conversation));
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
