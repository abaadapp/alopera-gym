<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Papers\Papers;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Finance/Payments/Index', [
            'payments' => BranchContext::scope(Payment::with(['invoice:id,number,customer_name', 'receiver:id,name']))
                ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
                ->when($request->string('method')->toString(), fn ($q, $m) => $q->where('method', $m))
                ->when($request->date('from'), fn ($q, $d) => $q->where('paid_at', '>=', $d))
                ->when($request->date('to'), fn ($q, $d) => $q->where('paid_at', '<=', $d->endOfDay()))
                ->latest('id')->paginate(30)->withQueryString()
                ->through(fn (Payment $p) => $p->only('id', 'receipt_number', 'kind', 'method', 'status', 'amount', 'reference', 'invoice_id') + [
                    'invoice' => $p->invoice?->number, 'customer' => $p->invoice?->customer_name, 'receiver' => $p->receiver?->name,
                    'paid_at' => $p->paid_at?->format('Y-m-d H:i'), 'has_proof' => $p->proof_path !== null,
                ]),
            'pendingCount' => BranchContext::scope(Payment::where('status', Payment::PENDING))->count(),
            'filters' => $request->only('status', 'method', 'from', 'to'),
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
        ], ['reference.required_if' => 'أدخل رقم العملية من جهاز الشبكة.']);

        $proof = $request->hasFile('proof')
            ? $request->file('proof')->store('tenants/'.$tenants->require()->id.'/payment-proofs', 'local')
            : null;

        $payment = $this->payments->record($invoice, $data['amount'], $data['method'], [
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'proof_path' => $proof,
        ]);

        return back()->with('success', $payment->status === Payment::PENDING
            ? 'تم تسجيل التحويل بانتظار تأكيد المالية. تُفعّل العضوية بعد التأكيد.'
            : 'تم تسجيل الدفعة. رقم الإيصال '.$payment->receipt_number);
    }

    public function confirm(Payment $payment): RedirectResponse
    {
        $this->payments->confirm($payment);

        return back()->with('success', 'تم تأكيد التحويل.');
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->payments->reject($payment, $data['reason']);

        return back()->with('success', 'تم رفض التحويل.');
    }

    /** سند القبض: the receipt paper (A4/A5, or the thermal strip with ?face=strip). Only a completed payment has one. */
    public function receipt(Request $request, Payment $payment): HttpResponse
    {
        abort_if($payment->status !== Payment::COMPLETED, 404);

        return Papers::for('receipt', $payment, null, $request->string('face')->toString() === 'strip' ? 'strip' : 'sheet')->page($request->boolean('print', true));
    }

    public function proof(Payment $payment): StreamedResponse
    {
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->download($payment->proof_path);
    }
}
