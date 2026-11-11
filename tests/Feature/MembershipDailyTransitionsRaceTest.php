<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * The 00:10 job (runDailyTransitions) and staff changing the same period at that moment, in separate processes on
 * PostgreSQL. The job re-reads each period under its lock before changing its status, so a period cancelled while the
 * job runs stays cancelled: it never opens the door again.
 */
class MembershipDailyTransitionsRaceTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    public function test_a_period_cancelled_while_the_daily_job_starts_it_stays_cancelled(): void
    {
        $club = $this->club();
        $plan = $this->plan($club);
        $member = $this->member($club);
        $today = CarbonImmutable::today();

        // A paid period starting today that the 00:10 job has not started yet.
        $membership = $this->asTenant($club, function () use ($member, $plan, $today) {
            $m = app(MembershipService::class)->sell($member, $plan, ['starts_on' => $today->addDay()->toDateString()]);
            app(PaymentService::class)->record($m->invoice()->first(), $m->total, 'cash');
            $m = $m->fresh();
            $this->assertSame(Membership::SCHEDULED, $m->status);
            $m->forceFill(['starts_on' => $today, 'ends_on' => $plan->endDateFrom($today)])->saveQuietly();

            return $m->fresh();
        });

        $results = $this->concurrently([
            // The daily job, slowed down right after it read the periods due to start.
            function (string $dir) use ($club) {
                DB::listen(function ($query) use ($dir) {
                    if (str_contains($query->sql, 'from "gym_memberships"') && str_contains($query->sql, '"starts_on" <= ?') && ! is_file("$dir/inside")) {
                        file_put_contents("$dir/inside", '1');
                        usleep(1_500_000);
                    }
                });

                return $this->asTenant($club, fn () => app(MembershipService::class)->runDailyTransitions());
            },
            // Meanwhile staff cancel that period.
            function (string $dir) use ($club, $membership) {
                self::waitFor("$dir/inside");

                return $this->asTenant($club, fn () => app(MembershipService::class)->cancel($membership, 'customer asked')->status);
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($results[1]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(Membership::CANCELLED, $results[1]['value']);

        $this->asTenant($club, function () use ($membership, $member) {
            $fresh = Membership::find($membership->id);
            $this->assertSame(Membership::CANCELLED, $fresh->status, 'cancelled at '.$fresh->cancelled_at.' but status is '.$fresh->status);
            $this->assertFalse(app(AccessControlService::class)->evaluate($member->fresh())->allowed, 'a cancelled membership opens the door');
        });
    }

    public function test_a_period_cancelled_while_the_daily_job_expires_it_stays_cancelled(): void
    {
        $club = $this->club();
        $plan = $this->plan($club);
        $member = $this->member($club);
        $today = CarbonImmutable::today();

        // An active period that ended yesterday: the 00:10 job is about to mark it expired.
        $membership = $this->asTenant($club, function () use ($member, $plan, $today) {
            $m = $this->activeMembership(app(TenantContext::class)->require(), $member, $plan);
            $m->forceFill(['starts_on' => $today->subDays(30), 'ends_on' => $today->subDay()])->saveQuietly();

            return $m->fresh();
        });

        $results = $this->concurrently([
            function (string $dir) use ($club) {
                DB::listen(function ($query) use ($dir) {
                    if (str_contains($query->sql, 'from "gym_memberships"') && str_contains($query->sql, '"ends_on" < ?') && ! is_file("$dir/inside")) {
                        file_put_contents("$dir/inside", '1');
                        usleep(1_500_000);
                    }
                });

                return $this->asTenant($club, fn () => app(MembershipService::class)->runDailyTransitions());
            },
            function (string $dir) use ($club, $membership) {
                self::waitFor("$dir/inside");

                return $this->asTenant($club, fn () => app(MembershipService::class)->cancel($membership, 'customer asked')->status);
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($results[1]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->asTenant($club, function () use ($membership) {
            $fresh = Membership::find($membership->id);
            $this->assertSame(Membership::CANCELLED, $fresh->status, 'cancelled at '.$fresh->cancelled_at.' but status is '.$fresh->status);
        });
    }
}
