<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollPayout;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A month still to come may be prepared as a draft, which posts nothing and pays nothing; it is approved and paid only
 * once that month has begun, and never paid with a date before it. Then it is paid once, whatever is clicked twice.
 */
class PayrollFutureMonthTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function prepared(): array
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->asTenant($club, fn () => Employee::create(['name' => 'سارة', 'basic_salary' => '400.000', 'status' => 'active', 'payment_method' => 'cash']));
        $month = now()->addMonth()->format('Y-m');
        $this->actingAs($owner)->post(route('club.payroll.store'), ['month' => $month])->assertSessionHasNoErrors();
        $run = $this->asTenant($club, fn () => PayrollRun::where('period', $month.'-01')->firstOrFail());

        return [$club, $owner, $run, $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'))];
    }

    private function pay($owner, PayrollRun $run, int $cash, string $on): TestResponse
    {
        return $this->actingAs($owner)->post(route('club.payroll.pay', $run), ['items' => $run->items()->pluck('id')->all(), 'account_id' => $cash, 'paid_on' => $on]);
    }

    public function test_a_month_to_come_is_a_draft_that_posts_and_pays_nothing(): void
    {
        [$club, , $run] = $this->prepared();

        $this->assertSame(PayrollRun::DRAFT, $run->status);
        $this->assertSame(0, $this->asTenant($club, fn () => JournalEntry::count() + PayrollPayout::count()));
    }

    public function test_it_is_not_approved_before_its_month_begins(): void
    {
        [$club, $owner, $run] = $this->prepared();

        $this->actingAs($owner)->post(route('club.payroll.approve', $run))->assertSessionHasErrors();
        $this->assertSame(PayrollRun::DRAFT, $run->fresh()->status);
        $this->assertSame(0, $this->asTenant($club, fn () => JournalEntry::count()));
    }

    public function test_one_approved_early_is_not_paid_before_its_month_begins(): void
    {
        [$club, $owner, $run, $cash] = $this->prepared();
        $run->forceFill(['status' => PayrollRun::APPROVED, 'approved_at' => now()])->save(); // approved before this rule existed

        $this->pay($owner, $run, $cash->id, today()->toDateString())->assertSessionHasErrors();
        $this->assertSame(0, $this->asTenant($club, fn () => PayrollPayout::count() + JournalEntry::where('source_type', 'payroll_payout')->count()));
        $this->assertSame(0, $this->asTenant($club, fn () => PayrollItem::whereNotNull('paid_at')->count()));
    }

    public function test_once_its_month_has_begun_it_is_approved_and_paid_once_never_dated_before_the_month(): void
    {
        [$club, $owner, $run, $cash] = $this->prepared();
        $this->fundAccounts($club);
        $this->travelTo($run->period->copy()->addDays(2)->setTime(9, 0));

        $this->actingAs($owner)->post(route('club.payroll.approve', $run))->assertSessionHasNoErrors();
        $this->assertSame(PayrollRun::APPROVED, $run->fresh()->status);
        $approval = $this->asTenant($club, fn () => JournalEntry::where('source_type', 'payroll_run')->sole());
        $this->assertSame(today()->toDateString(), $approval->entry_date->toDateString(), 'dated in its month, not before');

        $this->pay($owner, $run, $cash->id, $run->period->copy()->subDay()->toDateString())->assertSessionHasErrors();
        $this->pay($owner, $run, $cash->id, today()->toDateString())->assertSessionHasNoErrors();
        $this->pay($owner, $run, $cash->id, today()->toDateString())->assertSessionHasErrors(); // the second click
        $this->assertSame(1, $this->asTenant($club, fn () => PayrollPayout::count()));
        $this->assertSame(1, $this->asTenant($club, fn () => JournalEntry::where('source_type', 'payroll_payout')->count()));
    }
}
