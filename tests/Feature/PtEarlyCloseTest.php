<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSession;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Gym\Services\PtService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** A PT session closed before its start time: managers only, with a reason, audited, never a cancellation. */
class PtEarlyCloseTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function ptSession(Tenant $club, Trainer $trainer, Member $member, CarbonImmutable $startsAt): PtSession
    {
        return $this->asTenant($club, function () use ($trainer, $member, $startsAt) {
            $package = PtPackage::create(['name' => 'PT 4', 'sessions_count' => 4, 'price' => 40, 'validity_days' => 30]);
            $subscription = app(PtService::class)->sell($member, $package, $trainer);
            app(PaymentService::class)->record($subscription->invoice()->first(), $subscription->price, 'cash');

            return PtSession::create([
                'pt_subscription_id' => $subscription->id, 'trainer_id' => $trainer->id, 'member_id' => $member->id,
                'starts_at' => $startsAt, 'ends_at' => $startsAt->addHour(), 'status' => PtSession::SCHEDULED,
            ]);
        });
    }

    public function test_trainer_cannot_close_before_the_start_but_can_after_it(): void
    {
        $club = $this->club();
        $user = $this->staff($club, 'trainer');
        $trainer = $this->asTenant($club, fn () => Trainer::create(['name' => 'Coach', 'user_id' => $user->id]));
        $session = $this->ptSession($club, $trainer, $this->member($club), CarbonImmutable::now()->addHours(3));

        $this->actingAs($user)->post(route('club.pt.close', $session), ['status' => 'completed'])->assertSessionHasErrors('starts_at');
        $this->assertSame(PtSession::SCHEDULED, $this->asTenant($club, fn () => $session->fresh()->status));

        $this->travel(3)->hours();
        $this->travel(5)->minutes();
        $this->actingAs($user)->post(route('club.pt.close', $session), ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($session) {
            $session = $session->fresh();
            $this->assertSame(PtSession::COMPLETED, $session->status);
            $this->assertFalse($session->closed_early);
            $this->assertNotNull($session->closed_at);
        });
    }

    public function test_manager_closes_early_with_a_reason_and_it_is_audited_not_cancelled(): void
    {
        $club = $this->club();
        $manager = $this->staff($club, 'manager');
        $trainer = $this->asTenant($club, fn () => Trainer::create(['name' => 'Coach']));
        $session = $this->ptSession($club, $trainer, $this->member($club), CarbonImmutable::now()->addDay());

        $this->actingAs($manager)->post(route('club.pt.close', $session), ['status' => 'completed'])->assertSessionHasErrors('starts_at');

        $this->actingAs($manager)->post(route('club.pt.close', $session), ['status' => 'completed', 'reason' => 'Member trained earlier today'])
            ->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($session) {
            $session = $session->fresh();
            $this->assertSame(PtSession::COMPLETED, $session->status, 'an early close keeps its status; it is not a cancellation');
            $this->assertTrue($session->closed_early);
            $this->assertSame('Member trained earlier today', $session->early_reason);
            $this->assertNotNull($session->closed_at);
            $this->assertSame(1, $session->subscription->sessions_used, 'it is consumed from the package');
            $this->assertDatabaseHas('audit_logs', ['event' => 'pt.closed_early', 'subject_type' => 'pt_session', 'subject_id' => $session->id]);
        });
    }

    public function test_reception_and_trainers_do_not_have_the_early_close_permission(): void
    {
        $club = $this->club();
        foreach (['reception', 'trainer', 'finance'] as $role) {
            $this->assertFalse($this->staff($club, $role)->can('pt.close_early'), $role);
        }
        $this->assertTrue($this->staff($club, 'manager')->can('pt.close_early'));
        $this->assertTrue($this->owner($club)->can('pt.close_early'));
    }
}
