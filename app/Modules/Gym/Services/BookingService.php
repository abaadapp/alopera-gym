<?php

namespace App\Modules\Gym\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantContext;
use App\Modules\Gym\Events\BookingCancelled;
use App\Modules\Gym\Events\BookingConfirmed;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\Program;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Class bookings. Capacity is enforced under a row lock on the session, so
 * the last seat can only be taken once even when the website and the front
 * desk book at the same instant.
 */
final class BookingService
{
    public const SOURCES = ['reception', 'portal', 'website', 'trainer'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    public function book(Member $member, ClassSession $session, string $source = 'reception'): Booking
    {
        $byStaff = $source !== 'portal' && $source !== 'website';
        $tenant = $this->tenants->require();

        return DB::transaction(function () use ($member, $session, $source, $byStaff, $tenant) {
            $session = ClassSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->status !== ClassSession::SCHEDULED) {
                $this->fail('session.unavailable', 'هذه الحصة ملغاة أو منتهية.');
            }
            // A paused program takes no new bookings, from the website, the portal or the desk; the bookings it has keep
            // going (attendance, cancelling). Read under a share lock on the program: a pause being saved waits for a
            // booking already past this line, and a booking that comes after the pause is saved sees it.
            if (! Program::whereKey($session->program_id)->sharedLock()->value('is_active')) {
                $this->fail('program.paused', 'هذا البرنامج موقوف، فلا حجوزات جديدة على حصصه.');
            }
            if ($session->starts_at->lte(now())) {
                $this->fail('session.started', 'بدأت الحصة، لا يمكن الحجز.');
            }
            $window = (int) $tenant->setting('booking_window_days', 14);
            if (! $byStaff && $session->starts_at->gt(now()->addDays($window))) {
                $this->fail('session.window', __('الحجز متاح قبل :days يومًا فقط من موعد الحصة.', ['days' => $window]));
            }
            if ($member->status !== Member::ACTIVE) {
                $this->fail('member.inactive', 'حساب العضو غير نشط.');
            }

            $membership = $this->usableMembership($member, $session);

            if (Booking::where('class_session_id', $session->id)->where('member_id', $member->id)->where('status', '!=', Booking::CANCELLED)->exists()) {
                $this->fail('booking.duplicate', 'العضو محجوز في هذه الحصة بالفعل.');
            }
            if ($session->booked_count >= $session->capacity) {
                $this->fail('session.full', 'اكتملت مقاعد هذه الحصة.');
            }

            $overlap = Booking::where('member_id', $member->id)
                ->where('status', Booking::BOOKED)
                ->whereHas('session', fn ($q) => $q->where('status', ClassSession::SCHEDULED)
                    ->where('starts_at', '<', $session->ends_at)
                    ->where('ends_at', '>', $session->starts_at))
                ->exists();
            if ($overlap) {
                $this->fail('booking.overlap', 'لدى العضو حجز آخر في نفس الوقت.');
            }

            $consumes = $membership->sessions_total !== null;
            if ($consumes) {
                Membership::whereKey($membership->id)->increment('sessions_used');
            }

            $booking = Booking::create([
                'class_session_id' => $session->id,
                'member_id' => $member->id,
                'membership_id' => $membership->id,
                'status' => Booking::BOOKED,
                'source' => $source,
                'consumed_session' => $consumes,
                'booked_by' => Auth::guard('web')->id(),
            ]);

            $session->increment('booked_count');

            BookingConfirmed::dispatch($booking);

            return $booking;
        });
    }

    /** The membership that pays for this booking, or a clear refusal. */
    public function usableMembership(Member $member, ClassSession $session): Membership
    {
        $date = $session->starts_at->startOfDay();
        $candidates = Membership::where('member_id', $member->id)
            ->whereIn('status', [Membership::ACTIVE, Membership::SCHEDULED, Membership::FROZEN, Membership::PENDING])
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->lockForUpdate()
            ->get();

        // A renewal waiting behind a frozen period has no dates yet (the resume moves it back): it books nothing.
        $held = $candidates->filter(fn (Membership $m) => $m->status === Membership::SCHEDULED && $m->heldByFreeze());
        $usable = $candidates->diff($held)->first(fn (Membership $m) => in_array($m->status, [Membership::ACTIVE, Membership::SCHEDULED], true)
            && $m->booksClasses() && $this->programAllowed($m, $session) && $m->hasSessionsLeft());

        if ($usable) {
            return $usable;
        }

        if ($held->isNotEmpty() || $candidates->contains(fn ($m) => $m->status === Membership::FROZEN)) {
            $this->fail('membership.frozen', 'العضوية مجمّدة حاليًا.');
        }
        if ($candidates->contains(fn ($m) => $m->status === Membership::PENDING)) {
            $this->fail('membership.unpaid', 'العضوية بانتظار الدفع. تُفعّل بعد تأكيد الدفع.');
        }
        $paid = $candidates->filter(fn ($m) => in_array($m->status, [Membership::ACTIVE, Membership::SCHEDULED], true));
        // A «gym» plan sold from #70 on opens the door, not the classes.
        if ($paid->isNotEmpty() && ! $paid->contains(fn (Membership $m) => $m->booksClasses())) {
            $this->fail('membership.gym_only', 'باقة العضو لدخول النادي فقط، ولا تشمل الحصص.');
        }
        $active = $paid->first(fn (Membership $m) => $m->booksClasses());
        if ($active && ! $this->programAllowed($active, $session)) {
            $this->fail('membership.program', 'باقة العضو لا تشمل هذا البرنامج.');
        }
        if ($active && ! $active->hasSessionsLeft()) {
            $this->fail('membership.no_sessions', 'تم استهلاك جميع حصص الباقة.');
        }

        $this->fail('membership.none', 'لا توجد عضوية سارية بتاريخ هذه الحصة.');
    }

    private function programAllowed(Membership $membership, ClassSession $session): bool
    {
        $allowed = $membership->allowedProgramIds();

        return $allowed === [] || in_array((int) $session->program_id, $allowed, true);
    }

    /**
     * Cancel a booking. Members must cancel before the cutoff to get the
     * session back; staff can always cancel and restore.
     */
    public function cancel(Booking $booking, bool $byStaff, ?string $reason = null): Booking
    {
        $tenant = $this->tenants->require();

        return DB::transaction(function () use ($booking, $byStaff, $reason, $tenant) {
            // The class before the booking: the order cancelSession() and the releases on a freeze or expiry take them in,
            // so the two never deadlock. A booking's class never changes, so it is read before the lock.
            $session = ClassSession::whereKey($booking->class_session_id)->lockForUpdate()->firstOrFail();
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($booking->status !== Booking::BOOKED) {
                $this->fail('booking.not_active', 'الحجز غير قابل للإلغاء.');
            }

            $cutoff = $session->starts_at->subHours((int) $tenant->setting('booking_cancel_cutoff_hours', 3));
            if (! $byStaff && now()->gte($cutoff)) {
                $this->fail('booking.cutoff', 'انتهى وقت إلغاء الحجز. تواصل مع الاستقبال.');
            }

            $this->release($booking, $session, $reason);
            BookingCancelled::dispatch($booking, $reason);

            return $booking;
        });
    }

    public function markAttendance(Booking $booking, string $status): Booking
    {
        if (! in_array($status, [Booking::ATTENDED, Booking::NO_SHOW], true)) {
            $this->fail('attendance.status', 'حالة حضور غير صالحة.');
        }

        $booking->loadMissing('session');
        if ($booking->status === Booking::CANCELLED) {
            $this->fail('attendance.cancelled', 'الحجز ملغى.');
        }
        if ($booking->session->starts_at->gt(now()->addMinutes(30))) {
            $this->fail('attendance.too_early', 'لا يمكن تسجيل الحضور قبل موعد الحصة.');
        }

        $booking->forceFill([
            'status' => $status,
            'attended_at' => $status === Booking::ATTENDED ? ($booking->attended_at ?? now()) : null,
            'marked_by' => Auth::guard('web')->id(),
        ])->save();

        return $booking;
    }

    /** Staff cancel a whole session (trainer sick, hall closed): every booking is released and refunded. */
    public function cancelSession(ClassSession $session, string $reason): ClassSession
    {
        return DB::transaction(function () use ($session, $reason) {
            $session = ClassSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->status !== ClassSession::SCHEDULED) {
                $this->fail('session.unavailable', 'الحصة ليست مجدولة.');
            }

            $bookings = Booking::where('class_session_id', $session->id)->where('status', Booking::BOOKED)->lockForUpdate()->get();
            foreach ($bookings as $booking) {
                $this->release($booking, $session, 'إلغاء الحصة: '.$reason);
                BookingCancelled::dispatch($booking, 'إلغاء الحصة: '.$reason);
            }

            $session->forceFill(['status' => ClassSession::CANCELLED, 'cancel_reason' => $reason])->save();
            $this->audit->log('class_session.cancelled', $session, ['reason' => $reason, 'bookings' => $bookings->count()]);

            return $session;
        });
    }

    /** Release future bookings when a membership is frozen, cancelled or expires. */
    public function cancelFutureForMembership(Membership $membership, string $reason): int
    {
        $bookings = Booking::where('membership_id', $membership->id)
            ->where('status', Booking::BOOKED)
            ->whereHas('session', fn ($q) => $q->where('starts_at', '>', now()))
            ->get();

        $released = 0;
        foreach ($bookings as $booking) {
            $released += (int) DB::transaction(function () use ($booking, $reason) {
                $session = ClassSession::whereKey($booking->class_session_id)->lockForUpdate()->firstOrFail();
                // Read again under the class's lock: the member (or staff) may have cancelled it since the list was read,
                // and releasing it from that copy gave its seat back twice.
                $booking = $this->stillBooked($booking);
                if (! $booking) {
                    return false;
                }
                $this->release($booking, $session, $reason);
                BookingCancelled::dispatch($booking, $reason);

                return true;
            });
        }

        return $released;
    }

    /** The booking re-read under its lock, or null when it is no longer booked. */
    private function stillBooked(Booking $booking): ?Booking
    {
        $booking = Booking::whereKey($booking->id)->lockForUpdate()->first();

        return $booking && $booking->status === Booking::BOOKED ? $booking : null;
    }

    /**
     * An upgrade: the old membership's future bookings count on the new one where its plan, dates and sessions allow
     * it; the others are released, as when a membership is cancelled.
     */
    public function carryFuture(Membership $from, Membership $to, string $reason): int
    {
        $bookings = Booking::where('membership_id', $from->id)
            ->where('status', Booking::BOOKED)
            ->whereHas('session', fn ($q) => $q->where('starts_at', '>', now()))
            ->orderBy('id')
            ->get();

        foreach ($bookings as $booking) {
            $session = ClassSession::whereKey($booking->class_session_id)->lockForUpdate()->firstOrFail();
            $booking = $this->stillBooked($booking);
            if (! $booking) {
                continue; // cancelled since the list was read
            }
            $target = Membership::whereKey($to->id)->lockForUpdate()->firstOrFail();
            $date = $session->starts_at->startOfDay();
            if (! ($target->starts_on->lte($date) && $target->ends_on->gte($date) && $this->programAllowed($target, $session) && $target->hasSessionsLeft())) {
                $this->release($booking, $session, $reason);
                BookingCancelled::dispatch($booking, $reason);

                continue;
            }

            if ($booking->consumed_session) {
                Membership::whereKey($from->id)->where('sessions_used', '>', 0)->decrement('sessions_used');
            }
            $consumes = $target->sessions_total !== null;
            if ($consumes) {
                Membership::whereKey($target->id)->increment('sessions_used');
            }
            $booking->forceFill(['membership_id' => $target->id, 'consumed_session' => $consumes])->save();
        }

        return $bookings->count();
    }

    /**
     * After a session ends, bookings nobody marked become no-shows and the
     * session is closed.
     */
    public function closeFinishedSessions(): int
    {
        $sessions = ClassSession::where('status', ClassSession::SCHEDULED)->where('ends_at', '<', now()->subHour())->get();

        foreach ($sessions as $session) {
            DB::transaction(function () use ($session) {
                if ($this->tenants->require()->setting('auto_mark_no_show', true)) {
                    Booking::where('class_session_id', $session->id)->where('status', Booking::BOOKED)
                        ->update(['status' => Booking::NO_SHOW, 'updated_at' => now()]);
                }
                $session->forceFill(['status' => ClassSession::COMPLETED])->save();
            });
        }

        return $sessions->count();
    }

    private function release(Booking $booking, ClassSession $session, ?string $reason): void
    {
        $booking->forceFill([
            'status' => Booking::CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();

        if ($session->booked_count > 0) {
            $session->decrement('booked_count');
        }

        if ($booking->consumed_session && $booking->membership_id) {
            Membership::whereKey($booking->membership_id)->where('sessions_used', '>', 0)->decrement('sessions_used');
            $booking->forceFill(['consumed_session' => false])->save();
        }
    }

    private function fail(string $rule, string $message): never
    {
        throw new BusinessRuleException($rule, $message, 'booking');
    }
}
