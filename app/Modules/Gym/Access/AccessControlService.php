<?php

namespace App\Modules\Gym\Access;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Models\AccessCredential;
use App\Modules\Gym\Models\AccessDevice;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\CheckIn;
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
    public function __construct(private readonly TenantContext $tenants)
    {
    }

    public function evaluate(?Member $member, ?CarbonImmutable $today = null): AccessDecision
    {
        $today ??= CarbonImmutable::today();

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

        $active = $memberships->first(fn (Membership $m) => $m->status === Membership::ACTIVE && $m->coversDate($today));

        if ($active) {
            if ($this->tenants->require()->setting('block_access_with_dues') && $this->hasOverdueDues($member)) {
                return AccessDecision::deny('dues_outstanding', $member, $active);
            }

            return AccessDecision::allow($member, $active);
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
     */
    public function checkIn(?Member $member, string $method, ?AccessDevice $device = null, ?string $credentialHint = null): AccessDecision
    {
        $decision = $device && ! $device->acceptsMembers()
            ? AccessDecision::deny('device_not_for_members', $member)
            : $this->evaluate($member);

        DB::transaction(function () use ($decision, $member, $method, $device, $credentialHint) {
            CheckIn::create([
                'branch_id' => $device?->branch_id ?? $member?->branch_id,
                'member_id' => $member?->id,
                'membership_id' => $decision->membership?->id,
                'access_device_id' => $device?->id,
                'method' => $method,
                'direction' => $device?->direction === 'out' ? 'out' : 'in',
                'result' => $decision->allowed ? 'allowed' : 'denied',
                'reason' => $decision->reason,
                'credential_hint' => $credentialHint,
                'performed_by' => Auth::guard('web')->id(),
                'checked_in_at' => now(),
            ]);

            if ($decision->allowed && $member) {
                $member->forceFill(['last_check_in_at' => now()])->saveQuietly();
                $this->markTodaysBookingsAttended($member);
            }
        });

        return $decision;
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

    /** Staff clock in / out by alternating punches within the day. */
    public function staffPunch(User $user, ?AccessDevice $device = null, string $method = 'manual'): StaffAttendance
    {
        return DB::transaction(function () use ($user, $device, $method) {
            $open = StaffAttendance::where('user_id', $user->id)
                ->whereNull('clock_out_at')
                ->where('clock_in_at', '>=', now()->startOfDay())
                ->lockForUpdate()
                ->latest('clock_in_at')
                ->first();

            if ($open) {
                $open->forceFill(['clock_out_at' => now()])->save();

                return $open;
            }

            return StaffAttendance::create([
                'user_id' => $user->id,
                'branch_id' => $device?->branch_id ?? $user->branch_id,
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
