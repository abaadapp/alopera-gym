<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Papers\Papers;
use App\Core\Support\Money;
use App\Core\Support\Period;
use App\Core\Support\SubmittedOnce;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Papers\ReceiptPaper;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Inventory\Services\PosDevices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Finance/Payments/Index', [
            'payments' => BranchContext::scope(Payment::with(['invoice:id,number,customer_name', 'receiver:id,name', 'account:id,name']))
                ->when($request->string('status')->toString(), fn ($q, $s) => $s === 'credit'
                    ? $q->where('status', Payment::COMPLETED)->whereColumn('credit_amount', '>', 'credit_used')
                    : $q->where('status', $s))
                ->when($request->string('method')->toString(), fn ($q, $m) => $q->where('method', $m))
                // The receipt, the invoice's number or the customer's name.
                ->when($request->string('q')->toString(), fn ($q, $term) => $q->where(fn ($w) => $w->where('receipt_number', 'ilike', "%{$term}%")->orWhere('reference', 'ilike', "%{$term}%")
                    ->orWhereHas('invoice', fn ($i) => $i->where('number', 'ilike', "%{$term}%")->orWhere('customer_name', 'ilike', "%{$term}%"))))
                ->tap(fn ($q) => $request->string('status')->toString() === 'credit' ? $q : Period::fromRequest($request)->apply($q, 'paid_at'))
                ->latest('id')->paginate(in_array($request->integer('per_page'), [30, 50, 100], true) ? $request->integer('per_page') : 30)->withQueryString()
                ->through(fn (Payment $p) => $p->only('id', 'receipt_number', 'kind', 'method', 'status', 'amount', 'reference', 'invoice_id', 'from_credit', 'cancel_reason') + [
                    'invoice' => $p->invoice?->number, 'customer' => $p->invoice?->customer_name, 'receiver' => $p->receiver?->name, 'account' => $p->account?->name,
                    'paid_at' => $p->paid_at?->format('Y-m-d H:i'), 'has_proof' => $p->proof_path !== null,
                    'credit_left' => Money::isZero($p->credit_amount) ? null : $p->creditLeft(),
                ]),
            'pendingCount' => BranchContext::scope(Payment::where('status', Payment::PENDING))->count(),
            'creditCount' => BranchContext::scope(Payment::where('status', Payment::COMPLETED)->whereColumn('credit_amount', '>', 'credit_used'))->count(),
            'filters' => $request->only('status', 'method', 'q', 'per_page'),
            'period' => Period::fromRequest($request)->toArray(),
        ]);
    }

    public function store(Request $request, Invoice $invoice, TenantContext $tenants): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.001', 'max:999999'],
            'method' => ['required', 'in:cash,bank_transfer,card,other'],
            'reference' => ['nullable', 'string', 'max:120', 'required_if:method,card'],
            'notes' => ['nullable', 'string', 'max:500'],
            'proof' => ['nullable', 'file', 'max:'.config('gym.max_document_kb'), 'mimes:pdf,jpg,jpeg,png,webp,heic'],
            'account_id' => ['nullable', 'integer', TenantRule::exists('gym_accounts')],
            'client_key' => SubmittedOnce::RULE,
        ], ['reference.required_if' => 'أدخل رقم العملية من جهاز الشبكة.']);

        // One form, one payment: sent twice (a double click, a retry), the second gets the first payment back.
        [$pending, $receipt] = SubmittedOnce::run($data['client_key'] ?? null, 'invoice-payment:'.$invoice->id, function () use ($request, $tenants, $invoice, $data) {
            $proof = $request->hasFile('proof')
                ? $request->file('proof')->store('tenants/'.$tenants->require()->id.'/payment-proofs', 'local')
                : null;
            $payment = $this->payments->record($invoice, $data['amount'], $data['method'], [
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'proof_path' => $proof,
                'account_id' => isset($data['account_id']) ? (int) $data['account_id'] : null,
            ]);

            return [$payment->status === Payment::PENDING, $payment->receipt_number];
        });

        return back()->with('success', $pending
            ? 'تم تسجيل التحويل بانتظار تأكيد المالية. تُفعّل العضوية بعد التأكيد.'
            : 'تم تسجيل الدفعة. رقم الإيصال '.$receipt);
    }

    /** A transfer seen on the statement, or a refund waiting to be made now made (with its reference at the bank or Paymob). */
    public function confirm(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:120']]);
        $this->payments->confirm($payment, $data['reference'] ?? null);

        return back()->with('success', $payment->kind === Payment::KIND_REFUND ? 'تم تأكيد تنفيذ الرد، ودخل الدفاتر.' : 'تم تأكيد التحويل.');
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->payments->reject($payment, $data['reason']);

        return back()->with('success', $payment->kind === Payment::KIND_REFUND ? 'أُلغي طلب الرد، وعاد مبلغه متاحًا.' : 'تم رفض التحويل.');
    }

    /** A payment entered by mistake: cancelled with a reason, its entry reversed; the invoice owes it again. */
    public function cancel(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], [], ['reason' => 'سبب الإلغاء']);
        $payment = $this->payments->cancel($payment, $data['reason']);
        $invoice = $payment->invoice()->first();

        return back()->with('success', 'أُلغيت الدفعة '.($payment->receipt_number ?? '').'. الفاتورة '.$invoice?->number.' مستحق عليها الآن '.$invoice?->balance().'.'
            .($invoice?->type === 'membership' || $invoice?->type === 'pt' ? ' ما دُفعت له (العضوية أو الباقة) يبقى كما هو؛ راجعه إن لزم.' : ''));
    }

    /** Apply a customer's credit (money received and not applied) to another open invoice of theirs. */
    public function allocate(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'integer', TenantRule::exists('invoices')],
            'amount' => ['required', 'numeric', 'min:0.001', 'max:999999'],
            'reason' => ['nullable', 'string', 'max:500'], // required for gateway money (PaymentService)
            'client_key' => SubmittedOnce::RULE,
        ], [], ['invoice_id' => 'الفاتورة', 'amount' => 'المبلغ', 'reason' => 'السبب']);
        $invoice = Invoice::findOrFail($data['invoice_id']);
        // The invoice comes in the body, not the address: it is held to the user's branch here, as the payment is.
        abort_unless(app(BranchContext::class)->allows($request->user(), $invoice->owningBranchId()), 404);
        SubmittedOnce::run($data['client_key'] ?? null, 'credit-allocate:'.$payment->id, fn () => $this->payments->allocate($payment, $invoice, $data['amount'], $data['reason'] ?? null)->id);

        return back()->with('success', 'خُصّص '.Money::normalize($data['amount']).' من الرصيد للفاتورة '.$invoice->number.'.');
    }

    /** Pay back a customer's credit: at the desk at once, by bank transfer or Paymob once confirmed made. */
    public function refundCredit(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.001', 'max:999999'],
            'method' => ['required', Rule::in(Payment::REFUND_METHODS)],
            'reference' => ['nullable', 'string', 'max:120', 'required_if:method,card'],
            'account_id' => ['nullable', 'integer', TenantRule::exists('gym_accounts')],
            'reason' => ['nullable', 'string', 'max:500'], // required for gateway money (PaymentService)
            'client_key' => SubmittedOnce::RULE,
        ], ['reference.required_if' => 'أدخل رقم العملية من جهاز الشبكة.'], ['reason' => 'السبب']);
        $refund = Payment::findOrFail(SubmittedOnce::run($data['client_key'] ?? null, 'credit-refund-payment:'.$payment->id,
            fn () => $this->payments->refundCredit($payment, $data['amount'], $data['method'], $data['reference'] ?? null, isset($data['account_id']) ? (int) $data['account_id'] : null, $data['reason'] ?? null)->id));

        return back()->with('success', $refund->status === Payment::PENDING ? 'سُجّل طلب الرد بانتظار تأكيد تنفيذه.' : 'تم رد المبلغ من الرصيد.');
    }

    /**
     * سند القبض: the receipt paper (A4/A5, or the thermal strip with ?face=strip — at the width of the printer of this
     * point-of-sale device, 58 or 80 mm, when it has one). Only a completed payment has one.
     */
    public function receipt(Request $request, Payment $payment, PosDevices $devices): HttpResponse
    {
        abort_if($payment->status !== Payment::COMPLETED, 404);
        abort_unless(ReceiptPaper::allows($request->user(), $payment), 403);
        // A strip at the width of this till's printer (or a sheet, when its printer feeds A4 or A5).
        $strip = $request->string('face')->toString() === 'strip';
        $paper = $strip ? $devices->current($request)?->loadMissing('peripherals')->printer()?->paperSize() : null;

        return Papers::for('receipt', $payment, null, $strip ? 'strip' : 'sheet', $paper)->respond($request);
    }

    public function proof(Payment $payment): StreamedResponse
    {
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->download($payment->proof_path);
    }
}
