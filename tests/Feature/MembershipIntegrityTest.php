<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** What one membership step leaves in the others: the invoice, the next period, the bookings, the member file. */
class MembershipIntegrityTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function svc(): MembershipService
    {
        return app(MembershipService::class);
    }

    private function pay(Membership $m, string|float|null $amount = null)
    {
        $invoice = $m->invoice()->first();

        return app(PaymentService::class)->record($invoice, $amount ?? $invoice->balance(), 'cash');
    }

    public function test_a_partly_paid_sale_is_not_cancelled_leaving_its_invoice_open_to_collect(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 30]);

        $this->asTenant($club, function () use ($member, $plan) {
            $m = $this->svc()->sell($member, $plan);
            $this->pay($m, 10);

            try {
                $this->svc()->cancel($m->fresh(), 'changed mind');
                $this->fail('a sale with money on it is not cancelled behind finance');
            } catch (BusinessRuleException $e) {
                $this->assertSame('membership.partly_paid', $e->rule);
            }

            // Nothing half-done: the sale and its invoice are as they were, and paying the rest still activates it.
            $this->assertSame(Membership::PENDING, $m->fresh()->status);
            $this->assertSame(Invoice::PARTIALLY_PAID, $m->invoice()->first()->status);
            $this->pay($m->fresh());
            $this->assertSame(Membership::ACTIVE, $m->fresh()->status);
        });
    }

    public function test_an_unpaid_sale_is_still_withdrawn_with_its_invoice(): void
    {
        $club = $this->club();
        $member = $this->member($club);

        $this->asTenant($club, function () use ($club, $member) {
            $m = $this->svc()->sell($member, $this->plan($club));
            $this->svc()->cancel($m, 'changed mind');

            $this->assertSame(Membership::CANCELLED, $m->fresh()->status);
            $this->assertSame(Invoice::VOID, $m->invoice()->first()->status);
        });
    }

    public function test_freeze_days_push_the_paid_renewal_back_so_the_two_periods_never_overlap(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['duration_value' => 30, 'duration_unit' => 'day', 'freeze_allowed' => true, 'max_freeze_days' => 30]);

        $this->asTenant($club, function () use ($member, $plan) {
            $current = $this->activeMembership(app(TenantContext::class)->require(), $member, $plan);
            $renewal = $this->svc()->renew($member, $plan);
            $this->pay($renewal);
            $renewal = $renewal->fresh();
            $this->assertSame(Membership::SCHEDULED, $renewal->status);
            $length = $renewal->starts_on->diffInDays($renewal->ends_on);

            $this->svc()->freeze($current->fresh(), 10);
            $this->travel(10)->days();
            $this->svc()->resume($current->fresh());

            $current = $current->fresh();
            $renewal = $renewal->fresh();
            $this->assertTrue($renewal->starts_on->eq($current->ends_on->addDay()), 'the renewal starts the day after the frozen period now ends');
            $this->assertSame($length, $renewal->starts_on->diffInDays($renewal->ends_on), 'and keeps the length it was sold with');
        });
    }

    public function test_an_upgrade_pushes_the_renewal_after_it_and_carries_the_future_bookings(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['duration_value' => 30, 'duration_unit' => 'day', 'price' => 30]);
        $better = $this->plan($club, ['name' => 'Gold', 'duration_value' => 60, 'duration_unit' => 'day', 'price' => 90]);
        $session = $this->classSession($club);

        $this->asTenant($club, function () use ($club, $member, $plan, $better, $session) {
            $current = $this->activeMembership($club, $member, $plan);
            $booking = app(BookingService::class)->book($member, $session);
            $this->assertSame($current->id, $booking->membership_id);
            $renewal = $this->svc()->renew($member, $plan);
            $this->pay($renewal);

            $upgrade = $this->svc()->upgrade($current->fresh(), $better);
            $this->pay($upgrade);
            $upgrade = $upgrade->fresh();

            $this->assertSame(Membership::ACTIVE, $upgrade->status);
            $this->assertSame(Membership::CANCELLED, $current->fresh()->status);
            $this->assertTrue($renewal->fresh()->starts_on->gt($upgrade->ends_on), 'the paid renewal follows the upgrade, not inside it');

            $booking = $booking->fresh();
            $this->assertSame(Booking::BOOKED, $booking->status);
            $this->assertSame($upgrade->id, $booking->membership_id, 'the booking now counts on the membership in force');
        });
    }

    public function test_a_member_with_a_sale_waiting_for_payment_or_training_ahead_is_not_deleted(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);
        $this->asTenant($club, fn () => $this->svc()->sell($member, $this->plan($club)));

        $this->actingAs($owner)->delete(route('club.members.destroy', $member))->assertSessionHas('error');

        $this->asTenant($club, fn () => $this->assertNotNull($member->fresh(), 'the member is kept'));
    }

    public function test_a_late_payment_gives_the_length_that_was_sold_even_if_the_plan_changed_since(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['duration_value' => 30, 'duration_unit' => 'day']);
        $m = $this->asTenant($club, fn () => $this->svc()->sell($member, $plan));
        $plan->update(['duration_value' => 7]);
        $this->travel(2)->days();

        $this->asTenant($club, function () use ($m) {
            $this->pay($m);
            $m = $m->fresh();
            $this->assertTrue($m->starts_on->eq(CarbonImmutable::today()));
            $this->assertTrue($m->ends_on->eq(CarbonImmutable::today()->addDays(29)), 'thirty days, as sold');
        });
    }

    public function test_the_daily_run_starts_every_period_due_however_many_there_are(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club);
        $row = ['tenant_id' => $club->id, 'member_id' => $member->id, 'membership_plan_id' => $plan->id, 'status' => Membership::SCHEDULED,
            'starts_on' => today()->toDateString(), 'ends_on' => today()->addDays(29)->toDateString(), 'price' => 10, 'total' => 10, 'plan_snapshot' => '{}',
            'created_at' => now(), 'updated_at' => now()];
        // More than one page of the query: paging by offset while each row leaves the filter skipped every other page.
        DB::table('gym_memberships')->insert(array_fill(0, 1205, $row));

        $counts = $this->asTenant($club, fn () => $this->svc()->runDailyTransitions());

        $this->assertSame(1205, $counts['started']);
        $this->asTenant($club, fn () => $this->assertSame(0, Membership::where('status', Membership::SCHEDULED)->count()));
    }
}
