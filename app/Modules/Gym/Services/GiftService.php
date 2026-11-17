<?php

namespace App\Modules\Gym\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\NumberSequence;
use App\Core\Support\Phone;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Events\CreditNoteIssuing;
use App\Modules\Finance\Events\PaymentCancelled;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MembershipPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gift memberships (#192): an existing plan bought for someone else, redeemed once into a membership.
 *
 * Money: the gift is sold on its own invoice (one line, `gift_membership`) and paid only through PaymentService or a
 * signed Paymob notice. InvoicePaid → ActivatePaidItems → {@see markPaid()} is the only way a gift becomes redeemable.
 * Redemption sells the recipient a membership with a zero invoice (MembershipService, option `gift`), which
 * InvoiceService pays on issue: the membership is activated by the same InvoicePaid → ActivatePaidItems path as any
 * other, no payment is made up, and the price is counted once, on the gift's invoice.
 *
 * Locks: the gift's invoice, then the gift, the order a payment takes (invoice, then InvoicePaid → markPaid). Two
 * redemptions at once (staff and recipient) are read one after the other; the second sees the gift redeemed.
 */
final class GiftService
{
    /** How far ahead a redeemed membership may start. */
    public const MAX_START_DAYS = 90;

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly MembershipService $memberships,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Sell a gift: saved as `pending` with its invoice issued to the giver (no member account needed).
     *
     * @param  array{giver_name: string, giver_phone?: string|null, giver_email?: string|null, recipient_name: string, message?: string|null, branch_id?: int|null}  $data
     */
    public function issue(MembershipPlan $plan, array $data, string $source): GiftMembership
    {
        if (! $plan->is_active || ! $plan->giftable) {
            throw new BusinessRuleException('gift.plan', 'هذه الباقة غير متاحة للإهداء.', 'membership_plan_id');
        }

        return DB::transaction(function () use ($plan, $data, $source) {
            $token = Str::random(48);
            $gift = GiftMembership::create([
                'branch_id' => $data['branch_id'] ?? null,
                'number' => NumberSequence::next('gift'),
                'membership_plan_id' => $plan->id,
                'plan_snapshot' => $plan->snapshot() + ['name_en' => $plan->name_en, 'duration_label' => $plan->durationLabel()],
                'price' => Money::normalize($plan->price),
                'source' => $source === 'site' ? 'site' : 'staff',
                'giver_name' => trim($data['giver_name']),
                'giver_phone' => filled($data['giver_phone'] ?? null) ? Phone::normalize($data['giver_phone']) : null,
                'giver_email' => $data['giver_email'] ?? null,
                'recipient_name' => trim($data['recipient_name']),
                // Optional: when given, only an account with this number redeems it from the link (#192 review).
                'recipient_phone' => filled($data['recipient_phone'] ?? null) ? Phone::normalize($data['recipient_phone']) : null,
                'message' => filled($data['message'] ?? null) ? Str::limit(trim($data['message']), 300, '') : null,
                'status' => GiftMembership::PENDING,
                'token_hash' => GiftMembership::hashToken($token),
                'token' => $token,
                'created_by' => Auth::guard('web')->id(),
            ]);

            // A free plan is paid on issue: InvoicePaid reaches markPaid() through the invoice's line, before invoice_id
            // is saved below (only that column is written, so the paid status stays).
            $invoice = $this->invoices->issue(null, [[
                'description' => 'هدية اشتراك '.$gift->number.' — '.$plan->name.' ('.$plan->durationLabel().')، إلى '.$gift->recipient_name,
                'unit_price' => $gift->price,
                'itemable' => $gift,
            ]], [
                'type' => 'gift',
                'branch_id' => $gift->branch_id,
                'customer_phone' => $gift->giver_phone,
                'customer_email' => $gift->giver_email,
                'notes' => 'تبدأ مدة الباقة يوم يختاره المستلم عند استرداد الهدية.',
            ], $gift->giver_name);

            $gift->forceFill(['invoice_id' => $invoice->id])->save();
            $this->audit->log('gift.created', $gift, ['number' => $gift->number, 'source' => $gift->source, 'invoice' => $invoice->number]);

            return $gift->refresh();
        });
    }

    /** InvoicePaid → ActivatePaidItems: the gift's invoice is fully paid, so it can be redeemed from today. */
    public function markPaid(GiftMembership $gift, ?CarbonImmutable $today = null): void
    {
        $today ??= CarbonImmutable::today();
        $gift = GiftMembership::whereKey($gift->id)->lockForUpdate()->first();
        if (! $gift || $gift->status !== GiftMembership::PENDING) {
            return;
        }

        $gift->forceFill([
            'status' => GiftMembership::PAID,
            'paid_at' => now(),
            'expires_on' => $today->addDays($this->redeemDays()),
        ])->save();
        $this->audit->log('gift.paid', $gift, ['number' => $gift->number, 'expires_on' => $gift->expires_on->toDateString()]);
    }

    /** Days a paid gift can be redeemed in (club setting `gift_redeem_days`, 90 by default). */
    public function redeemDays(): int
    {
        return max(1, (int) $this->tenants->require()->setting('gift_redeem_days', GiftMembership::DEFAULT_REDEEM_DAYS));
    }

    /**
     * Redeem the gift into a membership for $member starting on $startsOn — once, whoever asks first.
     *
     * @param  'link'|'staff'  $via
     */
    public function redeem(GiftMembership $gift, Member $member, string $startsOn, string $via, ?CarbonImmutable $today = null): GiftMembership
    {
        $today ??= CarbonImmutable::today();
        $start = CarbonImmutable::parse($startsOn)->startOfDay();
        if ($start->lt($today) || $start->gt($today->addDays(self::MAX_START_DAYS))) {
            throw new BusinessRuleException('gift.start', 'اختر تاريخ بداية من اليوم وحتى 90 يومًا بعده.', 'starts_on');
        }

        return DB::transaction(function () use ($gift, $member, $start, $via, $today) {
            $invoice = $gift->invoice_id ? Invoice::whereKey($gift->invoice_id)->lockForUpdate()->first() : null;
            $gift = GiftMembership::whereKey($gift->id)->lockForUpdate()->firstOrFail();

            $this->assertRedeemable($gift, $invoice, $today);
            // Tied to the recipient's number by the giver: from the link, only that account takes it. Staff, who see who
            // is in front of them, may redeem it for another member (the panel shows the number it was meant for).
            if ($via === 'link' && $gift->recipient_phone !== null && Phone::normalize((string) $member->phone) !== $gift->recipient_phone) {
                throw new BusinessRuleException('gift.not_recipient', 'هذه الهدية مخصصة لرقم جوال آخر. استلميها بالحساب المسجّل برقم المستلمة، أو اطلبي تفعيلها من استقبال النادي.');
            }

            $plan = MembershipPlan::withTrashed()->findOrFail($gift->membership_plan_id);
            try {
                $membership = $this->memberships->sell($member, $plan, [
                    'starts_on' => $start->toDateString(),
                    'gift' => $gift,
                    'branch_id' => $gift->branch_id ?? $member->branch_id,
                ]);
            } catch (BusinessRuleException $e) {
                // The sale's own words speak of renewals and upgrades, which a gift's redemption does not offer.
                throw match ($e->rule) {
                    'membership.overlap' => new BusinessRuleException('membership.overlap', 'يوجد اشتراك آخر يغطي هذه الأيام. اختر تاريخ بداية بعد نهايته.', 'starts_on'),
                    'membership.pending' => new BusinessRuleException('membership.pending', 'يوجد اشتراك بانتظار الدفع على هذا الحساب. أكمل دفعه أو ألغِه قبل استلام الهدية.'),
                    default => $e,
                };
            }

            $gift->forceFill([
                'status' => GiftMembership::REDEEMED,
                'redeemed_at' => now(),
                'member_id' => $member->id,
                'membership_id' => $membership->id,
                'redeemed_via' => $via === 'link' ? 'link' : 'staff',
                'redeemed_by' => Auth::guard('web')->id(),
            ])->save();
            $this->audit->log('gift.redeemed', $gift, ['number' => $gift->number, 'member_id' => $member->id,
                'membership_id' => $membership->id, 'via' => $gift->redeemed_via, 'starts_on' => $start->toDateString()]);

            return $gift;
        });
    }

    /** Why this gift cannot be redeemed now, as the BusinessRuleException a screen shows; nothing when it can. */
    public function assertRedeemable(GiftMembership $gift, ?Invoice $invoice, ?CarbonImmutable $today = null): void
    {
        match (true) {
            $gift->status === GiftMembership::REDEEMED => throw new BusinessRuleException('gift.redeemed', 'استُخدمت هذه الهدية من قبل.'),
            $gift->status === GiftMembership::CANCELLED => throw new BusinessRuleException('gift.cancelled', 'هذه الهدية ملغاة.'),
            $gift->status === GiftMembership::REFUNDED => throw new BusinessRuleException('gift.refunded', 'رُدّ مبلغ هذه الهدية، فلا يمكن استخدامها.'),
            $gift->status === GiftMembership::EXPIRED => throw new BusinessRuleException('gift.expired', 'انتهت مهلة استرداد هذه الهدية.'),
            $gift->status !== GiftMembership::PAID => throw new BusinessRuleException('gift.unpaid', 'لم يكتمل دفع هذه الهدية بعد.'),
            // Refunded (fully or partly) in finance after it was paid: what was given back is not given again.
            ! $invoice || $invoice->status !== Invoice::PAID => throw new BusinessRuleException('gift.refunded', 'رُدّ مبلغ هذه الهدية، فلا يمكن استخدامها.'),
            $gift->isExpired($today) => throw new BusinessRuleException('gift.expired', 'انتهت مهلة استرداد هذه الهدية.'),
            default => null,
        };
    }

    /**
     * Cancel a gift that was not redeemed. An unpaid invoice is voided; money already paid stays on its invoice for
     * finance to refund (payments.refund) — nothing about the sale is deleted.
     */
    public function cancel(GiftMembership $gift, string $reason): GiftMembership
    {
        return DB::transaction(function () use ($gift, $reason) {
            $invoice = $gift->invoice_id ? Invoice::whereKey($gift->invoice_id)->lockForUpdate()->first() : null;
            $gift = GiftMembership::whereKey($gift->id)->lockForUpdate()->firstOrFail();

            if ($gift->status === GiftMembership::REDEEMED) {
                throw new BusinessRuleException('gift.redeemed', 'استُخدمت هذه الهدية، فلا يمكن إلغاؤها.');
            }
            if ($gift->status === GiftMembership::CANCELLED) {
                return $gift;
            }

            $gift->forceFill(['status' => GiftMembership::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => Str::limit($reason, 250, '')])->save();
            $this->audit->log('gift.cancelled', $gift, ['number' => $gift->number, 'reason' => $reason]);

            if ($invoice && $invoice->isOpen() && Money::isZero($invoice->paid_total)) {
                $this->invoices->void($invoice, 'إلغاء الهدية '.$gift->number.': '.$reason);
            }

            return $gift;
        });
    }

    /**
     * CreditNoteIssuing (CreditNoteService::issue, the invoice locked): a gift not used is given back in full or not at
     * all — never in part, which would leave a gift nobody can use and money nobody decided about. Once redeemed it is
     * a membership like any other, refunded as finance sees fit.
     */
    public function guardCredit(CreditNoteIssuing $event): void
    {
        $gift = GiftMembership::where('invoice_id', $event->invoice->id)->lockForUpdate()->first();
        if (! $gift || $gift->status === GiftMembership::REDEEMED) {
            return;
        }
        if (Money::cmp($event->amount, $event->invoice->netTotal()) !== 0) {
            throw new BusinessRuleException('gift.partial_refund', 'هدية الاشتراك غير المستخدمة تُردّ كاملة أو لا تُردّ. المبلغ الكامل: '.$event->invoice->netTotal().'.', 'amount');
        }
    }

    /**
     * The credit note was issued (CreditNote created, inside CreditNoteService::issue, under the invoice's lock): it
     * gave the whole of it back (guardCredit), so the gift is closed. Paid (or cancelled after its money came): refunded.
     * Still waiting for its money: cancelled. Expired: stays expired (its value already left the liability). Redeemed:
     * stays redeemed, and what is credited comes out of the membership's revenue.
     */
    public function refundedBy(CreditNote $note): void
    {
        $gift = GiftMembership::where('invoice_id', $note->invoice_id)->lockForUpdate()->first();
        if (! $gift) {
            return;
        }
        if ($gift->status === GiftMembership::PENDING) {
            $gift->forceFill(['status' => GiftMembership::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => 'invoice_credited'])->save();
            $this->audit->log('gift.cancelled', $gift, ['number' => $gift->number, 'reason' => 'invoice_credited', 'credit_note' => $note->number]);

            return;
        }
        if (! in_array($gift->status, [GiftMembership::PAID, GiftMembership::CANCELLED], true) || $gift->paid_at === null) {
            return;
        }

        $gift->forceFill(['status' => GiftMembership::REFUNDED, 'refunded_at' => now(), 'refund_note_id' => $note->id])->save();
        $this->audit->log('gift.refunded', $gift, ['number' => $gift->number, 'credit_note' => $note->number, 'amount' => $note->amount]);
    }

    /**
     * PaymentCancelled (a payment recorded by mistake, PaymentService::cancel, the invoice locked): a gift no longer
     * fully paid waits for its money again. A redeemed one cannot be taken back that way: the membership was given.
     */
    public function paymentCancelled(PaymentCancelled $event): void
    {
        $gift = GiftMembership::where('invoice_id', $event->invoice->id)->lockForUpdate()->first();
        if (! $gift) {
            return;
        }
        if ($gift->status === GiftMembership::REDEEMED) {
            throw new BusinessRuleException('gift.redeemed_payment', 'استُخدمت الهدية التي دُفعت بها هذه الدفعة، فلا تُلغى. ردّ المبلغ بإشعار دائن.');
        }
        if ($gift->status === GiftMembership::PAID && $event->invoice->status !== Invoice::PAID) {
            $gift->forceFill(['status' => GiftMembership::PENDING, 'paid_at' => null, 'expires_on' => null])->save();
            $this->audit->log('gift.unpaid', $gift, ['number' => $gift->number, 'payment' => $event->payment->id]);
        }
    }

    /**
     * «رابط جديد»: a new secret link for a gift not yet used; the old one stops at once (sent to the wrong person, or
     * seen where it should not have been).
     */
    public function rotateLink(GiftMembership $gift): GiftMembership
    {
        return DB::transaction(function () use ($gift) {
            $gift = GiftMembership::whereKey($gift->id)->lockForUpdate()->firstOrFail();
            if (! in_array($gift->status, [GiftMembership::PENDING, GiftMembership::PAID], true)) {
                throw new BusinessRuleException('gift.closed', 'هذه الهدية لم تعد قابلة للاستخدام، فلا يُصدر لها رابط جديد.');
            }
            $token = Str::random(48);
            $gift->forceFill(['token' => $token, 'token_hash' => GiftMembership::hashToken($token)])->save();
            $this->audit->log('gift.link_rotated', $gift, ['number' => $gift->number]);

            return $gift;
        });
    }

    /**
     * The repair path for a missed InvoicePaid (a gift's invoice paid while this code was rolled back, or a listener
     * that failed): a gift still waiting whose invoice is fully paid is marked paid, its window counted from the day the
     * invoice was paid. Read under the invoice's lock, then the gift's (markPaid). Run nightly by gym:daily-memberships.
     */
    public function reconcilePaid(): int
    {
        $count = 0;
        GiftMembership::where('status', GiftMembership::PENDING)->whereNotNull('invoice_id')
            ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::PAID))
            ->eachById(function (GiftMembership $gift) use (&$count) {
                DB::transaction(function () use ($gift, &$count) {
                    $invoice = Invoice::whereKey($gift->invoice_id)->lockForUpdate()->first();
                    if ($invoice?->status !== Invoice::PAID) {
                        return;
                    }
                    $before = $gift->status;
                    $this->markPaid($gift, CarbonImmutable::parse($invoice->paid_at ?? now())->startOfDay());
                    $count += (int) ($gift->fresh()->status !== $before);
                });
            });

        return $count;
    }

    /**
     * gym:expire-gifts: paid gifts whose window passed unredeemed are stored as expired (and leave the books' liability).
     * Each under its invoice's lock then its own, the order redemption takes, so a redemption in flight wins or waits.
     */
    public function expireDue(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $count = 0;
        GiftMembership::where('status', GiftMembership::PAID)->where('expires_on', '<', $today->toDateString())
            ->eachById(function (GiftMembership $gift) use ($today, &$count) {
                $count += DB::transaction(function () use ($gift, $today) {
                    $gift->invoice_id && Invoice::whereKey($gift->invoice_id)->lockForUpdate()->first();
                    $gift = GiftMembership::whereKey($gift->id)->lockForUpdate()->first();
                    if (! $gift || $gift->status !== GiftMembership::PAID || ! $gift->expires_on?->lt($today)) {
                        return 0;
                    }
                    $gift->forceFill(['status' => GiftMembership::EXPIRED, 'expired_at' => now()])->save();
                    $this->audit->log('gift.expired', $gift, ['number' => $gift->number, 'expires_on' => $gift->expires_on->toDateString()]);

                    return 1;
                });
            });

        return $count;
    }

    /** InvoiceVoided: a gift still waiting for its money is withdrawn with its invoice. */
    public function cancelForVoidedInvoice(Invoice $invoice): void
    {
        GiftMembership::where('invoice_id', $invoice->id)->where('status', GiftMembership::PENDING)->get()
            ->each(function (GiftMembership $gift) {
                $gift->forceFill(['status' => GiftMembership::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => 'invoice_voided'])->save();
                $this->audit->log('gift.cancelled', $gift, ['number' => $gift->number, 'reason' => 'invoice_voided']);
            });
    }
}
