<?php

namespace App\Modules\Messaging\Services;

use App\Core\Support\Money;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Payments\Services\OnlinePaymentService;

/**
 * Builds the values for each event and hands the message to WhatsAppService.
 * Keeps message wording decisions out of the gym and finance services.
 */
final class MemberNotifier
{
    public function __construct(
        private readonly WhatsAppService $whatsapp,
        private readonly TenantContext $tenants,
    ) {}

    public function welcome(Member $member): void
    {
        $this->whatsapp->queueTemplate($member, 'member_welcome', $this->base($member) + [
            'member_no' => $member->member_no,
        ], $member, 'welcome:'.$member->id);
    }

    public function invoiceIssued(Member $member, Invoice $invoice): void
    {
        if (! $invoice->isOpen() || Money::isZero($invoice->balance())) {
            return;
        }

        $this->whatsapp->queueTemplate($member, 'invoice_issued', $this->base($member) + [
            'invoice_number' => $invoice->number,
            'amount' => $this->money($invoice->balance()),
            'payment_link' => $this->paymentLink($invoice),
        ], $invoice, 'invoice:'.$invoice->id);
    }

    public function paymentReceived(Member $member, string $amount, string $receipt, int $paymentId, ?Invoice $invoice = null): void
    {
        $this->whatsapp->queueTemplate($member, 'payment_received', $this->base($member) + [
            'amount' => $this->money($amount),
            'receipt_number' => $receipt,
            'invoice_link' => $invoice ? $this->invoiceLink($invoice) : '',
        ], null, 'payment:'.$paymentId);
    }

    public function transferReceived(Member $member, string $amount, int $paymentId): void
    {
        $this->whatsapp->queueTemplate($member, 'transfer_received', $this->base($member) + [
            'amount' => $this->money($amount),
        ], null, 'transfer:'.$paymentId);
    }

    public function paymentFailed(Member $member, Invoice $invoice, int $intentId): void
    {
        if (! $invoice->isOpen()) {
            return;
        }

        $this->whatsapp->queueTemplate($member, 'payment_failed', $this->base($member) + [
            'amount' => $this->money($invoice->balance()),
            'payment_link' => $this->paymentLink($invoice),
        ], $invoice, 'payment_failed:'.$intentId);
    }

    public function classChanged(Booking $booking, string $change, int $version): void
    {
        $booking->loadMissing(['member', 'session.program']);
        $this->whatsapp->queueTemplate($booking->member, 'class_changed', $this->base($booking->member) + $this->bookingValues($booking) + [
            'change' => $change,
        ], $booking, 'class_changed:'.$booking->id.':'.$version);
    }

    public function membershipFollowup(Membership $membership, int $days): void
    {
        $membership->loadMissing('member');
        $this->whatsapp->queueTemplate($membership->member, 'membership_followup', $this->base($membership->member) + [
            'renew_link' => $this->portalLink('portal.renew'),
        ], $membership, 'followup:'.$membership->id.':'.$days);
    }

    public function membershipActivated(Membership $membership): void
    {
        $membership->loadMissing('member');
        $member = $membership->member;
        $key = $membership->kind === Membership::KIND_RENEWAL ? 'membership_renewed' : 'membership_activated';

        $this->whatsapp->queueTemplate($member, $key, $this->base($member) + $this->membershipValues($membership), $membership, 'activated:'.$membership->id);
    }

    public function membershipFrozen(Membership $membership): void
    {
        $membership->loadMissing('member');
        $this->whatsapp->queueTemplate($membership->member, 'membership_frozen', $this->base($membership->member) + $this->membershipValues($membership),
            $membership, 'frozen:'.$membership->id.':'.$membership->freezes_count);
    }

    public function membershipResumed(Membership $membership): void
    {
        $membership->loadMissing('member');
        $this->whatsapp->queueTemplate($membership->member, 'membership_resumed', $this->base($membership->member) + $this->membershipValues($membership),
            $membership, 'resumed:'.$membership->id.':'.$membership->freezes_count);
    }

    public function membershipExpiring(Membership $membership, int $daysLeft): void
    {
        $membership->loadMissing('member');
        $this->whatsapp->queueTemplate($membership->member, 'membership_expiring', $this->base($membership->member) + $this->membershipValues($membership) + [
            'days_left' => (string) $daysLeft,
            'renew_link' => $this->portalLink('portal.renew'),
        ], $membership, 'expiring:'.$membership->id.':'.$daysLeft);
    }

    public function membershipExpired(Membership $membership): void
    {
        $membership->loadMissing('member');
        $this->whatsapp->queueTemplate($membership->member, 'membership_expired', $this->base($membership->member) + [
            'renew_link' => $this->portalLink('portal.renew'),
        ], $membership, 'expired:'.$membership->id);
    }

    public function bookingConfirmed(Booking $booking): void
    {
        $booking->loadMissing(['member', 'session.program']);
        $this->whatsapp->queueTemplate($booking->member, 'booking_confirmed', $this->base($booking->member) + $this->bookingValues($booking),
            $booking, 'booking:'.$booking->id);
    }

    public function classReminder(Booking $booking): void
    {
        $booking->loadMissing(['member', 'session.program']);
        $this->whatsapp->queueTemplate($booking->member, 'class_reminder', $this->base($booking->member) + $this->bookingValues($booking),
            $booking, 'class_reminder:'.$booking->id);
    }

    public function bookingCancelled(Booking $booking, ?string $reason): void
    {
        $booking->loadMissing(['member', 'session.program']);
        $this->whatsapp->queueTemplate($booking->member, 'booking_cancelled', $this->base($booking->member) + $this->bookingValues($booking) + [
            'reason' => $reason ?: '—',
        ], $booking, 'booking_cancelled:'.$booking->id);
    }

    /** @return array<string, string> */
    private function base(Member $member): array
    {
        return [
            'member_name' => explode(' ', trim($member->full_name))[0] ?: $member->full_name,
            'club_name' => $this->tenants->require()->name,
        ];
    }

    /** @return array<string, string> */
    private function membershipValues(Membership $membership): array
    {
        return [
            'plan_name' => (string) ($membership->plan_snapshot['name'] ?? ''),
            'starts_on' => $membership->starts_on->format('Y/m/d'),
            'ends_on' => $membership->ends_on->format('Y/m/d'),
        ];
    }

    /** @return array<string, string> */
    private function bookingValues(Booking $booking): array
    {
        return [
            'class_name' => $booking->session->program->name,
            'date' => $booking->session->starts_at->locale('ar')->translatedFormat('l j F'),
            'time' => $booking->session->starts_at->format('g:i A'),
        ];
    }

    private function money(string $amount): string
    {
        return number_format((float) $amount, 3).' '.$this->tenants->require()->currency;
    }

    /** The online pay page when the club takes online payments; otherwise the invoice page (no dead pay button). */
    public function paymentLink(Invoice $invoice): string
    {
        if (! app(OnlinePaymentService::class)->available()) {
            return $this->invoiceLink($invoice);
        }

        return route('site.pay.show', ['club' => $this->tenants->require()->slug, 'token' => $invoice->public_token]);
    }

    public function invoiceLink(Invoice $invoice): string
    {
        return route('site.invoice', ['club' => $this->tenants->require()->slug, 'token' => $invoice->public_token]);
    }

    private function portalLink(string $route): string
    {
        return route($route, ['club' => $this->tenants->require()->slug]);
    }
}
