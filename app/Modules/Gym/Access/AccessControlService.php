<?php

namespace App\Modules\Gym\Access;

use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Models\AccessCredential;
use App\Modules\Gym\Models\AccessDevice;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\StaffAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a member may enter, and records every decision.
 *
 * The same rules serve the reception check-in screen, the QR kiosk and any
 * door hardware connected through the device API.
 */
final class AccessControlService
{
    /** A punch left open since the night before closes at the next punch within this many hours (a late shift). */
    private const OVERNIGHT_SHIFT_HOURS = 14;

    public function __construct(private readonly TenantContext $tenants) {}

    public function evaluate(?Member $member, ?CarbonImmutable $today = null): AccessDecision
    {
        // The club's date (its timezone), as a date of the app: the periods' dates and the day of a class are the club's.
        $today ??= CarbonImmutable::parse(CarbonImmutable::now($this->zone())->toDateString());

        if (! $member) {
            return AccessDecision::deny('unknown_credential');
        }
        if ($member->status === Member::BLOCKED) {
            return AccessDecision::deny('member_blocked', $member);
        }
        if ($member->status !== Member::ACTIVE) {
            return AccessDecision::deny('member_inactive', $member);
        }

        $memberships = Membership::where('member_id', $member->id)
            ->whereIn('status', [Membership::ACTIVE, Membership::FROZEN, Membership::SCHEDULED, Membership::PENDING, Membership::EXPIRED])
            ->orderByDesc('ends_on')
            ->get();

        // By the period's dates in the club's day, from 00:00: a paid period starting today lets her in before the
        // 00:10 job marks it active, and one that ended yesterday does not, though it still says active until then.
        // And by its plan (#70): «gym» and «combo» any day of the period, «classes» on a day she has a class booked here.
        $covering = $memberships->filter(fn (Membership $m) => $m->grantsAccessOn($today));
        $booked = null;
        $opens = function (Membership $m) use ($member, $today, &$booked): bool {
            return $m->opensDoorAnyDay() || ($m->booksClasses() && ($booked ??= $this->hasClassOn($member, $today)));
        };
        $active = $covering->first(fn (Membership $m) => $m->status === Membership::ACTIVE && $opens($m)) ?? $covering->first($opens);

        if ($active) {
            if ($this->tenants->require()->setting('block_access_with_dues') && $this->hasOverdueDues($member)) {
                return AccessDecision::deny('dues_outstanding', $member, $active);
            }

            return AccessDecision::allow($member, $active);
        }
        if ($covering->isNotEmpty()) {
            return AccessDecision::deny('classes_without_booking', $member, $covering->first());
        }

        if ($frozen = $memberships->firstWhere('status', Membership::FROZEN)) {
            return AccessDecision::deny('membership_frozen', $member, $frozen);
        }
        if ($scheduled = $memberships->firstWhere('status', Membership::SCHEDULED)) {
            return AccessDecision::deny('membership_not_started', $member, $scheduled);
        }
        if ($pending = $memberships->firstWhere('status', Membership::PENDING)) {
            return AccessDecision::deny('pending_payment', $member, $pending);
        }
        if ($memberships->isNotEmpty()) {
            return AccessDecision::deny('membership_expired', $member, $memberships->first());
        }

        return AccessDecision::deny('no_membership', $member);
    }

    /**
     * A booking still standing for a class of this club that day: booked, or attended already (she may step out and
     * back in). A cancelled booking, a cancelled class, another day's class and another club's booking do not count.
     * The day runs from the club's midnight to the next, in the club's timezone; class times are kept in the app's.
     */
    private function hasClassOn(Member $member, CarbonImmutable $day): bool
    {
        $start = CarbonImmutable::parse($day->toDateString(), $this->zone());
        $from = $start->setTimezone(config('app.timezone'));
        $to = $start->addDay()->setTimezone(config('app.timezone'));

        return Booking::where('member_id', $member->id)
            ->whereIn('status', [Booking::BOOKED, Booking::ATTENDED])
            ->whereHas('session', fn ($q) => $q->where('status', '!=', ClassSession::CANCELLED)
                ->where('starts_at', '>=', $from)->where('starts_at', '<', $to))
            ->exists();
    }

    /** The club's timezone (Tenant::$timezone), or the app's when none is set. */
    private function zone(): string
    {
        return $this->tenants->get()?->timezone ?: (string) config('app.timezone');
    }

    private function hasOverdueDues(Member $member): bool
    {
        return Invoice::where('billable_type', $member->getMorphClass())
            ->where('billable_id', $member->id)
            ->overdue()
            ->exists();
    }

    /**
     * Evaluate and record. On an allowed entry, today's class bookings close
     * to now are marked attended.
     *
     * Every attempt is logged. A second allowed scan of the same member within the club's
     * duplicate window (setting duplicate_scan_minutes, 0 = off) still lets her in, but is
     * logged as `duplicate` and counts as no new attendance.
     */
    public function checkIn(?Member $member, string $method, ?AccessDevice $device = null, ?string $credentialHint = null): AccessDecision
    {
        $decision = $device && ! $device->acceptsMembers()
            ? AccessDecision::deny('device_not_for_members', $member)
            : $this->evaluate($member);

        return DB::transaction(function () use ($decision, $member, $method, $device, $credentialHint) {
            $entering = $device?->direction !== 'out';
            if ($decision->allowed && $member && $entering && $this->isRepeatEntry($member)) {
                $decision = $decision->asDuplicate();
            }

            CheckIn::create([
                // Where it happened: the device's branch, else the branch of the staff at reception, else the member's.
                'branch_id' => $device?->branch_id ?? app(BranchContext::class)->id() ?? $member?->branch_id,
                'member_id' => $member?->id,
                'membership_id' => $decision->membership?->id,
                'access_device_id' => $device?->id,
                'method' => $method,
                'direction' => $entering ? 'in' : 'out',
                'result' => $decision->isDuplicate() ? 'duplicate' : ($decision->allowed ? 'allowed' : 'denied'),
                'reason' => $decision->reason,
                'credential_hint' => $credentialHint,
                'performed_by' => Auth::guard('web')->id(),
                'checked_in_at' => now(),
            ]);

            if ($decision->allowed && ! $decision->isDuplicate() && $member) {
                $member->forceFill(['last_check_in_at' => now()])->saveQuietly();
                $this->markTodaysBookingsAttended($member);
            }

            return $decision;
        });
    }

    /** An allowed entry of this member inside the duplicate window. Locks the member so two simultaneous reads count once. */
    private function isRepeatEntry(Member $member): bool
    {
        $minutes = (int) $this->tenants->require()->setting('duplicate_scan_minutes', 5);
        if ($minutes <= 0) {
            return false;
        }

        Member::whereKey($member->id)->lockForUpdate()->first();

        return CheckIn::where('member_id', $member->id)->where('result', 'allowed')->where('direction', 'in')
            ->where('checked_in_at', '>=', now()->subMinutes($minutes))->exists();
    }

    private function markTodaysBookingsAttended(Member $member): void
    {
        $window = (int) $this->tenants->require()->setting('checkin_class_window_minutes', 60);

        Booking::where('member_id', $member->id)
            ->where('status', Booking::BOOKED)
            ->whereHas('session', fn ($q) => $q->whereBetween('starts_at', [now()->subMinutes(15), now()->addMinutes($window)]))
            ->update(['status' => Booking::ATTENDED, 'attended_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Who does this credential belong to? QR codes carry the member's token;
     * cards, fingerprints and PINs are looked up in the credential table.
     */
    public function resolve(string $type, string $identifier): Member|User|null
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if ($type === 'qr') {
            return Member::where('qr_token', $identifier)->first();
        }

        $credential = AccessCredential::where('type', $type)->where('identifier', $identifier)->where('is_active', true)->first();
        if (! $credential) {
            return null;
        }

        return $credential->holder_type === 'user'
            ? User::ofTenant($this->tenants->require()->id)->where('is_active', true)->find($credential->holder_id)
            : Member::find($credential->holder_id);
    }

    /**
     * Staff clock in / out by alternating punches within the day. Punches are serialised per person (her user row
     * is locked), and a punch within the duplicate window of her last one — a double scan on the reader — is
     * ignored, so it can neither open a second record nor clock her out the moment she clocked in.
     */
    public function staffPunch(User $user, ?AccessDevice $device = null, string $method = 'manual'): StaffAttendance
    {
        return DB::transaction(function () use ($user, $device, $method) {
            User::whereKey($user->id)->lockForUpdate()->first();

            $window = (int) ($this->tenants->require()->settings['payroll']['punch_window_seconds'] ?? 60);
            $last = StaffAttendance::where('user_id', $user->id)->latest('clock_in_at')->first();
            $lastPunch = $last ? max($last->clock_in_at, $last->clock_out_at ?? $last->clock_in_at) : null;
            if ($last && $window > 0 && abs($lastPunch->diffInSeconds(now())) < $window) {
                return $last;
            }

            // Open today, or open since last night within a shift's length: a shift that ends after midnight closes
            // where it began instead of opening the next day's record (and leaving its own open).
            $since = min(now()->startOfDay(), now()->subHours(self::OVERNIGHT_SHIFT_HOURS));
            $open = StaffAttendance::where('user_id', $user->id)
                ->whereNull('clock_out_at')
                ->where('clock_in_at', '>=', $since)
                ->lockForUpdate()
                ->latest('clock_in_at')
                ->first();

            if ($open) {
                $open->forceFill(['clock_out_at' => now()])->save();

                return $open;
            }

            return StaffAttendance::create([
                'user_id' => $user->id,
                'branch_id' => $device?->branch_id ?? app(BranchContext::class)->id($user),
                'access_device_id' => $device?->id,
                'clock_in_at' => now(),
                'method' => $method,
            ]);
        });
    }

    public static function mask(string $identifier): string
    {
        $len = mb_strlen($identifier);

        return $len <= 4 ? str_repeat('•', $len) : str_repeat('•', min(6, $len - 4)).mb_substr($identifier, -4);
    }
}
