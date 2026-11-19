<?php

namespace App\Modules\Payments\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\Phone;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\ManualInvoices;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentLink;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Payment links made from the Paymob page, over what is already there: each link is an ordinary club invoice issued on
 * credit (the books, its number, tax and journal are the invoice engine's), and its address is the invoice's secure pay
 * link (/pay/{token}). Its checkout and its money are OnlinePaymentService's: a payment counts only from a signed,
 * matching callback, once. Paymob QuickLink and its API key are not used.
 */
final class PaymentLinks
{
    public function __construct(
        private readonly ManualInvoices $invoices,
        private readonly InvoiceService $engine,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Issue the link's invoice for exactly the amount asked (tax included when the club charges it) and the link.
     *
     * @param  array{amount: string|float, description: string, customer_name?: string|null, customer_phone?: string|null, valid_days: int|string}  $data
     */
    public function create(array $data): PaymentLink
    {
        $amount = Money::normalize($data['amount']);
        $rate = (float) $this->tenants->require()->setting('vat_rate', 0);
        $net = self::netFor($amount, $rate);
        $name = trim((string) ($data['customer_name'] ?? ''));
        $phone = filled($data['customer_phone'] ?? null) ? Phone::normalize($data['customer_phone']) : null;
        $description = trim($data['description']);

        return DB::transaction(function () use ($amount, $net, $name, $phone, $description, $data) {
            $invoice = $this->invoices->issue([
                'customer_mode' => $name !== '' ? 'new' : 'walk_in',
                'customer_name' => $name !== '' ? $name : null,
                'customer_phone' => $phone,
                'issue_date' => today()->toDateString(),
                'due_date' => today()->toDateString(),
                'payment_terms_days' => 0,
                'lines' => [['description' => $description, 'quantity' => 1, 'unit_price' => $net]],
            ], ['method' => 'credit', 'amount' => null, 'paid_at' => null, 'reference' => null, 'proof' => null]);

            // What the customer is asked is what the staff typed, to the baisa, or nothing is issued.
            if (Money::cmp($invoice->total, $amount) !== 0) {
                throw new BusinessRuleException('payment_link.amount', 'تعذّر إصدار الرابط بهذا المبلغ مع الضريبة. جرّب مبلغًا يزيد أو ينقص بيسة واحدة.', 'amount');
            }

            $link = PaymentLink::create([
                'invoice_id' => $invoice->id,
                'description' => $description,
                'created_by' => Auth::id(),
                'expires_at' => now()->addDays((int) $data['valid_days']),
            ]);
            $this->audit->log('payment_link.created', $invoice, ['link' => $link->id, 'amount' => $amount, 'expires_at' => $link->expires_at->toIso8601String()]);

            return $link;
        });
    }

    /**
     * Cancel a link nothing was paid on, with its invoice (voided, as the invoice screen voids). Refused while a payment
     * page opened from it may still take money: cancelling then could leave the customer's payment with nothing to pay.
     */
    public function cancel(PaymentLink $link): PaymentLink
    {
        return DB::transaction(function () use ($link) {
            $invoice = Invoice::whereKey($link->invoice_id)->lockForUpdate()->firstOrFail();
            $link = PaymentLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            if ($link->cancelled_at !== null) {
                return $link;
            }
            if ($invoice->status === Invoice::PAID || ! Money::isZero($invoice->paid_total)) {
                throw new BusinessRuleException('payment_link.paid', 'دُفع على هذا الرابط، فلا يُلغى. للرد استخدم الإشعار الدائن أو رد المبلغ من الفاتورة.');
            }
            $open = PaymentIntent::where('invoice_id', $invoice->id)
                ->where(fn ($q) => $q->where('status', PaymentIntent::PENDING)
                    ->orWhere(fn ($q) => $q->where('status', PaymentIntent::FAILED)->whereNotNull('checkout_url')))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->subMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES)))
                ->orderByDesc('expires_at')->first();
            if ($open) {
                $after = $open->expires_at?->copy()->addMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES)->timezone((string) config('app.timezone'));
                throw new BusinessRuleException('payment_link.page_open', 'فتح العميل صفحة الدفع من هذا الرابط وقد يدفع منها'
                    .($after ? ' حتى الساعة '.$after->format('H:i') : '').'. ألغِ الرابط بعد ذلك حتى لا تصل دفعة لرابط ملغى.');
            }

            if ($invoice->status !== Invoice::VOID) {
                $this->engine->void($invoice, 'إلغاء رابط الدفع');
            }
            $link->forceFill(['cancelled_at' => now(), 'cancelled_by' => Auth::id()])->save();
            $this->audit->log('payment_link.cancelled', $invoice, ['link' => $link->id]);

            return $link;
        });
    }

    /** The link the customer opens: the invoice's secure pay page. */
    public function url(PaymentLink $link): string
    {
        return route('site.pay.show', ['club' => $this->tenants->require()->slug, 'token' => $link->invoice->public_token]);
    }

    /** WhatsApp on the staff's own phone, the message ready (to the customer's number when there is one). Nothing is sent by the system. */
    public function whatsapp(PaymentLink $link): string
    {
        $invoice = $link->invoice;
        $club = $this->tenants->require()->name;
        $hello = filled($invoice->customer_name) && $invoice->billable_id === null && $invoice->customer_name !== 'عميل نقدي' ? 'مرحبًا '.$invoice->customer_name.'، ' : 'مرحبًا، ';
        $text = $hello.'هذا رابط دفع من '.$club.' لـ«'.$link->description.'» بمبلغ '.$invoice->total.' '.$invoice->currency.":\n".$this->url($link);
        $phone = $invoice->customer_phone ? Phone::normalize($invoice->customer_phone) : '';

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($text);
    }

    /**
     * The line price (before tax) whose invoice total, with the club's tax worked out as the invoice engine works it out
     * (whole baisa, rounded), is exactly $amount. Null when no price gives it (a rounding gap of one baisa).
     */
    public static function netFor(string $amount, float $rate): string
    {
        $total = Money::toMinor($amount);
        if ($rate <= 0) {
            return $amount;
        }
        $guess = (int) round($total * 100 / (100 + $rate));
        foreach ([0, -1, 1, -2, 2] as $step) {
            $net = $guess + $step;
            if ($net > 0 && $net + (int) round($net * $rate / 100) === $total) {
                return Money::fromMinor($net);
            }
        }

        return Money::fromMinor($guess);
    }
}
