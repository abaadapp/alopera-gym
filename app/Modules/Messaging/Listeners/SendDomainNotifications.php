<?php

namespace App\Modules\Messaging\Listeners;

use App\Modules\Finance\Events\PaymentRecorded;
use App\Modules\Finance\Events\TransferSubmitted;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Events\BookingCancelled;
use App\Modules\Gym\Events\BookingConfirmed;
use App\Modules\Gym\Events\ClassSessionChanged;
use App\Modules\Gym\Events\MemberRegistered;
use App\Modules\Gym\Events\MembershipActivated;
use App\Modules\Gym\Events\MembershipCreated;
use App\Modules\Gym\Events\MembershipExpired;
use App\Modules\Gym\Events\MembershipFrozen;
use App\Modules\Gym\Events\MembershipResumed;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\Member;
use App\Modules\Messaging\Services\MemberNotifier;
use App\Modules\Payments\Events\OnlinePaymentFailed;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns domain events into WhatsApp messages. A messaging problem must never
 * break a sale or a booking, so every handler swallows and logs its errors.
 */
final class SendDomainNotifications
{
    public function __construct(private readonly MemberNotifier $notifier) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            MemberRegistered::class => 'onMemberRegistered',
            MembershipCreated::class => 'onMembershipCreated',
            MembershipActivated::class => 'onMembershipActivated',
            MembershipFrozen::class => 'onMembershipFrozen',
            MembershipResumed::class => 'onMembershipResumed',
            MembershipExpired::class => 'onMembershipExpired',
            PaymentRecorded::class => 'onPaymentRecorded',
            BookingConfirmed::class => 'onBookingConfirmed',
            BookingCancelled::class => 'onBookingCancelled',
            TransferSubmitted::class => 'onTransferSubmitted',
            OnlinePaymentFailed::class => 'onOnlinePaymentFailed',
            ClassSessionChanged::class => 'onClassSessionChanged',
        ];
    }

    public function onMemberRegistered(MemberRegistered $e): void
    {
        $this->safely(fn () => $this->notifier->welcome($e->member));
    }

    public function onMembershipCreated(MembershipCreated $e): void
    {
        $this->safely(function () use ($e) {
            $e->membership->loadMissing(['member', 'invoice']);
            if ($e->membership->invoice) {
                $this->notifier->invoiceIssued($e->membership->member, $e->membership->invoice);
            }
        });
    }

    public function onMembershipActivated(MembershipActivated $e): void
    {
        $this->safely(fn () => $this->notifier->membershipActivated($e->membership));
    }

    public function onMembershipFrozen(MembershipFrozen $e): void
    {
        $this->safely(fn () => $this->notifier->membershipFrozen($e->membership));
    }

    public function onMembershipResumed(MembershipResumed $e): void
    {
        $this->safely(fn () => $this->notifier->membershipResumed($e->membership));
    }

    public function onMembershipExpired(MembershipExpired $e): void
    {
        $this->safely(fn () => $this->notifier->membershipExpired($e->membership));
    }

    public function onPaymentRecorded(PaymentRecorded $e): void
    {
        $this->safely(function () use ($e) {
            $payment = $e->payment;
            if ($payment->payer_type !== 'member' || ! $payment->receipt_number) {
                return;
            }
            $member = Member::find($payment->payer_id);
            if ($member) {
                $this->notifier->paymentReceived($member, (string) $payment->amount, $payment->receipt_number, $payment->id, Invoice::find($payment->invoice_id));
            }
        });
    }

    public function onTransferSubmitted(TransferSubmitted $e): void
    {
        $this->safely(function () use ($e) {
            if ($e->payment->payer_type === 'member' && ($member = Member::find($e->payment->payer_id))) {
                $this->notifier->transferReceived($member, (string) $e->payment->amount, $e->payment->id);
            }
        });
    }

    public function onOnlinePaymentFailed(OnlinePaymentFailed $e): void
    {
        $this->safely(function () use ($e) {
            $invoice = Invoice::find($e->intent->invoice_id);
            if ($invoice && $invoice->billable_type === 'member' && ($member = Member::find($invoice->billable_id))) {
                $this->notifier->paymentFailed($member, $invoice, $e->intent->id);
            }
        });
    }

    public function onClassSessionChanged(ClassSessionChanged $e): void
    {
        $this->safely(function () use ($e) {
            $version = (int) $e->session->updated_at?->timestamp;
            Booking::where('class_session_id', $e->session->id)->where('status', Booking::BOOKED)->get()
                ->each(fn (Booking $b) => $this->notifier->classChanged($b, $e->change, $version));
        });
    }

    public function onBookingConfirmed(BookingConfirmed $e): void
    {
        $this->safely(fn () => $this->notifier->bookingConfirmed($e->booking));
    }

    public function onBookingCancelled(BookingCancelled $e): void
    {
        $this->safely(fn () => $this->notifier->bookingCancelled($e->booking, $e->reason));
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if (app()->runningUnitTests() && config('app.debug_notifications')) {
                throw $e;
            }
            Log::warning('WhatsApp notification skipped: '.$e->getMessage(), ['exception' => $e::class]);
        }
    }
}
