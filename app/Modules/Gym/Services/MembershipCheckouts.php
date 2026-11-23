<?php

namespace App\Modules\Gym\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipCheckout;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentReview;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Support\Facades\DB;

/**
 * A membership bought online is sold after it is paid, not before (owner's decision, 2026-11-24).
 *
 *   open      the member chose a plan on the website (sign-up) or in her portal (renewal) and pays online: every check
 *             of a sale is made (MembershipService::checkoutLines) and the amount is kept on an unnumbered draft invoice — the
 *             one Paymob is asked for. No membership, no number, nothing owed, nothing in the books, no message: a draft
 *             is outside every list, total and report (Invoice's «issued» scope).
 *   complete  Paymob confirmed the money (OnlinePaymentService, under the intent's lock): the sale is made then, as the
 *             desk makes it (MembershipService::sell / renew — a numbered invoice, the membership pending), the
 *             checkout's attempts move to that invoice, the draft is removed, and the payment is recorded on it like
 *             any other: paid in full, ActivatePaidItems activates the membership and the payment and the activation
 *             are announced. A sale that can no longer be made as it was chosen (the plan withdrawn, a period now
 *             overlapping, the coupon used up, another price) still gets a numbered invoice for the money, which is
 *             held for the club's review — money taken is never left without an invoice.
 *
 * An attempt that is never paid leaves nothing anywhere but this row and its hidden draft; a new choice abandons it.
 */
final class MembershipCheckouts
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly InvoiceService $invoices,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The member's checkout for this plan: a new one, or the one already open for the same choice while a page of it
     * may still take money. Another choice while such a page is open is refused (she could pay both); an earlier
     * attempt that can no longer take money is abandoned. A choice that costs nothing (a full discount) is sold at once.
     *
     * @param  string  $kind  new (a sign-up) | renewal
     * @param  string  $channel  website | portal
     */
    public function open(Member $member, MembershipPlan $plan, string $kind, string $channel, ?string $coupon = null): MembershipCheckout
    {
        $coupon = filled($coupon) ? trim((string) $coupon) : null;

        return DB::transaction(function () use ($member, $plan, $kind, $channel, $coupon) {
            Member::whereKey($member->id)->lockForUpdate()->first();

            foreach (MembershipCheckout::where('member_id', $member->id)->where('status', MembershipCheckout::OPEN)->lockForUpdate()->get() as $earlier) {
                if (self::mayTakeMoney($earlier)) {
                    if ($earlier->membership_plan_id === $plan->id && $earlier->kind === $kind && $earlier->coupon_code === $coupon) {
                        return $earlier;
                    }
                    throw new BusinessRuleException('checkout.open', __('لديكِ صفحة دفع مفتوحة لاشتراك آخر. أكملي الدفع منها، أو انتظري ساعة ثم حاولي مرة أخرى.'), 'membership_plan_id');
                }
                $earlier->forceFill(['status' => MembershipCheckout::ABANDONED])->save();
            }

            $lines = $this->memberships->checkoutLines($member, $plan, $kind === Membership::KIND_RENEWAL, $coupon);
            $draft = $this->invoices->draft($member, $lines, ['type' => 'membership', 'branch_id' => $member->branch_id]);

            // Nothing to pay: there is no payment to wait for — sold now, paid on issue (InvoiceService::issue).
            if (Money::isZero($draft->total)) {
                $membership = $this->sell($member, $plan, $kind, $coupon);

                return MembershipCheckout::create([
                    'member_id' => $member->id, 'membership_plan_id' => $plan->id, 'kind' => $kind, 'channel' => $channel,
                    'coupon_code' => $coupon, 'invoice_id' => $membership->invoice_id, 'membership_id' => $membership->id,
                    'status' => MembershipCheckout::COMPLETED, 'completed_at' => now(),
                ]);
            }

            $items = $draft->getRelation('items');
            $draft->unsetRelation('items');
            $draft->fill(['status' => Invoice::DRAFT, 'number' => null, 'public_token' => null, 'notes' => null])->save();
            InvoiceService::keepItems($draft, $items);

            $checkout = MembershipCheckout::create([
                'member_id' => $member->id, 'membership_plan_id' => $plan->id, 'kind' => $kind, 'channel' => $channel,
                'coupon_code' => $coupon, 'invoice_id' => $draft->id, 'status' => MembershipCheckout::OPEN,
            ]);
            $this->audit->log('membership.checkout_opened', $checkout, ['plan' => $plan->id, 'kind' => $kind, 'channel' => $channel, 'total' => $draft->total]);

            return $checkout;
        });
    }

    /** The member's open checkout, if any — with its draft (to pay it). */
    public static function openFor(Member $member): ?MembershipCheckout
    {
        return MembershipCheckout::with(['invoice', 'plan'])->where('member_id', $member->id)->where('status', MembershipCheckout::OPEN)
            ->latest('id')->first();
    }

    /**
     * Called by OnlinePaymentService with the intent locked, once Paymob confirmed money for it. When the intent's
     * invoice is a checkout's draft, the sale is made now and the intent (with the draft's other attempts and reviews)
     * moves to its invoice; the caller then records the money there. Returns why that money must be held for review
     * (null: apply it as usual). An intent of any other invoice is left as it is.
     */
    public function complete(PaymentIntent $intent): ?string
    {
        $draft = Invoice::withoutGlobalScope('issued')->whereKey($intent->invoice_id)->lockForUpdate()->first();
        if (! $draft || $draft->status !== Invoice::DRAFT) {
            return null;
        }
        $checkout = MembershipCheckout::where('invoice_id', $draft->id)->lockForUpdate()->first();
        if (! $checkout) {
            return null;
        }

        $member = Member::withTrashed()->findOrFail($checkout->member_id);
        $plan = MembershipPlan::withTrashed()->findOrFail($checkout->membership_plan_id);
        $membership = null;
        $problem = null;
        try {
            // Its own savepoint: a sale refused here leaves nothing half-made, and the money still gets an invoice below.
            $membership = DB::transaction(fn () => $this->sell($member, $plan, $checkout->kind, $checkout->coupon_code));
            $invoice = Invoice::whereKey($membership->invoice_id)->firstOrFail();
            if (Money::cmp($invoice->balance(), $intent->amount) !== 0) {
                $problem = 'checkout_amount_changed';
            }
        } catch (BusinessRuleException $e) {
            $invoice = $this->invoices->issue($member, $draft->items()->orderBy('id')->get()->map(fn ($item) => [
                'description' => $item->description, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'discount' => $item->discount,
            ])->all(), [
                'type' => 'membership',
                'branch_id' => $draft->branch_id,
                'notes' => 'اشتراك دُفع إلكترونيًا ولم يُفعَّل تلقائيًا: '.$e->getMessage().' راجِعه وفعّله أو أعد المبلغ.',
            ]);
            $problem = 'checkout_unavailable';
        }

        PaymentIntent::where('invoice_id', $draft->id)->update(['invoice_id' => $invoice->id]);
        PaymentReview::where('invoice_id', $draft->id)->update(['invoice_id' => $invoice->id]);
        $checkout->forceFill([
            'status' => MembershipCheckout::COMPLETED, 'invoice_id' => $invoice->id, 'membership_id' => $membership?->id,
            'problem' => $problem, 'completed_at' => now(),
        ])->save();
        $draft->items()->delete();
        $draft->delete();
        $intent->setAttribute('invoice_id', $invoice->id);

        $this->audit->as('gateway', null, 'paymob', fn () => $this->audit->log('membership.checkout_completed', $checkout, [
            'invoice' => $invoice->id, 'membership' => $membership?->id, 'problem' => $problem,
        ]));

        return $problem;
    }

    /**
     * Could a page of this checkout still take money? One handed out (it has a URL), not paid, until Paymob may still
     * call back after its end (OnlinePaymentService::LATE_CALLBACK_MINUTES); or one the bank has not decided yet.
     */
    public static function mayTakeMoney(MembershipCheckout $checkout): bool
    {
        if ($checkout->invoice_id === null) {
            return false;
        }

        return PaymentIntent::where('invoice_id', $checkout->invoice_id)->whereNotNull('checkout_url')
            ->whereIn('status', [PaymentIntent::PENDING, PaymentIntent::FAILED])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->subMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES)))
            ->exists();
    }

    /** The sale, as the desk makes it — but announced without the invoice message: it is paid on the spot. */
    private function sell(Member $member, MembershipPlan $plan, string $kind, ?string $coupon): Membership
    {
        $options = array_filter(['coupon_code' => $coupon]) + ['invoice_message' => false];

        return $kind === Membership::KIND_RENEWAL
            ? $this->memberships->renew($member, $plan, $options)
            : $this->memberships->sell($member, $plan, $options);
    }
}
