<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** The owner's decisions after the launch audit (2026-11-05), each held by a test. */
class OwnerDecisionsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_an_approved_count_posts_the_difference_found_when_counting_and_keeps_later_sales(): void
    {
        $club = $this->club();

        $this->asTenant($club, function () {
            $branch = Branch::orderByDesc('is_main')->orderBy('id')->firstOrFail();
            $water = Product::create(['name' => 'Water', 'price' => 0.3]);
            app(StockService::class)->move($water, 10, 'adjustment', null, 'shelf', 0.1, branchId: $branch->id);
            $count = app(InventoryService::class)->startCount($branch->id, [$water->id]);
            app(InventoryService::class)->saveCounts($count, [$count->lines()->value('id') => ['counted' => 8]]);
            // Sold after the count, before it was approved.
            app(StockService::class)->move($water, -3, 'sale', null, 'sold', branchId: $branch->id);

            app(InventoryService::class)->approveCount($count->fresh());

            $this->assertSame(5.0, (float) app(StockService::class)->quantity($water, $branch->id), 'ten, two missing at the count, three sold since');
            $this->assertSame('-2.000', $count->lines()->value('difference'));
            $this->assertThrows(fn () => app(InventoryService::class)->approveCount($count->fresh()), BusinessRuleException::class);
            $this->assertSame(5.0, (float) app(StockService::class)->quantity($water, $branch->id), 'approved once');
        });
    }

    public function test_a_draft_payroll_run_is_listed_but_left_out_of_the_totals(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->asTenant($club, function () {
            Employee::create(['name' => 'موظف', 'basic_salary' => 100, 'status' => 'active']);
            app(PayrollService::class)->open(now()->format('Y-m'));
        });

        $this->actingAs($owner)->get(route('club.reports.show', ['report' => 'payroll']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.runs.0.status', 'draft')
                ->where('data.runs.0.net', '100.000')
                ->where('data.comparison.net.current', '0.000')
                ->where('data.comparison.runs.current', 0));
    }
}
