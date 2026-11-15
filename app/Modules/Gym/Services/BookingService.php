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
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * Attended or absent — or a correction of either, as before — on a booking still alive, whose membership covered the
     * class when it began (#174). The class, then the booking, then its membership, as every booking path takes them,
     * each read again under its lock: the copy the page carried may since have been cancelled, released by a
     * cancellation, freeze or expiry, or carried to an upgrade, and saving it turned a cancelled booking into attended.
     * Neither the seat nor the counted class changes here: they were taken by the booking.
     */
    public function markAttendance(Booking $booking, string $status): Booking
    {
        if (! in_array($status, [Booking::ATTENDED, Booking::NO_SHOW], true)) {
            $this->fail('attendance.status', 'حالة حضور غير صالحة.');
        }

        return DB::transaction(function () use ($booking, $status) {
            $session = ClassSession::whereKey($booking->class_session_id)->lockForUpdate()->firstOrFail();
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($booking->status === Booking::CANCELLED) {
                $this->fail('attendance.cancelled', 'الحجز ملغى.');
            }
            if ($session->status === ClassSession::CANCELLED) {
                $this->fail('attendance.session_cancelled', 'الحصة ملغاة.');
            }
            if ($session->starts_at->gt(now()->addMinutes(30))) {
                $this->fail('attendance.too_early', 'لا يمكن تسجيل الحضور قبل موعد الحصة.');
            }
            if ($booking->membership_id) {
                $why = $this->notCovering(Membership::whereKey($booking->membership_id)->lockForUpdate()->first(), $session);
                if ($why !== null) {
                    $this->fail('attendance.membership', 'لا يُسجَّل الحضور: '.$why);
                }
            }

            $booking->forceFill([
                'status' => $status,
                'attended_at' => $status === Booking::ATTENDED ? ($booking->attended_at ?? now()) : null,
                'marked_by' => Auth::guard('web')->id(),
            ])->save();

            return $booking->setRelation('session', $session);
        });
    }

    /**
     * Why a membership did not cover a class when it began, or null when it did — by the moments the settlement of its
     * bookings uses (settle()), so a booking is marked exactly when it is not released: paid, the class inside its period,
     * and not cancelled or frozen before the class began (a class begun before is history it covered); a paid period
     * waiting behind a freeze (heldByFreeze) covers nothing.
     */
    private function notCovering(?Membership $membership, ClassSession $session): ?string
    {
        if (! $membership) {
            return 'العضوية المرتبطة بالحجز غير موجودة.';
        }
        $date = $session->starts_at->startOfDay();

        return match (true) {
            $membership->status === Membership::PENDING => 'العضوية غير مدفوعة.',
            $membership->status === Membership::CANCELLED && ! $membership->cancelled_at?->gte($session->starts_at) => 'العضوية أُلغيت قبل موعد الحصة.',
            $membership->status === Membership::FROZEN && ! $membership->openFreeze?->created_at?->gte($session->starts_at) => 'العضوية مجمّدة منذ ما قبل موعد الحصة.',
            $membership->status === Membership::SCHEDULED && $membership->heldByFreeze() => 'هذه الفترة تنتظر انتهاء تجميد العضوية السابقة.',
            $membership->ends_on->lt($date) => 'الحصة بعد نهاية العضوية.',
            $membership->starts_on->gt($date) => 'الحصة قبل بداية العضوية.',
            default => null,
        };
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

    /**
     * Release future bookings when a membership is frozen, cancelled or expires: classes after $since (now, unless the
     * decision was taken earlier — a cancellation settled after its commit counts from the moment it was cancelled).
     */
    public function cancelFutureForMembership(Membership $membership, string $reason, ?CarbonInterface $since = null): int
    {
        $bookings = Booking::where('membership_id', $membership->id)
            ->where('status', Booking::BOOKED)
            ->whereHas('session', fn ($q) => $q->where('starts_at', '>', $since ?? now()))
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
     * What a cancelled membership's future bookings become — run after the transaction that cancelled it (a cancellation,
     * or the payment of the upgrade that replaced it) has committed, never inside it: a booking locks its class, then the
     * membership, so a payment or a cancellation must not lock a class while it holds the membership (the two deadlocked,
     * and one of them was the Paymob callback). Replaced by an upgrade (`upgraded`): carried to it where it allows, else
     * released; cancelled otherwise: released. What is left to do is read from the bookings themselves (a booked future
     * class on a cancelled membership), so gym:settle-bookings finishes whatever a crash or a failure left; each booking
     * is moved or released once however many times this runs (each in its own transaction, read again under its class's
     * lock). The classes are those after the moment it was cancelled (`cancelled_at`), as they were when that was decided:
     * one that started before this ran is settled all the same (a class already marked attended or absent is history and
     * is left as it is). An upgrade no longer active or scheduled takes nothing: its bookings are released.
     */
    public function settleCancelled(Membership $membership): int
    {
        $membership = Membership::find($membership->id);
        if (! $membership || $membership->status !== Membership::CANCELLED) {
            return 0;
        }
        $since = $membership->cancelled_at ?? now();
        if ($membership->cancel_reason === 'upgraded') {
            $upgrade = Membership::where('previous_membership_id', $membership->id)->where('kind', Membership::KIND_UPGRADE)
                ->whereIn('status', [Membership::ACTIVE, Membership::SCHEDULED])->latest('id')->first();

            return $upgrade ? $this->carryFuture($membership, $upgrade, 'الترقية', $since) : $this->cancelFutureForMembership($membership, 'الترقية', $since);
        }

        return $this->cancelFutureForMembership($membership, 'إلغاء العضوية', $since);
    }

    /**
     * What the bookings of a membership that can no longer use them become, by its status now: cancelled
     * (settleCancelled), frozen (settleFrozen) or expired (settleExpired); anything else keeps its bookings. Run after the
     * transaction that changed the status has committed, and by gym:settle-bookings for whatever a crash or a failure left.
     */
    public function settle(Membership $membership): int
    {
        return match (Membership::find($membership->id)?->status) {
            Membership::CANCELLED => $this->settleCancelled($membership),
            Membership::FROZEN => $this->settleFrozen($membership),
            Membership::EXPIRED => $this->settleExpired($membership),
            default => 0,
        };
    }

    /**
     * A frozen membership's bookings — as a cancellation's (settleCancelled), after the freeze has committed, never inside
     * it: the freeze holds the membership, a booking (or the member cancelling one) holds its class and then waits for the
     * membership, and the two deadlocked. Released: the classes after the moment it was frozen (its open freeze), on it
     * and on the member's paid periods after it, which wait for the resume (heldByFreeze) and book nothing. Each once,
     * however many times this runs. A membership resumed since keeps what it has: it can use it again.
     */
    public function settleFrozen(Membership $membership): int
    {
        $membership = Membership::find($membership->id);
        if (! $membership || $membership->status !== Membership::FROZEN) {
            return 0;
        }
        $since = $membership->openFreeze?->created_at ?? now();

        return $this->heldByTheFreeze($membership)
            ->sum(fn (Membership $m) => $this->cancelFutureForMembership($m, 'تجميد العضوية', $since));
    }

    /** An expired membership's bookings after the last day of its period (none, unless a crash left them): released. */
    public function settleExpired(Membership $membership): int
    {
        $membership = Membership::find($membership->id);
        if (! $membership || $membership->status !== Membership::EXPIRED) {
            return 0;
        }

        return $this->cancelFutureForMembership($membership, 'انتهاء العضوية', $membership->ends_on->endOfDay());
    }

    /** How many bookings settle() would still carry or release (nothing is changed). */
    public function leftToSettle(Membership $membership): int
    {
        $membership = Membership::find($membership->id);
        [$memberships, $since] = match ($membership?->status) {
            Membership::CANCELLED => [collect([$membership]), $membership->cancelled_at ?? now()],
            Membership::FROZEN => [$this->heldByTheFreeze($membership), $membership->openFreeze?->created_at ?? now()],
            Membership::EXPIRED => [collect([$membership]), $membership->ends_on->endOfDay()],
            default => [collect(), now()],
        };

        return $memberships->isEmpty() ? 0 : Booking::whereIn('membership_id', $memberships->map->getKey()->all())->where('status', Booking::BOOKED)
            ->whereHas('session', fn ($q) => $q->where('starts_at', '>', $since))->count();
    }

    /**
     * The frozen membership and the member's paid periods after it (held by the freeze until the resume moves them back).
     *
     * @return Collection<int, Membership>
     */
    private function heldByTheFreeze(Membership $frozen): Collection
    {
        return Membership::where('member_id', $frozen->member_id)->whereKeyNot($frozen->id)
            ->where('status', Membership::SCHEDULED)->where('starts_on', '>', $frozen->starts_on)->orderBy('id')->get()
            ->prepend($frozen);
    }

    /**
     * An upgrade: the old membership's future bookings count on the new one where its plan, dates and sessions allow
     * it; the others are released, as when a membership is cancelled. A new plan without classes keeps none of them.
     * Each booking in its own transaction, the class locked before the booking and the memberships (see settleCancelled).
     */
    public function carryFuture(Membership $from, Membership $to, string $reason, ?CarbonInterface $since = null): int
    {
        $bookings = Booking::where('membership_id', $from->id)
            ->where('status', Booking::BOOKED)
            ->whereHas('session', fn ($q) => $q->where('starts_at', '>', $since ?? now()))
            ->orderBy('id')
            ->get();

        foreach ($bookings as $booking) {
            DB::transaction(function () use ($booking, $from, $to, $reason) {
                $session = ClassSession::whereKey($booking->class_session_id)->lockForUpdate()->firstOrFail();
                $booking = $this->stillBooked($booking);
                if (! $booking || $booking->membership_id !== $from->id) {
                    return; // cancelled, or already carried, since the list was read
                }
                $target = Membership::whereKey($to->id)->lockForUpdate()->firstOrFail();
                $date = $session->starts_at->startOfDay();
                if (! (in_array($target->status, [Membership::ACTIVE, Membership::SCHEDULED], true) && $target->starts_on->lte($date) && $target->ends_on->gte($date)
                    && $target->booksClasses() && $this->programAllowed($target, $session) && $target->hasSessionsLeft())) {
                    $this->release($booking, $session, $reason);
                    BookingCancelled::dispatch($booking, $reason);

                    return;
                }

                if ($booking->consumed_session) {
                    Membership::whereKey($from->id)->where('sessions_used', '>', 0)->decrement('sessions_used');
                }
                $consumes = $target->sessions_total !== null;
                if ($consumes) {
                    Membership::whereKey($target->id)->increment('sessions_used');
                }
                $booking->forceFill(['membership_id' => $target->id, 'consumed_session' => $consumes])->save();
            });
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
