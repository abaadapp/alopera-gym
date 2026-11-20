<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Core\Support\Money;
use App\Core\Support\SubmittedOnce;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentLinkHistory;
use App\Modules\Payments\Models\PaymentReview;
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Payments\Services\PaymentLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** «روابط الدفع»: make a payment link, copy or share it, follow its state, cancel one nothing was paid on. */
class PaymentLinkController extends Controller
{
    /** The amount field's messages, in the words of the form. */
    private const MESSAGES = [
        'amount.required' => 'اكتب المبلغ.',
        'amount.numeric' => 'المبلغ رقم بالريال العُماني.',
        'amount.decimal' => 'المبلغ بثلاث منازل عشرية على الأكثر (بالبيسة).',
        'amount.min' => 'أقل مبلغ 0.100 ر.ع.',
        'amount.max' => 'أكبر مبلغ لرابط واحد 5,000.000 ر.ع.',
        'description.required' => 'اكتب وصف الدفع.',
        'customer_name.required_with' => 'اكتب اسم العميل مع رقمه.',
        'customer_phone.regex' => 'رقم الهاتف أرقام فقط، مثل 91234567 أو 96891234567.',
        'valid_days.in' => 'اختر مدة صلاحية الرابط.',
    ];

    public function index(PaymentLinks $links, OnlinePaymentService $online): Response
    {
        return Inertia::render('Finance/PaymentLinks/Index', self::props($links, $online));
    }

    /**
     * What the panel shows, here and on the Paymob page: whether links take payments now, and the latest links — each
     * with its invoice's amount, what was paid and what is left (the invoice's, not the link's: a link asks what the
     * invoice still owes when it is opened), money at the bank or under review, and the confirmed Paymob payment.
     */
    public static function props(PaymentLinks $links, OnlinePaymentService $online): array
    {
        // A user tied to a branch sees the links of that branch's invoices only, as on the invoice list.
        $invoices = BranchContext::scope(Invoice::query())->select('id');
        $with = ['invoice:id,number,status,customer_name,customer_phone,billable_id,total,paid_total,credited_total,currency,public_token', 'creator:id,name'];
        $current = PaymentLink::whereIn('invoice_id', $invoices)->with($with)->orderByRaw('coalesce(issued_at, created_at) desc')->orderByDesc('id')->limit(50)->get();
        $earlier = PaymentLinkHistory::whereIn('invoice_id', $invoices)->with($with)->latest('issued_at')->orderByDesc('id')->limit(50)->get();

        return [
            'paymentLinks' => [
                'on' => $online->available(),
                'valid_days' => PaymentLink::VALID_DAYS,
                'currency' => app(TenantContext::class)->require()->currency,
                'can_cancel' => (bool) request()->user()?->can('payments.links'),
                'can_open_invoice' => (bool) request()->user()?->can('invoices.view'),
                'rows' => self::rows($current, $earlier, $links)->take(50)->values(),
            ],
        ];
    }

    /**
     * An invoice's payment link, on its page: its current link (its address, copy, WhatsApp, cancel), whether a new one
     * can be made now, and every link it had, newest first (owner's decision: kept, never deleted).
     *
     * @return array<string, mixed>
     */
    public static function forInvoice(Invoice $invoice, PaymentLinks $links, OnlinePaymentService $online): array
    {
        $current = PaymentLink::where('invoice_id', $invoice->id)->with(['invoice', 'creator:id,name'])->get();
        $earlier = PaymentLinkHistory::where('invoice_id', $invoice->id)->with(['invoice', 'creator:id,name'])->get();
        $shown = self::rows($current, $earlier, $links);
        $live = $shown->first(fn (array $r) => $r['live']);

        return [
            'on' => $online->available(),
            'valid_days' => PaymentLink::VALID_DAYS,
            'can_manage' => (bool) request()->user()?->can('payments.links'),
            // A new link: while something is owed and no link still runs (one past its time is replaced by it).
            'can_open' => $invoice->isOpen() && ! Money::isZero($invoice->balance()) && ($live === null || $live['status'] === PaymentLink::EXPIRED),
            'live' => $live,
            'history' => $shown->values(),
        ];
    }

    /**
     * The links as the log shows them, newest first: each invoice's current link (PaymentLink) and its earlier ones
     * (PaymentLinkHistory, cancelled or replaced). What was paid and what is left are the invoice's.
     *
     * @param  Collection<int, PaymentLink>  $current
     * @param  Collection<int, PaymentLinkHistory>  $earlier
     * @return Collection<int, array<string, mixed>>
     */
    private static function rows(Collection $current, Collection $earlier, PaymentLinks $links): Collection
    {
        $ids = $current->pluck('invoice_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $reviewed = $ids === [] ? collect() : PaymentReview::whereIn('invoice_id', $ids)->open()->pluck('invoice_id')->map(fn ($id) => (int) $id);
        $processing = PaymentLink::processing($ids);
        // The confirmed Paymob payment of each invoice: amount, when, the receipt and Paymob's transaction number.
        $paid = $ids === [] ? collect() : Payment::whereIn('invoice_id', $ids)->where('gateway', 'paymob')->where('kind', Payment::KIND_PAYMENT)
            ->where('status', Payment::COMPLETED)->orderBy('id')->get(['invoice_id', 'amount', 'paid_at', 'receipt_number', 'gateway_transaction_id'])->keyBy('invoice_id');
        $zone = (string) config('app.timezone');
        $common = fn (PaymentLink|PaymentLinkHistory $l, Carbon $issued) => [
            'invoice_id' => $l->invoice_id,
            'number' => $l->invoice?->number,
            'description' => $l->description,
            'customer' => $l->invoice?->customer_name,
            'phone' => $l->invoice?->customer_phone,
            'amount' => $l->invoice?->total,
            'paid' => $l->invoice?->paid_total,
            'remaining' => $l->invoice?->balance(),
            // What the invoice owed when this link was issued (links made before v2: unknown).
            'asked' => $l->amount,
            'currency' => $l->invoice?->currency,
            'by' => $l->creator?->name,
            'at' => $issued->copy()->timezone($zone)->format('Y-m-d H:i'),
            'expires_at' => $l->expires_at->copy()->timezone($zone)->format('Y-m-d H:i'),
            'sort' => $issued->getTimestamp(),
        ];

        $now = $current->map(function (PaymentLink $l) use ($links, $reviewed, $processing, $paid, $zone, $common) {
            $status = $l->status(['review' => $reviewed->contains((int) $l->invoice_id), 'processing' => $processing->contains((int) $l->invoice_id)]);
            $live = $l->cancelled_at === null;
            $payment = $live ? $paid->get($l->invoice_id) : null;
            $endable = $live && in_array($status, [PaymentLink::PENDING, PaymentLink::EXPIRED], true);

            return ['key' => 'l'.$l->id, 'id' => $l->id, 'status' => $status, 'live' => $live,
                'ended_at' => $l->cancelled_at?->copy()->timezone($zone)->format('Y-m-d H:i'),
                'payment' => $payment ? [
                    'amount' => $payment->amount,
                    'at' => $payment->paid_at?->timezone($zone)->format('Y-m-d H:i'),
                    'receipt' => $payment->receipt_number,
                    'transaction' => $payment->gateway_transaction_id,
                ] : null,
                'url' => $status === PaymentLink::PENDING ? $links->url($l) : null,
                'whatsapp' => $status === PaymentLink::PENDING ? $links->whatsapp($l) : null,
                // Paymob pages of it with no recorded payment: what the staff look up at Paymob before ending it.
                'unanswered' => $endable ? $l->unansweredPages() : [],
            ] + $common($l, $l->issuedAt());
        });
        $before = $earlier->map(fn (PaymentLinkHistory $h) => ['key' => 'h'.$h->id, 'id' => $h->id, 'status' => $h->status(), 'live' => false,
            'ended_at' => ($h->cancelled_at ?? $h->replaced_at)?->copy()->timezone($zone)->format('Y-m-d H:i'),
            'payment' => null, 'url' => null, 'whatsapp' => null, 'unanswered' => [],
        ] + $common($h, $h->issued_at));

        // Newest first; within one second, an invoice's current link before its earlier ones, then the newer row.
        return $now->concat($before)->sortByDesc(fn (array $r) => [$r['sort'], $r['key'][0] === 'l' ? 1 : 0, $r['id']])->map(fn (array $r) => Arr::except($r, 'sort'))->values();
    }

    public function store(Request $request, PaymentLinks $links): RedirectResponse
    {
        $data = $request->validate([
            // For now a whole number of 10 baisa (OnlinePaymentService::singleBaisa): a card may be charged a single-baisa
            // amount rounded (4.725 as 4.720), which the callback's exact check would not record. Never rounded here.
            'amount' => ['bail', 'required', 'numeric', 'decimal:0,3', 'min:0.1', 'max:5000', function (string $attribute, mixed $value, \Closure $fail) {
                if (OnlinePaymentService::singleBaisa(Money::normalize($value))) {
                    $fail('مؤقتًا اجعل آخر خانة في المبلغ صفرًا (مثل 4.720 أو 4.730): لم يُتحقق بعد من أن Paymob تقبل البيسة المفردة مع بطاقات Visa، والمبلغ لا يُقرَّب.');
                }
            }],
            'description' => ['required', 'string', 'max:250'],
            'customer_name' => ['nullable', 'required_with:customer_phone', 'string', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:20', 'regex:/^[+0-9٠-٩\s-]{8,20}$/u'],
            'valid_days' => ['required', 'in:'.implode(',', PaymentLink::VALID_DAYS)],
            'client_key' => SubmittedOnce::RULE,
        ], self::MESSAGES);

        // Pressed twice (a double click, a retry): one link.
        SubmittedOnce::run($data['client_key'] ?? null, 'payment-link', fn () => $links->create($data)->id);

        return back()->with('success', 'أُنشئ رابط الدفع. انسخه أو أرسله بواتساب من السجل.');
    }

    /** A new link for an issued invoice (from its page), or in place of its link whose time is over. */
    public function open(Request $request, Invoice $invoice, PaymentLinks $links): RedirectResponse
    {
        $data = $request->validate([
            'valid_days' => ['required', 'in:'.implode(',', PaymentLink::VALID_DAYS)],
            'description' => ['nullable', 'string', 'max:250'],
            'paymob_checked' => ['nullable', 'boolean'],
            'client_key' => SubmittedOnce::RULE,
        ], self::MESSAGES);

        // Pressed twice (a double click, a retry): one link.
        SubmittedOnce::run($data['client_key'] ?? null, 'payment-link:'.$invoice->id, fn () => $links->open($invoice, (int) $data['valid_days'], $data['description'] ?? null, (bool) ($data['paymob_checked'] ?? false))->id);

        return back()->with('success', 'أُنشئ رابط الدفع. انسخه أو أرسله بواتساب.');
    }

    public function cancel(Request $request, PaymentLink $paymentLink, PaymentLinks $links): RedirectResponse
    {
        $data = $request->validate(['paymob_checked' => ['nullable', 'boolean']]);
        $links->cancel($paymentLink, (bool) ($data['paymob_checked'] ?? false));

        return back()->with('success', 'أُلغي رابط الدفع. الفاتورة ومستحقاتها كما هي.');
    }
}
