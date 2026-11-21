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
use App\Modules\Payments\Models\PaymentLinkHistory;
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
     * a single baisa, which a card may round), online payments off, a link still running (cancel it first), or money of
     * the invoice not settled — a payment awaiting confirmation, money held for review, an open Paymob review, a signed
     * «pending» or authorization at the bank, whatever time has passed.
     *
     * Design B (owner's decision, 2026-11-21): the invoice keeps ONE link row. Its first link is that row; a later one
     * takes the row's place: what the row held goes to PaymentLinkHistory first (cancelled, or replaced once its time was
     * over — then, as a cancel, only when no page of it may still take money and its unanswered Paymob pages were checked
     * at Paymob: end()), and the row gets the new address, time and creator. Pages opened from an earlier link keep their
     * own Paymob reference and invoice (PaymentIntent): money Paymob took on them is still matched and recorded once,
     * whatever address the row has now.
     *
     * All under the invoice's lock, then the row's, as the checkout, the cancel and the callback take the invoice's: two
     * requests at once make one link, the second seeing the first's (and the unique key on invoice_id stops a second row).
     */
    public function open(Invoice $invoice, int $days, ?string $description = null, bool $paymobChecked = false, ?Carbon $expires = null, bool $whileOff = false): PaymentLink
    {
        if (! in_array($days, PaymentLink::VALID_DAYS, true)) {
            throw new BusinessRuleException('payment_link.days', 'اختر مدة صلاحية الرابط.', 'valid_days');
        }

        $link = DB::transaction(function () use ($invoice, $days, $description, $paymobChecked, $expires, $whileOff) {
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
            $row = PaymentLink::where('invoice_id', $invoice->id)->lockForUpdate()->first();
            if ($row && $row->cancelled_at === null && $row->expires_at->isFuture()) {
                throw new BusinessRuleException('payment_link.live', 'لهذه الفاتورة رابط دفع نشط حتى '.$row->expires_at->timezone((string) config('app.timezone'))->format('Y-m-d H:i')
                    .'. انسخه أو أرسله، أو ألغه أولًا لإنشاء رابط جديد.');
            }
            // Money of the invoice not settled stops a new link. A page still open stops it when it ends a running link
            // (one past its time): a cancelled link was checked when it was cancelled and opened nothing since, and a page
            // opened from the invoice's own address is reused by the new link, or refused — never a second one.
            $replacing = $row !== null && $row->cancelled_at === null;
            self::refuseWhileMoneyIsUnsettled($invoice, pages: $replacing);
            $data = [
                'token' => Str::random(48),
                'description' => filled($description) ? trim((string) $description) : 'فاتورة '.$invoice->number,
                'created_by' => Auth::id(),
                'issued_at' => now(),
                'amount' => $invoice->balance(),
                'expires_at' => $expires ?? now()->addDays($days),
                'cancelled_at' => null,
                'cancelled_by' => null,
                'paymob_checked' => null,
            ];

            if ($row === null) {
                $link = PaymentLink::create(['invoice_id' => $invoice->id] + $data);
                $this->audit->log('payment_link.created', $invoice, ['link' => $link->id, 'amount' => $link->amount, 'expires_at' => $link->expires_at->toIso8601String()]);

                return $link;
            }

            $kept = $replacing ? $this->end($invoice, $row, 'replaced', $paymobChecked) : $this->archive($invoice, $row, []);
            $row->forceFill($data)->save();
            $this->audit->log('payment_link.created', $invoice, ['link' => $row->id, 'amount' => $row->amount, 'expires_at' => $row->expires_at->toIso8601String(), 'after' => $kept->id]);

            return $row;
        });

        // With the club's API key, the link is made inside Paymob too (QuickLinks) — once everything around it is
        // committed, Paymob called with no lock held; when Paymob does not make it, the staff are told and the link
        // opens the club's own page as before.
        DB::afterCommit(function () use ($link) {
            if (($why = app(QuickLinks::class)->issue($link)) !== null && app()->bound('session') && request()->hasSession()) {
                request()->session()->flash('error', $why);
            }
        });

        return $link;
    }

    /**
     * Cancel a link: it opens nothing any more; it stays the invoice's link row, «cancelled», until a new link takes its
     * place (then it goes to the history as it was). Refused once anything was paid on its invoice (a part too: owner's
     * decision), and while money of it is not settled or a page of it may still take money (refuseWhileMoneyIsUnsettled),
     * and, for pages Paymob never answered, until the staff say they checked them at Paymob (end()).
     */
    public function cancel(PaymentLink $link, bool $paymobChecked = false): PaymentLink
    {
        // A link made inside Paymob (QuickLinks) is closed there first — after the checks that need no Paymob (nothing is
        // closed there for a cancel refused anyway), and outside any lock. What Paymob said is kept on its attempt at
        // once, apart from the cancel below: should the cancel then fail, the attempt still says the truth (closed, so
        // the link opens the club's own page and the desk is open), and cancelling again goes on without asking Paymob
        // twice. Paymob not confirming refuses the cancel, nothing changed.
        $ended = DB::transaction(function () use ($link) {
            $invoice = Invoice::whereKey($link->invoice_id)->lockForUpdate()->firstOrFail();
            if (PaymentLink::whereKey($link->id)->lockForUpdate()->firstOrFail()->cancelled_at !== null) {
                return true;
            }
            self::refusePaid($invoice);
            self::refuseWhileMoneyIsUnsettled($invoice, pages: false);

            return false;
        });
        if (! $ended) {
            app(QuickLinks::class)->closeAtPaymob($link, $paymobChecked);
        }

        return DB::transaction(function () use ($link, $paymobChecked) {
            $invoice = Invoice::whereKey($link->invoice_id)->lockForUpdate()->firstOrFail();
            $link = PaymentLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            if ($link->cancelled_at !== null) {
                return $link;
            }
            self::refusePaid($invoice);
            self::refuseWhileMoneyIsUnsettled($invoice);

            // Owner's decision (2026-11-20): cancelling ends the link only. The invoice, what it owes and its entries stay
            // as they are; cancelling the invoice is its own action, on its own screen and permission (invoices.void).
            $this->end($invoice, $link, 'cancelled', $paymobChecked);

            return $link;
        });
    }

    /**
     * End a running link — cancelled (the row is marked) or replaced (the row goes to the history, then takes the new
     * link) — under its invoice's lock, after refuseWhileMoneyIsUnsettled. Paymob's pages of it that brought no recorded
     * payment (PaymentLink::unansweredPages) are past their time here, but the time alone is not taken as proof that
     * Paymob took nothing on them (owner's decision, 2026-11-20): the staff confirm they looked each up at Paymob, and
     * that confirmation is kept with the references, on the link and in the audit log.
     *
     * @return PaymentLink|PaymentLinkHistory the cancelled row, or the history entry of the replaced link
     */
    private function end(Invoice $invoice, PaymentLink $link, string $how, bool $paymobChecked): PaymentLink|PaymentLinkHistory
    {
        $pages = $link->unansweredPages();
        if ($pages !== [] && ! $paymobChecked) {
            throw new BusinessRuleException('payment_link.unanswered', self::unansweredMessage($pages));
        }
        $this->audit->log('payment_link.'.$how, $invoice, ['link' => $link->id] + ($pages !== [] ? ['paymob_checked' => $pages] : []));
        if ($how === 'cancelled') {
            $link->forceFill(['cancelled_at' => now(), 'cancelled_by' => Auth::id(), 'paymob_checked' => $pages !== [] ? $pages : null])->save();

            return $link;
        }

        return $this->archive($invoice, $link, ['replaced_at' => now(), 'replaced_by' => Auth::id(), 'paymob_checked' => $pages !== [] ? $pages : null]);
    }

    /**
     * The link the row holds, kept as it is before a new link takes the row: its address, what it asked, its time, and
     * how it ended (cancelled — by this code or by the code before v2 —, or replaced: $ended). Written once.
     *
     * @param  array<string, mixed>  $ended
     */
    private function archive(Invoice $invoice, PaymentLink $row, array $ended): PaymentLinkHistory
    {
        // How it ended ($ended) over what the row held (a replaced link's confirmation over the row's none).
        return PaymentLinkHistory::create($ended + [
            'invoice_id' => $invoice->id,
            'payment_link_id' => $row->id,
            'token' => $row->token,
            'description' => $row->description,
            'amount' => $row->amount,
            'balance_at_end' => $invoice->balance(),
            'created_by' => $row->created_by,
            'issued_at' => $row->issuedAt(),
            'expires_at' => $row->expires_at,
            'cancelled_at' => $row->cancelled_at,
            'cancelled_by' => $row->cancelled_by,
            'paymob_checked' => $row->paymob_checked,
        ]);
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
    private static function refusePaid(Invoice $invoice): void
    {
        if ($invoice->status === Invoice::PAID || ! Money::isZero($invoice->paid_total)) {
            throw new BusinessRuleException('payment_link.paid', 'دُفع على هذا الرابط، فلا يُلغى. للرد استخدم الإشعار الدائن أو رد المبلغ من الفاتورة.');
        }
    }

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
        // Made inside Paymob (QuickLinks): Paymob's own address, while it may be paid.
        if ($link->token !== null && ($paymob = QuickLinks::openFor($link->invoice_id, $link->issuedAt())) !== null) {
            return (string) $paymob->checkout_url;
        }

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
