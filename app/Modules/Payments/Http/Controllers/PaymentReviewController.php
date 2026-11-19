<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Modules\Payments\Models\PaymentReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Closing the review of Paymob money that did not match its invoice (payments.review), once staff dealt with it at
 * Paymob and in the payment screens. It moves no money and changes no invoice: it only lifts what the open review held
 * (a new checkout, the dues reminder, the website order's automatic cancellation), with who, when and why.
 */
class PaymentReviewController extends Controller
{
    public function close(Request $request, PaymentReview $paymentReview, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']], [
            'note.required' => 'اكتب ما الذي تم بشأن هذه الدفعة قبل إغلاق مراجعتها.',
            'note.min' => 'اكتب ما الذي تم بشأن هذه الدفعة قبل إغلاق مراجعتها.',
            'note.max' => 'الملاحظة أطول من 500 حرف.',
        ]);

        $closed = DB::transaction(function () use ($paymentReview, $data, $request, $audit) {
            $review = PaymentReview::whereKey($paymentReview->id)->lockForUpdate()->firstOrFail();
            if ($review->status !== PaymentReview::OPEN) {
                return false;
            }
            $review->forceFill([
                'status' => PaymentReview::CLOSED,
                'closed_at' => now(),
                'closed_by' => $request->user()->id,
                'close_note' => trim($data['note']),
            ])->save();
            $audit->log('payment.review_closed', $review, [
                'transaction' => $review->transaction_id, 'invoice' => $review->invoice_id, 'reason' => $review->reason, 'note' => $review->close_note,
            ]);

            return true;
        });

        return back()->with('success', $closed ? 'أُغلقت المراجعة. لم يتغير بها أي مبلغ.' : 'هذه المراجعة مغلقة من قبل.');
    }
}
