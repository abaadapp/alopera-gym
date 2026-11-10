<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Access\AccessDecision;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The door follows the period's dates in the club's day (Asia/Muscat) from 00:00, not from the 00:10 job that flips
 * the statuses: a paid period that starts today lets the member in at midnight, one that ended yesterday does not.
 */
class MembershipMidnightAccessTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const TZ = 'Asia/Muscat';

    private function svc(): MembershipService
    {
        return app(MembershipService::class);
    }

    private function pay(Membership $m): void
    {
        $invoice = $m->invoice()->first();
        app(PaymentService::class)->record($invoice, $invoice->balance(), 'cash');
    }

    private function at(string $muscatTime): void
    {
        $this->travelTo(CarbonImmutable::parse($muscatTime, self::TZ));
    }

    private function door(Tenant $club, Member $member): AccessDecision
    {
        return $this->asTenant($club, fn () => app(AccessControlService::class)->evaluate($member->fresh()));
    }

    /** The 00:10 job, as routes/console.php runs it. */
    private function dailyJob(Tenant $club): void
    {
        $this->asTenant($club, fn () => $this->svc()->runDailyTransitions());
    }

    private function tenDays(Tenant $club): MembershipPlan
    {
        return $this->plan($club, ['duration_value' => 10, 'duration_unit' => 'day', 'freeze_allowed' => true, 'max_freeze_days' => 30]);
    }

    /** A member whose paid period runs 1–10 November and whose paid renewal is scheduled for 11 November. */
    private function memberWithPaidRenewal(Tenant $club): array
    {
        $this->at('2026-11-01 12:00:00');
        $member = $this->member($club);
        $plan = $this->tenDays($club);
        $current = $this->activeMembership($club, $member, $plan);
        $renewal = $this->asTenant($club, function () use ($member, $plan) {
            $renewal = $this->svc()->renew($member, $plan);
            $this->pay($renewal);

            return $renewal->fresh();
        });

        $this->assertSame('2026-11-10', $current->ends_on->toDateString());
        $this->assertSame('2026-11-11', $renewal->starts_on->toDateString());
        $this->assertSame(Membership::SCHEDULED, $renewal->status);

        return [$member, $current, $renewal];
    }

    public function test_a_paid_period_starting_today_opens_the_door_from_midnight_before_the_daily_job(): void
    {
        $club = $this->club();
        $this->at('2026-11-01 12:00:00');
        $member = $this->member($club);
        $period = $this->asTenant($club, function () use ($club, $member) {
            $period = $this->svc()->sell($member, $this->tenDays($club), ['starts_on' => '2026-11-11']);
            $this->pay($period);

            return $period->fresh();
        });
        $this->assertSame(Membership::SCHEDULED, $period->status);

        $this->at('2026-11-10 23:59:00');
        $this->assertSame('membership_not_started', $this->door($club, $member)->reason, 'not yet: its day has not come');

        foreach (['2026-11-11 00:00:00', '2026-11-11 00:05:00'] as $time) {
            $this->at($time);
            $decision = $this->door($club, $member);
            $this->assertTrue($decision->allowed, "let in at {$time}, before the 00:10 job: ".$decision->reason);
            $this->assertSame($period->id, $decision->membership?->id);
        }
        $this->assertSame(Membership::SCHEDULED, $period->fresh()->status, 'the door decides, the job still flips the status');

        $this->at('2026-11-11 00:10:00');
        $this->dailyJob($club);
        $this->at('2026-11-11 00:15:00');
        $this->assertSame(Membership::ACTIVE, $period->fresh()->status);
        $this->assertTrue($this->door($club, $member)->allowed, 'still let in after the job');
    }

    public function test_a_paid_renewal_takes_over_at_midnight_with_no_gap_at_the_door(): void
    {
        $club = $this->club();
        [$member, $current, $renewal] = $this->memberWithPaidRenewal($club);

        $this->at('2026-11-10 23:59:00');
        $decision = $this->door($club, $member);
        $this->assertTrue($decision->allowed, 'the last day of the current period, to its last minute');
        $this->assertSame($current->id, $decision->membership?->id);

        $this->at('2026-11-11 00:05:00');
        $decision = $this->door($club, $member);
        $this->assertTrue($decision->allowed, 'the renewal lets her in before the 00:10 job: '.$decision->reason);
        $this->assertSame($renewal->id, $decision->membership?->id);
        $this->assertSame(Membership::ACTIVE, $current->fresh()->status, 'the old period still says active until the job');

        $this->at('2026-11-11 00:10:00');
        $this->dailyJob($club);
        $this->at('2026-11-11 00:15:00');
        $decision = $this->door($club, $member);
        $this->assertTrue($decision->allowed);
        $this->assertSame($renewal->id, $decision->membership?->id);
    }

    public function test_a_period_that_ended_yesterday_does_not_open_the_door_before_the_job_expires_it(): void
    {
        $club = $this->club();
        $this->at('2026-11-01 12:00:00');
        $member = $this->member($club);
        $current = $this->activeMembership($club, $member, $this->tenDays($club));

        $this->at('2026-11-10 23:59:00');
        $this->assertTrue($this->door($club, $member)->allowed, 'its last day');

        $this->at('2026-11-11 00:05:00');
        $this->assertSame(Membership::ACTIVE, $current->fresh()->status, 'the job has not run yet');
        $this->assertSame('membership_expired', $this->door($club, $member)->reason, 'its dates are over, whatever the status says');

        $this->at('2026-11-11 00:10:00');
        $this->dailyJob($club);
        $this->assertSame('membership_expired', $this->door($club, $member)->reason);
    }

    public function test_a_frozen_member_is_not_let_in_by_her_renewal_s_start(): void
    {
        $club = $this->club();
        [$member, $current, $renewal] = $this->memberWithPaidRenewal($club);

        $this->at('2026-11-05 12:00:00');
        $this->asTenant($club, fn () => $this->svc()->freeze($current->fresh(), 30));

        foreach (['2026-11-11 00:00:00', '2026-11-11 00:05:00'] as $time) {
            $this->at($time);
            $this->assertSame('membership_frozen', $this->door($club, $member)->reason, "frozen at {$time}: the renewal waits for the resume");
        }

        $this->at('2026-11-11 00:10:00');
        $this->dailyJob($club);
        $this->at('2026-11-11 00:15:00');
        $this->assertSame(Membership::SCHEDULED, $renewal->fresh()->status);
        $this->assertSame('membership_frozen', $this->door($club, $member)->reason);
    }

    public function test_a_cancelled_period_starting_today_does_not_open_the_door(): void
    {
        $club = $this->club();
        $this->at('2026-11-01 12:00:00');
        $member = $this->member($club);
        $this->asTenant($club, function () use ($club, $member) {
            $period = $this->svc()->sell($member, $this->tenDays($club), ['starts_on' => '2026-11-11']);
            $this->pay($period);
            $this->svc()->cancel($period->fresh(), 'changed mind');
        });

        $this->at('2026-11-11 00:05:00');
        $this->assertFalse($this->door($club, $member)->allowed);
    }

    public function test_the_club_s_day_decides_when_the_instant_comes_in_utc(): void
    {
        $club = $this->club();
        $this->at('2026-11-01 12:00:00');
        $member = $this->member($club);
        $this->asTenant($club, function () use ($club, $member) {
            $period = $this->svc()->sell($member, $this->tenDays($club), ['starts_on' => '2026-11-11']);
            $this->pay($period);
        });

        // 19:55 UTC on the 10th is 23:55 in Muscat: still the day before.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 19:55:00', 'UTC'));
        $this->assertSame('membership_not_started', $this->door($club, $member)->reason);

        // 20:05 UTC on the 10th is 00:05 on the 11th in Muscat: the UTC date is still the 10th, the club's is the 11th.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 20:05:00', 'UTC'));
        $this->assertSame('2026-11-11', CarbonImmutable::today()->toDateString());
        $this->assertTrue($this->door($club, $member)->allowed);
    }

    public function test_class_booking_follows_the_dates_at_midnight_too(): void
    {
        $club = $this->club();
        [$member, , $renewal] = $this->memberWithPaidRenewal($club);
        $session = $this->classSession($club, null, ['starts_at' => CarbonImmutable::parse('2026-11-11 18:00:00', self::TZ)]);

        $this->at('2026-11-11 00:05:00');
        $booking = $this->asTenant($club, fn () => app(BookingService::class)->book($member->fresh(), $session));
        $this->assertSame($renewal->id, $booking->membership_id, 'today\'s class is paid by the renewal, not the period that ended yesterday');
    }
}
