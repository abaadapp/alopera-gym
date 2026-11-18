<?php

namespace Tests\Feature;

use App\Modules\Gym\Models\MembershipPlan;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Depends;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Eloquent reads a model's column names once per process, for mass assignment against $guarded. A test that filled a
 * model on a test database migrated before a column was added (tests/Unit/PlanAndPermissionsTest runs before the first
 * migrate:fresh) left those older columns to every later test: the gift tests then failed in the full suite only
 * («Add [giftable] to fillable…»), and passed alone. Each test now starts without them (Tests\TestCase::tearDown).
 */
class ModelColumnsBetweenTestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_test_may_leave_the_columns_of_an_older_schema(): void
    {
        $older = array_values(array_diff(Schema::getColumnListing('gym_membership_plans'), ['giftable']));
        (new ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, [MembershipPlan::class => $older]);

        $this->expectException(MassAssignmentException::class);
        new MembershipPlan(['giftable' => false]); // what the gift tests met
    }

    #[Depends('test_a_test_may_leave_the_columns_of_an_older_schema')]
    public function test_the_next_test_reads_its_own_database_s_columns_and_id_and_tenant_stay_guarded(): void
    {
        $this->assertFalse((new MembershipPlan(['giftable' => false]))->giftable);

        foreach (['id' => 7, 'tenant_id' => 1] as $guarded => $value) {
            try {
                new MembershipPlan([$guarded => $value]);
                $this->fail("$guarded is not guarded any more");
            } catch (MassAssignmentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
