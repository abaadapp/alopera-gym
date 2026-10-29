<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Models\StaffAttendance;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Staff files, the payroll cycle, its postings, confidentiality and attendance. */
class PayrollTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function employee($club, array $attributes = []): Employee
    {
        return $this->asTenant($club, fn () => Employee::create($attributes + ['name' => 'سارة', 'basic_salary' => '400.000', 'allowances' => [['name' => 'مواصلات', 'amount' => '50.000']], 'status' => 'active']));
    }

    private function openRun($club, ?string $month = null): PayrollRun
    {
        $month ??= now()->format('Y-m');
        $this->actingAs($this->owner($club))->post(route('club.payroll.store'), ['month' => $month])->assertSessionHasNoErrors();

        return $this->asTenant($club, fn () => PayrollRun::where('period', $month.'-01')->firstOrFail());
    }

    private function balance($club, string $key): string
    {
        return $this->asTenant($club, fn () => app(FinanceReports::class)->balanceOf($key));
    }

    public function test_a_month_copies_salaries_into_draft_lines_that_compute_net_to_three_decimals(): void
    {
        $club = $this->club();
        $this->employee($club);
        $this->employee($club, ['name' => 'منى', 'basic_salary' => '350.125', 'allowances' => []]);
        $this->employee($club, ['name' => 'مستقيلة', 'status' => 'terminated']);

        $run = $this->openRun($club);
        $this->assertSame(PayrollRun::DRAFT, $run->status);
        $this->assertSame('800.125', $run->net);
        $this->asTenant($club, fn () => $this->assertSame(2, $run->items()->count()));

        $item = $this->asTenant($club, fn () => $run->items()->where('employee_name', 'سارة')->firstOrFail());
        $this->actingAs($this->owner($club))->put(route('club.payroll.items.update', $item), ['overtime' => '12.345', 'overtime_hours' => 3, 'deductions' => '20.010', 'deduction_notes' => 'تأخير'])->assertSessionHasNoErrors();
        $this->assertSame('442.335', $item->fresh()->net); // 400 + 50 + 12.345 − 20.010

        // A later raise does not rewrite the run.
        $this->asTenant($club, fn () => Employee::where('name', 'سارة')->update(['basic_salary' => '999']));
        $this->assertSame('400.000', $item->fresh()->basic);
    }

    public function test_deductions_cannot_exceed_what_she_earned(): void
    {
        $club = $this->club();
        $this->employee($club);
        $run = $this->openRun($club);
        $item = $this->asTenant($club, fn () => $run->items()->firstOrFail());

        $this->actingAs($this->owner($club))->put(route('club.payroll.items.update', $item), ['deductions' => '450.001'])->assertSessionHasErrors('deductions');
        $this->assertSame('450.000', $item->fresh()->net);
    }

    public function test_approval_posts_salaries_owed_once_and_locks_the_run(): void
    {
        $club = $this->club();
        $this->employee($club);
        $run = $this->openRun($club);
        $item = $this->asTenant($club, fn () => $run->items()->firstOrFail());
        $this->actingAs($this->owner($club))->put(route('club.payroll.items.update', $item), ['deductions' => '30'])->assertSessionHasNoErrors();

        $this->actingAs($this->owner($club))->post(route('club.payroll.approve', $run))->assertSessionHasNoErrors();
        $this->actingAs($this->owner($club))->post(route('club.payroll.approve', $run))->assertSessionHasErrors();

        $this->assertSame(PayrollRun::APPROVED, $run->fresh()->status);
        $this->assertSame('450.000', $this->balance($club, 'salaries_expense'));
        $this->assertSame('30.000', $this->balance($club, 'salary_deductions'));
        $this->assertSame('420.000', $this->balance($club, 'salaries_payable'));
        $this->asTenant($club, fn () => $this->assertSame(1, JournalEntry::where('source_type', 'payroll_run')->count()));

        // Approved: lines are locked.
        $this->actingAs($this->owner($club))->put(route('club.payroll.items.update', $item), ['deductions' => '0'])->assertSessionHasErrors();
        $this->actingAs($this->owner($club))->delete(route('club.payroll.destroy', $run))->assertSessionHasErrors();
        $this->assertSame('420.000', $item->fresh()->net);
    }

    public function test_paying_some_staff_then_repeating_the_request_pays_nobody_twice(): void
    {
        $club = $this->club();
        $this->employee($club);
        $this->employee($club, ['name' => 'منى', 'basic_salary' => '300', 'allowances' => []]);
        $run = $this->openRun($club);
        $this->actingAs($this->owner($club))->post(route('club.payroll.approve', $run));
        [$sara, $mona] = $this->asTenant($club, fn () => [$run->items()->where('employee_name', 'سارة')->first(), $run->items()->where('employee_name', 'منى')->first()]);
        $bank = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('bank'));
        $pay = fn (array $items) => $this->actingAs($this->owner($club))->post(route('club.payroll.pay', $run), ['items' => $items, 'account_id' => $bank->id, 'paid_on' => today()->toDateString()]);

        $pay([$sara->id])->assertSessionHasNoErrors();
        $pay([$sara->id])->assertSessionHasErrors(); // double click / second tab
        $this->assertSame(PayrollRun::APPROVED, $run->fresh()->status);
        $this->assertSame('300.000', $this->balance($club, 'salaries_payable'));
        $this->assertSame('-450.000', $this->balance($club, 'bank'));

        $pay([$sara->id, $mona->id])->assertSessionHasNoErrors(); // only Mona is still owed
        $this->assertSame(PayrollRun::PAID, $run->fresh()->status);
        $this->assertSame('0.000', $this->balance($club, 'salaries_payable'));
        $this->assertSame('-750.000', $this->balance($club, 'bank'));
        $this->asTenant($club, function () {
            $this->assertSame(2, JournalEntry::where('source_type', 'payroll_payout')->count());
            $this->assertSame(2, PayrollItem::whereNotNull('paid_at')->count());
            $this->assertSame((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit'));
        });
    }

    public function test_salaries_are_paid_from_cash_or_bank_never_from_the_paymob_clearing_account(): void
    {
        $club = $this->club();
        $this->employee($club);
        $run = $this->openRun($club);
        $this->actingAs($this->owner($club))->post(route('club.payroll.approve', $run));
        $item = $this->asTenant($club, fn () => $run->items()->firstOrFail());
        $paymob = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('paymob_clearing'));

        $this->actingAs($this->owner($club))->post(route('club.payroll.pay', $run), ['items' => [$item->id], 'account_id' => $paymob->id, 'paid_on' => today()->toDateString()])
            ->assertSessionHasErrors('account_id');
        $this->assertNull($item->fresh()->paid_at);
    }

    public function test_a_draft_cannot_be_paid_and_each_step_needs_its_own_permission(): void
    {
        $club = $this->club();
        $this->employee($club);
        $run = $this->openRun($club);
        $item = $this->asTenant($club, fn () => $run->items()->firstOrFail());
        $cash = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'));
        $payload = ['items' => [$item->id], 'account_id' => $cash->id, 'paid_on' => today()->toDateString()];

        $this->actingAs($this->owner($club))->post(route('club.payroll.pay', $run), $payload)->assertSessionHasErrors();

        $manager = $this->staff($club, 'manager');
        $finance = $this->staff($club, 'finance');
        $reception = $this->staff($club, 'reception');
        $this->actingAs($manager)->post(route('club.payroll.approve', $run))->assertForbidden();
        $this->actingAs($finance)->post(route('club.payroll.approve', $run))->assertForbidden();
        $this->actingAs($reception)->get(route('club.payroll.index'))->assertForbidden();
        $this->actingAs($reception)->get(route('club.employees.index'))->assertForbidden();

        $this->actingAs($this->owner($club))->post(route('club.payroll.approve', $run))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('club.payroll.pay', $run), $payload)->assertForbidden();
        $this->actingAs($finance)->post(route('club.payroll.pay', $run), $payload)->assertSessionHasNoErrors();
        $this->assertNotNull($item->fresh()->paid_at);
    }

    public function test_salaries_and_bank_details_stay_hidden_and_untouched_without_the_confidential_permission(): void
    {
        $club = $this->club();
        $employee = $this->employee($club, ['iban' => 'OM810180000001299123456']);
        $manager = $this->staff($club, 'manager'); // manages staff files, not salaries

        $this->actingAs($manager)->get(route('club.employees.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('confidential', false)->where('employees.0.basic_salary', null)->where('employees.0.iban', null)->where('stats.monthly', null));

        $this->actingAs($manager)->put(route('club.employees.update', $employee), ['name' => 'سارة علي', 'status' => 'active', 'basic_salary' => '9999', 'iban' => 'HACK'])->assertSessionHasNoErrors();
        $fresh = $employee->fresh();
        $this->assertSame('سارة علي', $fresh->name);
        $this->assertSame('400.000', $fresh->basic_salary);
        $this->assertSame('OM810180000001299123456', $fresh->iban);
        $this->asTenant($club, fn () => $this->assertNotSame('OM810180000001299123456', \DB::table('gym_employees')->where('id', $employee->id)->value('iban'), 'stored encrypted'));

        $this->actingAs($this->owner($club))->get(route('club.employees.index'))->assertInertia(fn ($page) => $page->where('employees.0.basic_salary', '400.000'));
    }

    public function test_nobody_but_the_owner_changes_her_own_salary(): void
    {
        $club = $this->club();
        $hr = $this->asTenant($club, fn () => Role::create(['key' => 'hr', 'name' => 'شؤون الموظفين', 'permissions' => ['employees.view', 'employees.manage', 'payroll.salaries']]));
        $user = $this->staff($club, 'reception');
        $user->forceFill(['role_id' => $hr->id])->save();
        $mine = $this->employee($club, ['user_id' => $user->id]);
        $other = $this->employee($club, ['name' => 'منى']);

        $this->actingAs($user->fresh())->put(route('club.employees.update', $mine), ['name' => 'سارة', 'status' => 'active', 'basic_salary' => '900', 'payment_method' => 'bank', 'user_id' => $user->id])->assertSessionHasErrors('basic_salary');
        $this->assertSame('400.000', $mine->fresh()->basic_salary);

        $this->actingAs($user->fresh())->put(route('club.employees.update', $other), ['name' => 'منى', 'status' => 'active', 'basic_salary' => '420', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame('420.000', $other->fresh()->basic_salary);
    }

    public function test_trainers_get_one_staff_file_each_however_often_they_are_imported(): void
    {
        $club = $this->club();
        $this->asTenant($club, fn () => [Trainer::create(['name' => 'كوتش دانة', 'phone' => '91234567']), Trainer::create(['name' => 'كوتش ريم'])]);

        $this->actingAs($this->owner($club))->post(route('club.employees.import-trainers'))->assertSessionHasNoErrors();
        $this->actingAs($this->owner($club))->post(route('club.employees.import-trainers'))->assertSessionHasNoErrors();

        $this->asTenant($club, function () {
            $this->assertSame(2, Employee::count());
            $this->assertSame('مدرب', Employee::firstWhere('name', 'كوتش دانة')->jobTitle->name);
        });
    }

    public function test_a_double_punch_on_the_reader_does_not_clock_her_out_or_open_a_second_record(): void
    {
        $club = $this->club();
        $user = $this->staff($club, 'reception');
        $this->travelTo(now()->setTime(8, 0));

        $this->asTenant($club, function () use ($user) {
            $access = app(AccessControlService::class);
            $access->staffPunch($user);
            $this->travel(20)->seconds();
            $access->staffPunch($user); // duplicate scan
            $this->assertSame(1, StaffAttendance::count());
            $this->assertNull(StaffAttendance::first()->clock_out_at);

            $this->travel(8)->hours();
            $access->staffPunch($user);
            $this->assertNotNull(StaffAttendance::first()->clock_out_at);
        });
    }

    public function test_every_payroll_screen_opens_through_the_cycle(): void
    {
        $club = $this->club();
        $this->employee($club);
        $owner = $this->owner($club);
        $run = $this->openRun($club);
        $item = $this->asTenant($club, fn () => $run->items()->firstOrFail());

        foreach ([route('club.employees.index'), route('club.job-titles.index'), route('club.payroll.index'), route('club.payroll.index', ['run' => $run->id]), route('club.payroll.payouts'), route('club.staff-attendance.index'), route('club.settings.payroll'), route('club.payroll.print', $run), route('club.payroll.print', ['run' => $run->id, 'item' => $item->id]), route('club.payroll.export', ['run' => $run->id, 'format' => 'csv'])] as $url) {
            $this->actingAs($owner)->get($url)->assertOk();
        }
        $this->actingAs($owner)->post(route('club.payroll.approve', $run));
        $this->actingAs($owner)->get(route('club.payroll.payouts', ['run' => $run->id]))->assertOk()->assertInertia(fn ($page) => $page->where('run.unpaid', '450.000')->where('waiting', '450.000'));
    }

    public function test_one_clubs_payroll_is_invisible_to_another(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $this->employee($a);
        $run = $this->openRun($a);

        $this->actingAs($this->owner($b))->get(route('club.payroll.print', $run))->assertNotFound();
        $this->actingAs($this->owner($b))->post(route('club.payroll.approve', $run))->assertNotFound();
        $this->actingAs($this->owner($b))->get(route('club.employees.index'))->assertInertia(fn ($page) => $page->has('employees', 0));
    }
}
