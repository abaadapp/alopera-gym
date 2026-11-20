<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Core\Support\Money;
use App\Core\Support\SubmittedOnce;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Payments\Services\PaymentLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    /** What the panel shows, here and on the Paymob page: whether links take payments now, and the latest links. */
    public static function props(PaymentLinks $links, OnlinePaymentService $online): array
    {
        return [
            'paymentLinks' => [
                'on' => $online->available(),
                'valid_days' => PaymentLink::VALID_DAYS,
                'currency' => app(TenantContext::class)->require()->currency,
                'can_cancel' => (bool) request()->user()?->can('invoices.void'),
                // A user tied to a branch sees the links of that branch's invoices only, as on the invoice list.
                'rows' => PaymentLink::whereIn('invoice_id', BranchContext::scope(Invoice::query())->select('id'))->with(['invoice:id,number,status,customer_name,customer_phone,billable_id,total,paid_total,credited_total,currency,public_token', 'creator:id,name'])
                    ->latest('id')->limit(50)->get()
                    ->map(fn (PaymentLink $l) => [
                        'id' => $l->id,
                        'number' => $l->invoice?->number,
                        'description' => $l->description,
                        'customer' => $l->invoice?->customer_name,
                        'phone' => $l->invoice?->customer_phone,
                        'amount' => $l->invoice?->total,
                        'currency' => $l->invoice?->currency,
                        'status' => $l->status(),
                        'by' => $l->creator?->name,
                        'at' => $l->created_at->format('Y-m-d H:i'),
                        'expires_at' => $l->expires_at->format('Y-m-d H:i'),
                        'url' => $l->status() === PaymentLink::PENDING ? $links->url($l) : null,
                        'whatsapp' => $l->status() === PaymentLink::PENDING ? $links->whatsapp($l) : null,
                    ]),
            ],
        ];
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

    public function cancel(PaymentLink $paymentLink, PaymentLinks $links): RedirectResponse
    {
        $links->cancel($paymentLink);

        return back()->with('success', 'أُلغي رابط الدفع وفاتورته.');
    }
}
