<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Modules\Payments\Models\PaymentReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Closing the review of Paymob money the books do not hold (payments.review). Staff say what became of the money —
 * recorded in the books, returned to the customer, or never taken — and it closes only when Paymob itself said so (a
 * signed callback, or the payment it made in the books: PaymentReview::evidence), never on a note alone. It moves no
 * money and changes no invoice; the original amounts and references stay on the review, the evidence and the note with
 * it, and the audit trail records who closed it, how and on what.
 */
class PaymentReviewController extends Controller
{
    public function close(Request $request, PaymentReview $paymentReview, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(array_keys(PaymentReview::OUTCOMES))],
            'note' => ['required', 'string', 'min:3', 'max:400'],
        ], [
            'outcome.required' => 'اختر ما الذي حدث للمبلغ.',
            'outcome.in' => 'اختر ما الذي حدث للمبلغ.',
            'note.required' => 'اكتب ما الذي تم بشأن هذه الدفعة قبل إغلاق مراجعتها.',
            'note.min' => 'اكتب ما الذي تم بشأن هذه الدفعة قبل إغلاق مراجعتها.',
            'note.max' => 'الملاحظة أطول من 400 حرف.',
        ]);

        $closed = PaymentReview::closeOn($paymentReview->id, $data['outcome'], $data['note'], $request->user()->id, $audit);

        return back()->with('success', $closed ? 'أُغلقت المراجعة على دليلها. لم يتغير بها أي مبلغ.' : 'هذه المراجعة مغلقة من قبل.');
    }
}
