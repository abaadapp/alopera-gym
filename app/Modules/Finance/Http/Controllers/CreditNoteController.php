<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Support\Money;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditNoteController extends Controller
{
    /** Issue a credit note; optionally pay back, in the same step, what it leaves overpaid. */
    public function store(Request $request, Invoice $invoice, CreditNoteService $credits): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.001', 'max:99999'],
            'reason' => ['required', 'string', 'max:255'],
            'refund_now' => ['boolean'],
            'method' => ['required_if:refund_now,true', 'nullable', 'in:cash,bank_transfer,card,other'],
            'reference' => ['nullable', 'string', 'max:120', 'required_if:method,card'],
        ], ['reference.required_if' => 'أدخل رقم العملية من جهاز الشبكة.']);

        abort_if($request->boolean('refund_now') && ! $request->user()->can('payments.refund'), 403);

        $note = $credits->issue($invoice, $data['amount'], $data['reason'],
            $request->boolean('refund_now') ? ['method' => $data['method'], 'reference' => $data['reference'] ?? null] : null);

        return back()->with('success', 'تم إصدار الإشعار الدائن '.$note->number
            .(! Money::isZero($note->refunded_total) ? ' ورد '.$note->refunded_total.' للعميل.' : '.'));
    }

    /** Pay back money a credit note left overpaid (when it was not refunded at issue time). */
    public function refund(Request $request, CreditNote $creditNote, PaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.001'],
            'method' => ['required', 'in:cash,bank_transfer,card,other'],
            'reference' => ['nullable', 'string', 'max:120', 'required_if:method,card'],
        ], ['reference.required_if' => 'أدخل رقم العملية من جهاز الشبكة.']);

        $payments->refund($creditNote, $data['amount'], $data['method'], $data['reference'] ?? null);

        return back()->with('success', 'تم تسجيل رد المبلغ.');
    }

    public function print(CreditNote $creditNote, TenantContext $tenants): Response
    {
        $creditNote->load(['invoice', 'creator:id,name', 'refunds' => fn ($q) => $q->where('status', 'completed')]);
        $tenant = $tenants->require();
        $invoice = $creditNote->invoice;

        return Inertia::render('Finance/CreditNotes/Print', [
            'note' => $creditNote->only('number', 'amount', 'refunded_total', 'currency', 'reason') + [
                'issued_at' => $creditNote->created_at->format('Y-m-d H:i'),
                'creator' => $creditNote->creator?->name,
                'refunds' => $creditNote->refunds->map(fn ($p) => $p->only('receipt_number', 'method', 'amount', 'reference') + ['paid_at' => $p->paid_at?->format('Y-m-d H:i')]),
                'invoice' => $invoice->only('id', 'number', 'customer_name', 'customer_phone', 'total', 'credited_total', 'paid_total') + [
                    'issue_date' => $invoice->issue_date->toDateString(), 'net_total' => $invoice->netTotal(), 'balance' => $invoice->balance(),
                ],
            ],
            'club' => $tenant->only('name', 'legal_name', 'phone', 'address') + ['logo_url' => $tenant->logoUrl(), 'vat_number' => $tenant->setting('vat_number')],
        ]);
    }
}
