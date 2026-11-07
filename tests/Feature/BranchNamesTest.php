<?php

namespace Tests\Feature;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A branch name is the club's own: a new branch (or a rename) may not take a name another branch of the club already
 * has. Branches that already share a name keep it, and can still be edited and stopped; another club may use any name.
 */
class BranchNamesTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_a_new_branch_may_not_take_a_name_the_club_already_has(): void
    {
        $club = $this->club();
        $other = $this->club();
        $this->asTenant($other, fn () => Branch::create(['name' => 'فرع الخوض', 'is_active' => true]));
        $this->actingAs($this->owner($club));

        $this->post(route('club.branches.store'), ['name' => 'فرع الخوض'])->assertSessionHasNoErrors();
        $this->post(route('club.branches.store'), ['name' => 'فرع الخوض'])->assertSessionHasErrors('name');
        $this->post(route('club.branches.store'), ['name' => '  فرع الخوض '])->assertSessionHasErrors('name');
        $this->post(route('club.branches.store'), ['name' => 'الفرع الرئيسي'])->assertSessionHasErrors('name');

        $this->asTenant($club, fn () => $this->assertSame(1, Branch::where('name', 'فرع الخوض')->count()));
        $this->asTenant($other, fn () => $this->assertSame(1, Branch::where('name', 'فرع الخوض')->count(), 'another club keeps its own'));
    }

    public function test_a_rename_may_not_take_a_taken_name_but_old_namesakes_stay_editable(): void
    {
        $club = $this->club();
        [$a, $b, $c] = $this->asTenant($club, fn () => [
            Branch::create(['name' => 'فرع قديم', 'is_active' => true]),
            Branch::create(['name' => 'فرع قديم', 'is_active' => true]),
            Branch::create(['name' => 'فرع السيب', 'is_active' => true]),
        ]);
        $this->actingAs($this->owner($club));

        $this->put(route('club.branches.update', $c), ['name' => 'فرع قديم', 'is_active' => true])->assertSessionHasErrors('name');
        // Two branches that already shared a name before the rule: each is saved and stopped as it is, name untouched.
        $this->put(route('club.branches.update', $b), ['name' => 'فرع قديم', 'phone' => '91234567', 'is_active' => false])->assertSessionHasNoErrors();
        $this->put(route('club.branches.update', $c), ['name' => 'فرع السيب الجديد', 'is_active' => true])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($a, $b, $c) {
            $this->assertSame(['فرع قديم', 'فرع قديم', 'فرع السيب الجديد'], [$a->fresh()->name, $b->fresh()->name, $c->fresh()->name]);
            $this->assertFalse($b->fresh()->is_active);
        });
    }
}
