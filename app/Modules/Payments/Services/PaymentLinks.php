<?php

namespace App\Modules\Payments\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\Phone;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\ManualInvoices;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentReview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payment links over what is already there: a link belongs to an ordinary club invoice (the books, its number, tax and
 * journal are the invoice engine's) — one issued with it from the Paymob page or the editor, or any invoice already
 * issued. Each link has its own secure address (/pay/link/{token}); its checkout and its money are the invoice's
 * (OnlinePaymentService): a payment counts only from a signed, matching callback, once, and asks what the invoice owes
 * when the page is opened. Paymob QuickLink and its API key are not used.
 *
 * Owner's decisions (2026-11-20): one live link per invoice; a new one once the one before is over or cancelled, while
 * the payment allows it; an ended link opens nothing; every link is kept; ending a link never touches its invoice.
 */
final class PaymentLinks
{
    /** What staff are told when a link is not cancelled or replaced because of an open Paymob review on its invoice. */
    public const REVIEW_REFUSED = 'هناك دفعة إلكترونية من Paymob قيد المراجعة على هذه الفاتورة، فلا يُلغى رابط الدفع ولا يُنشأ رابط بدله حتى تُغلق المراجعة بدليل. الفاتورة نفسها لا تتغير.';

    public function __construct(
        private readonly ManualInvoices $invoices,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
        private readonly OnlinePaymentService $online,
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
        $days = (int) $data['valid_days'];
        $expires = now()->addDays($days);

        return DB::transaction(function () use ($amount, $net, $name, $phone, $description, $days, $expires) {
            $invoice = $this->invoices->issue([
                'customer_mode' => $name !== '' ? 'new' : 'walk_in',
                'customer_name' => $name !== '' ? $name : null,
                'customer_phone' => $phone,
                'issue_date' => today()->toDateString(),
                // Owner's decision (2026-11-19), for link invoices only: due the day the link ends, not the day it is made.
                'due_date' => $expires->toDateString(),
                'payment_terms_days' => $days,
                'lines' => [['description' => $description, 'quantity' => 1, 'unit_price' => $net]],
            ], ['method' => 'credit', 'amount' => null, 'paid_at' => null, 'reference' => null, 'proof' => null]);

            // What the customer is asked is what the staff typed, to the baisa, or nothing is issued.
            if (Money::cmp($invoice->total, $amount) !== 0) {
                throw new BusinessRuleException('payment_link.amount', 'تعذّر إصدار الرابط بهذا المبلغ مع الضريبة. جرّب مبلغًا يزيد أو ينقص بيسة واحدة.', 'amount');
            }

            // As from the Paymob page before (#213): made while online payments are off too (the page says they are).
            return $this->open($invoice, $days, $description, expires: $expires, whileOff: true);
        });
    }

    /**
     * A new link for an issued invoice, for what it owes when the customer opens it. Refused, with why: nothing owed (or
     * a single baisa, which a card may round), online payments off, a live link still running (cancel it first), or money
     * of the invoice not settled. Once the live link's time is over, the new one replaces it (owner's decision): like a
     * cancel, only once nothing can still come through its pages — see end(). Under the invoice's lock, as the checkout
     * and the callback take it: two requests at once make one link (and the partial unique index stops a second).
     */
    public function open(Invoice $invoice, int $days, ?string $description = null, bool $paymobChecked = false, ?Carbon $expires = null, bool $whileOff = false): PaymentLink
    {
        if (! in_array($days, PaymentLink::VALID_DAYS, true)) {
            throw new BusinessRuleException('payment_link.days', 'اختر مدة صلاحية الرابط.', 'valid_days');
        }

        return DB::transaction(function () use ($invoice, $days, $description, $paymobChecked, $expires, $whileOff) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! $invoice->isOpen() || Money::isZero($invoice->balance())) {
                throw new BusinessRuleException('payment_link.nothing_owed', 'لا يوجد مبلغ مستحق على هذه الفاتورة، فلا يُنشأ لها رابط دفع.');
            }
            if (OnlinePaymentService::singleBaisa($invoice->balance())) {
                throw new BusinessRuleException('payment_link.single_baisa', OnlinePaymentService::singleBaisaMessage($invoice->balance()));
            }
            if (! $whileOff && ! $this->online->available()) {
                throw new BusinessRuleException('payment_link.off', 'الدفع الإلكتروني غير مفعّل، فلا يفتح رابط الدفع صفحة دفع. فعّل Paymob من الإعدادات ← الربط أولًا.');
            }
            $live = PaymentLink::where('invoice_id', $invoice->id)->live()->lockForUpdate()->latest('id')->first();
            if ($live && $live->expires_at->isFuture()) {
                throw new BusinessRuleException('payment_link.live', 'لهذه الفاتورة رابط دفع نشط حتى '.$live->expires_at->timezone((string) config('app.timezone'))->format('Y-m-d H:i')
                    .'. انسخه أو أرسله، أو ألغه أولًا لإنشاء رابط جديد.');
            }
            // Money of the invoice not settled stops a new link; a page still open stops it only when it ends a link (a
            // page opened from the invoice's own address is reused by the new link, or refused: never a second one).
            self::refuseWhileMoneyIsUnsettled($invoice, pages: $live !== null);
            if ($live) {
                $this->end($invoice, $live, 'replaced', $paymobChecked);
            }

            $link = PaymentLink::create([
                'invoice_id' => $invoice->id,
                'token' => Str::random(48),
                'description' => filled($description) ? trim((string) $description) : 'فاتورة '.$invoice->number,
                'created_by' => Auth::id(),
                'expires_at' => $expires ?? now()->addDays($days),
            ]);
            $this->audit->log('payment_link.created', $invoice, ['link' => $link->id, 'amount' => $invoice->balance(), 'expires_at' => $link->expires_at->toIso8601String()]
                + ($live ? ['replaces' => $live->id] : []));

            return $link;
        });
    }

    /**
     * Cancel a link: it opens nothing any more. Refused once anything was paid on its invoice (a part too: owner's
     * decision), and while money of it is not settled or a page of it may still take money (refuseWhileMoneyIsUnsettled),
     * and, for pages Paymob never answered, until the staff say they checked them at Paymob (end()).
     */
    public function cancel(PaymentLink $link, bool $paymobChecked = false): PaymentLink
    {
        return DB::transaction(function () use ($link, $paymobChecked) {
            $invoice = Invoice::whereKey($link->invoice_id)->lockForUpdate()->firstOrFail();
            $link = PaymentLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            if ($link->cancelled_at !== null || $link->replaced_at !== null) {
                return $link;
            }
            if ($invoice->status === Invoice::PAID || ! Money::isZero($invoice->paid_total)) {
                throw new BusinessRuleException('payment_link.paid', 'دُفع على هذا الرابط، فلا يُلغى. للرد استخدم الإشعار الدائن أو رد المبلغ من الفاتورة.');
            }
            self::refuseWhileMoneyIsUnsettled($invoice);

            // Owner's decision (2026-11-20): cancelling ends the link only. The invoice, what it owes and its entries stay
            // as they are; cancelling the invoice is its own action, on its own screen and permission (invoices.void).
            return $this->end($invoice, $link, 'cancelled', $paymobChecked);
        });
    }

    /**
     * End a link (cancelled, or replaced by a newer one), under its invoice's lock, after refuseWhileMoneyIsUnsettled.
     * Paymob's pages of it that brought no recorded payment (PaymentLink::unansweredPages) are past their time here, but
     * the time alone is not taken as proof that Paymob took nothing on them (owner's decision, 2026-11-20): the staff
     * confirm they looked each up at Paymob, and that confirmation is kept with the references in the audit log.
     */
    private function end(Invoice $invoice, PaymentLink $link, string $how, bool $paymobChecked): PaymentLink
    {
        $pages = $link->unansweredPages();
        if ($pages !== [] && ! $paymobChecked) {
            throw new BusinessRuleException('payment_link.unanswered', self::unansweredMessage($pages));
        }
        $link->forceFill($how === 'cancelled' ? ['cancelled_at' => now(), 'cancelled_by' => Auth::id()] : ['replaced_at' => now()])->save();
        $this->audit->log('payment_link.'.$how, $invoice, ['link' => $link->id] + ($pages !== [] ? ['paymob_checked' => $pages] : []));

        return $link;
    }

    /** @param  list<string>  $pages */
    public static function unansweredMessage(array $pages): string
    {
        return 'فتح العميل صفحة دفع Paymob من هذا الرابط ولم تصل منها دفعة مسجّلة (المرجع: '.implode('، ', $pages).'). '
            .'ابحث عن المرجع في لوحة Paymob وتأكد أنه لا توجد عملية ناجحة به، ثم أكّد أنك راجعته.';
    }

    /**
     * Owner's decision (2026-11-19): a payment link's invoice is not cancelled — from the link or from the invoice screen
     * (InvoiceService::void) — while a payment on it is in progress or under review, until that is settled:
     *  - money recorded and not settled: a payment or refund awaiting confirmation, or money received whose credit is not
     *    yet allocated or refunded (a late online payment held for review);
     *  - a payment page opened from it that may still take money (its time, then the late-callback window).
     * Settled money (refunded, allocated elsewhere, rejected, cancelled) no longer stops it. Called under the invoice's lock.
     */
    public static function refuseWhileMoneyIsUnsettled(Invoice $invoice, bool $pages = true): void
    {
        $unsettled = Payment::where('invoice_id', $invoice->id)
            ->where(fn ($q) => $q->where('status', Payment::PENDING)
                ->orWhere(fn ($q) => $q->where('status', Payment::COMPLETED)->where('kind', Payment::KIND_PAYMENT)->whereColumn('credit_amount', '>', 'credit_used')))
            ->exists();
        if ($unsettled) {
            throw new BusinessRuleException('payment_link.money', 'على رابط الدفع لهذه الفاتورة دفعة قيد التنفيذ أو المراجعة، فلا تُلغى حتى تُسوّى. راجعها من «المدفوعات والتحصيل» أولًا.');
        }
        // PR #216/#217: an open signed-Paymob review may represent money even when its checkout expired. An invoice's
        // void is refused by PaymentReview::refuseVoidWhileOpen before it gets here (InvoiceService::void); here it is a
        // link being cancelled or replaced, which never touches the invoice (owner's decision, 2026-11-20): the same
        // rule, said of the link.
        if (PaymentReview::where('invoice_id', $invoice->id)->open()->exists()) {
            throw new BusinessRuleException('payment_link.review', PaymentLinks::REVIEW_REFUSED);
        }
        // The bank's word is still awaited (a signed «pending», or an authorization not captured): whatever time passed.
        if (PaymentLink::processing([$invoice->id])->isNotEmpty()) {
            throw new BusinessRuleException('payment_link.at_bank', 'عملية دفع لهذه الفاتورة ما زالت قيد التنفيذ لدى البنك (أبلغتنا Paymob بذلك). انتظر نتيجتها النهائية قبل إلغاء الرابط أو الفاتورة أو إنشاء رابط جديد.');
        }
        if (! $pages) {
            return;
        }
        $open = PaymentIntent::where('invoice_id', $invoice->id)
            ->where(fn ($q) => $q->where('status', PaymentIntent::PENDING)
                ->orWhere(fn ($q) => $q->where('status', PaymentIntent::FAILED)->whereNotNull('checkout_url')))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->subMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES)))
            ->orderByDesc('expires_at')->first();
        if ($open) {
            $after = $open->expires_at?->copy()->addMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES)->timezone((string) config('app.timezone'));
            throw new BusinessRuleException('payment_link.page_open', 'فتح العميل صفحة الدفع من رابط هذه الفاتورة وقد يدفع منها'
                .($after ? ' حتى الساعة '.$after->format('H:i') : '').'. لا يُلغى الرابط أو الفاتورة ولا يُنشأ رابط بدله قبل ذلك، حتى لا تصل دفعة لا تُنتظر.');
        }
    }

    /** The link the customer opens: its own secure address (a link made before v2: its invoice's pay page, as sent). */
    public function url(PaymentLink $link): string
    {
        $club = $this->tenants->require()->slug;

        return $link->token !== null
            ? route('site.pay.link', ['club' => $club, 'token' => $link->token])
            : route('site.pay.show', ['club' => $club, 'token' => $link->invoice->public_token]);
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
