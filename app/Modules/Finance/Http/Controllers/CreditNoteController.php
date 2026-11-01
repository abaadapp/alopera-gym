<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Papers\Papers;
use App\Core\Support\Money;
use App\Core\Support\SubmittedOnce;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;

class CreditNoteController extends Controller
{
    /** Issue a credit note; optionally pay back, in the same step, what it leaves overpaid. */
    public function store(Request $request, Invoice $invoice, CreditNoteService $credits): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.001', 'max:99999'],
            'reason' => ['required', 'string', 'max:255'],
            'refund_now' => ['boolean'],
            'method' => ['required_if:refund_now,true', 'nullable', Rule::in(Payment::REFUND_METHODS)],
            'reference' => ['nullable', 'string', 'max:120', 'required_if:method,card'],
            'client_key' => SubmittedOnce::RULE,
        ], ['reference.required_if' => 'أدخل رقم العملية من جهاز الشبكة.']);

        abort_if($request->boolean('refund_now') && ! $request->user()->can('payments.refund'), 403);

        // Sent twice (a double click, a retry): one note, one refund.
        $note = CreditNote::findOrFail(SubmittedOnce::run($data['client_key'] ?? null, 'credit-note:'.$invoice->id, fn () => $credits->issue($invoice, $data['amount'], $data['reason'],
            $request->boolean('refund_now') ? ['method' => $data['method'], 'reference' => $data['reference'] ?? null] : null)->id));

        $waiting = Payment::where('credit_note_id', $note->id)->where('status', Payment::PENDING)->sum('amount');

        return back()->with('success', 'تم إصدار الإشعار الدائن '.$note->number
            .(! Money::isZero($note->refunded_total) ? ' ورد '.$note->refunded_total.' للعميل.' : (! Money::isZero($waiting) ? '، وسُجّل رد '.Money::normalize($waiting).' بانتظار تأكيد تنفيذه.' : '.')));
    }

    /** Pay back money a credit note left overpaid (when it was not refunded at issue time). */
    public function refund(Request $request, CreditNote $creditNote, PaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.001'],
            'method' => ['required', Rule::in(Payment::REFUND_METHODS)],
            'reference' => ['nullable', 'string', 'max:120', 'required_if:method,card'],
            'account_id' => ['nullable', 'integer', TenantRule::exists('gym_accounts')],
            'client_key' => SubmittedOnce::RULE,
        ], ['reference.required_if' => 'أدخل رقم العملية من جهاز الشبكة.']);

        // Sent twice (a double click, a retry): the money goes back once.
        $refund = Payment::findOrFail(SubmittedOnce::run($data['client_key'] ?? null, 'credit-refund:'.$creditNote->id, fn () => $payments->refund($creditNote, $data['amount'], $data['method'], $data['reference'] ?? null, isset($data['account_id']) ? (int) $data['account_id'] : null)->id));

        return back()->with('success', $refund->status === Payment::PENDING ? 'سُجّل الرد بانتظار تأكيد تنفيذه (التحويل البنكي أو Paymob). لا يدخل الدفاتر قبل التأكيد.' : 'تم تسجيل رد المبلغ.');
    }

    /** The print page of the credit note — the paper engine, as on screen and in the PDF. */
    public function print(Request $request, CreditNote $creditNote): HttpResponse
    {
        return Papers::for('credit_note', $creditNote)->page($request->boolean('print', true));
    }
}
