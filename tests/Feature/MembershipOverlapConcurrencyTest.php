<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\MembershipService;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * #70 at the same moment, in separate processes on PostgreSQL. A sale reads the member's periods under the member's
 * lock, so two sales at once are checked one after the other. A sale paid while another is being sold is seen by it.
 * A frozen period resuming while a later period is being sold waits for that sale, then moves it back with the others.
 * Whatever the timing, no two live periods cover the same day, a refused sale leaves no invoice or payment, and nothing
 * deadlocks.
 */
class MembershipOverlapConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    private function sale(Tenant $club, Member $member, MembershipPlan $plan, ?string $startsOn = null): \Closure
    {
        return fn () => $this->asTenant($club, fn () => app(MembershipService::class)->sell($member->fresh(), $plan, array_filter(['starts_on' => $startsOn]))->id);
    }

    /** @return array{memberships: int, invoices: int, payments: int} */
    private function counts(Tenant $club): array
    {
        return $this->asTenant($club, fn () => ['memberships' => Membership::count(), 'invoices' => Invoice::count(), 'payments' => Payment::count()]);
    }

    public function test_two_sales_for_the_same_member_at_once_make_one_membership_and_one_invoice(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club);

        $results = $this->concurrently([
            function (string $dir) use ($club, $member, $plan) {
                self::pauseAfter('insert into "gym_memberships"', $dir, 1.0); // the sale is written, not committed

                return ($this->sale($club, $member, $plan))();
            },
            function (string $dir) use ($club, $member, $plan) {
                self::waitFor("$dir/inside");

                return ($this->sale($club, $member, $plan))();
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($results[1]['ok'], 'the second sale went through: '.json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['memberships' => 1, 'invoices' => 1, 'payments' => 0], $this->counts($club));
    }

    public function test_a_sale_while_the_overlapping_one_is_being_paid_is_refused_and_bills_nothing(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club);
        $waiting = $this->asTenant($club, fn () => app(MembershipService::class)->sell($member->fresh(), $plan));

        $results = $this->concurrently([
            function (string $dir) use ($club, $waiting) {
                self::pauseAfter('update "gym_memberships" set "status"', $dir, 1.0); // paid and activated, not committed

                return $this->asTenant($club, fn () => app(PaymentService::class)->record($waiting->invoice()->firstOrFail(), $waiting->total, 'cash')->id);
            },
            function (string $dir) use ($club, $member, $plan) {
                self::waitFor("$dir/inside");

                return ($this->sale($club, $member, $plan))();
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($results[1]['ok'], 'a second sale over the same days went through: '.json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(Membership::ACTIVE, $this->asTenant($club, fn () => $waiting->fresh()->status));
        $this->assertSame(['memberships' => 1, 'invoices' => 1, 'payments' => 1], $this->counts($club));
    }

    public function test_a_period_sold_while_a_frozen_one_resumes_is_moved_back_with_it(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['freeze_allowed' => true, 'max_freeze_days' => 60, 'max_freezes' => 2]);
        $tenDaysAgo = CarbonImmutable::today()->subDays(10);
        $frozen = $this->asTenant($club, function () use ($member, $plan, $tenDaysAgo) {
            $m = app(MembershipService::class)->sell($member->fresh(), $plan, ['starts_on' => $tenDaysAgo->toDateString()]);
            app(PaymentService::class)->record($m->invoice()->firstOrFail(), $m->total, 'cash');

            return app(MembershipService::class)->freeze($m->fresh(), null, 'سفر', $tenDaysAgo->addDays(4)); // frozen six days ago
        });
        $after = $frozen->ends_on->addDay()->toDateString();

        $results = $this->concurrently([
            // The next period is being sold: written, not committed.
            function (string $dir) use ($club, $member, $plan, $after) {
                self::pauseAfter('insert into "gym_memberships"', $dir, 1.0);

                return ($this->sale($club, $member, $plan, $after))();
            },
            // The frozen one resumes meanwhile: six days are added to it, and what follows moves back by as many.
            function (string $dir) use ($club, $frozen) {
                self::waitFor("$dir/inside");

                return $this->asTenant($club, fn () => app(MembershipService::class)->resume($frozen->fresh())->ends_on->toDateString());
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($results[1]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->asTenant($club, function () use ($frozen, $results) {
            $resumed = $frozen->fresh();
            $sold = Membership::findOrFail($results[0]['value']);
            $this->assertSame($frozen->ends_on->addDays(6)->toDateString(), $resumed->ends_on->toDateString());
            $this->assertSame($resumed->ends_on->addDay()->toDateString(), $sold->starts_on->toDateString(), 'the period sold meanwhile moved back with it');
        });
    }
}
