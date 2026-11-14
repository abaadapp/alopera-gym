<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Gym\Models\MembershipPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A plan and the programs it opens are saved together or not at all: a link that fails after the plan's row was
 * written leaves the plan as it was (never the new price with old or missing programs), and what is saved is what the
 * plans page shows when opened again. Memberships already sold keep the terms they were sold with.
 */
class PlanSavingTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private bool $failLinks = false;

    protected function setUp(): void
    {
        parent::setUp();
        // The programs' links fail as the database would (a lost connection, a full disk) at their first write: on an
        // edit, once the old links are removed and before the new ones are in.
        DB::listen(function ($query) {
            if ($this->failLinks && preg_match('/^(insert into|delete from) "gym_plan_program"/', $query->sql)) {
                throw new RuntimeException('database down');
            }
        });
    }

    private function form(array $programIds, string $price, array $extra = []): array
    {
        return $extra + ['name' => 'شهري', 'allows_gym' => true, 'allows_classes' => true, 'duration_value' => 1, 'duration_unit' => 'month',
            'price' => $price, 'is_active' => true, 'is_public' => true, 'program_ids' => $programIds];
    }

    /** What the plans page shows for the plan, opened anew. */
    private function shown(User $owner, int $planId): array
    {
        $plan = [];
        $this->actingAs($owner)->get(route('club.plans.index'))->assertOk()->assertInertia(function ($page) use (&$plan, $planId) {
            $plan = collect($page->toArray()['props']['plans'])->firstWhere('id', $planId);
        });
        sort($plan['program_ids']);

        return [(string) $plan['price'], $plan['program_ids']];
    }

    public function test_a_failed_link_to_programs_keeps_the_plan_as_it_was(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$yoga, $spin, $box] = [$this->program($club, ['name' => 'يوغا']), $this->program($club, ['name' => 'سبين']), $this->program($club, ['name' => 'ملاكمة'])];

        $this->actingAs($owner)->post(route('club.plans.store'), $this->form([$yoga->id, $spin->id], '20'))->assertSessionHasNoErrors();
        $plan = $this->asTenant($club, fn () => MembershipPlan::sole());
        $member = $this->member($club);
        $sold = $this->activeMembership($club, $member, $plan);

        // The new price with new programs, and the link fails half-way: nothing of the change stays.
        $this->failLinks = true;
        $this->actingAs($owner)->put(route('club.plans.update', $plan), $this->form([$box->id], '30'))->assertServerError();
        $this->failLinks = false;
        $this->assertSame(['20.000', [$yoga->id, $spin->id]], $this->shown($owner, $plan->id), 'the old price with its own programs, not 30 with none');

        // A new plan whose links fail is not added half-made.
        $this->failLinks = true;
        $this->actingAs($owner)->post(route('club.plans.store'), $this->form([$box->id], '15', ['name' => 'أسبوعي']))->assertServerError();
        $this->failLinks = false;
        $this->asTenant($club, fn () => $this->assertSame(1, MembershipPlan::count()));

        // The save that works is what the page shows again; the membership sold before keeps its terms.
        $this->actingAs($owner)->put(route('club.plans.update', $plan), $this->form([$box->id, $spin->id], '30'))->assertSessionHasNoErrors()->assertSessionHas('success');
        $expected = [$spin->id, $box->id];
        sort($expected);
        $this->assertSame(['30.000', $expected], $this->shown($owner, $plan->id));
        $this->assertSame($sold->plan_snapshot, $sold->fresh()->plan_snapshot);
    }

    public function test_the_same_save_sent_twice_leaves_one_plan_with_its_programs_once(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $yoga = $this->program($club, ['name' => 'يوغا']);
        $this->actingAs($owner)->post(route('club.plans.store'), $this->form([$yoga->id], '20'))->assertSessionHasNoErrors();
        $plan = $this->asTenant($club, fn () => MembershipPlan::sole());

        foreach ([1, 2] as $ignored) {
            $this->actingAs($owner)->put(route('club.plans.update', $plan), $this->form([$yoga->id], '25'))->assertSessionHasNoErrors();
        }
        $this->assertSame(['25.000', [$yoga->id]], $this->shown($owner, $plan->id));
        $this->assertSame(1, DB::table('gym_plan_program')->where('membership_plan_id', $plan->id)->count());
    }
}
