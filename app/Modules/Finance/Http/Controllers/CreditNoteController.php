<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Papers\Papers;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

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

    /** The print page of the credit note — the paper engine, as on screen and in the PDF. */
    public function print(Request $request, CreditNote $creditNote): HttpResponse
    {
        return Papers::for('credit_note', $creditNote)->page($request->boolean('print', true));
    }
}
