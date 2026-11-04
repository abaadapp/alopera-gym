<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Models\StaffAttendance;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Attendance kept as worked, a payroll line saved as shown, and a person's own pay out of their own hands. */
class StaffIntegrityTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_a_shift_that_ends_after_midnight_is_one_record_closed_at_the_late_punch(): void
    {
        $club = $this->club();
        $user = $this->staff($club, 'reception');
        $this->travelTo(now()->startOfDay()->setTime(16, 0));

        $this->asTenant($club, function () use ($user) {
            $access = app(AccessControlService::class);
            $access->staffPunch($user);
            $this->travelTo(now()->addHours(8)->addMinutes(30)); // 00:30 the next day
            $access->staffPunch($user);

            $this->assertSame(1, StaffAttendance::count());
            $shift = StaffAttendance::sole();
            $this->assertSame(510, (int) $shift->clock_in_at->diffInMinutes($shift->clock_out_at));

            // The next afternoon opens a new day, as before.
            $this->travelTo(now()->addHours(15)->addMinutes(30));
            $access->staffPunch($user);
            $this->assertSame(2, StaffAttendance::count());
            $this->assertNull(StaffAttendance::latest('clock_in_at')->first()->clock_out_at);
        });
    }

    public function test_a_checkout_never_punched_is_closed_by_a_correction_and_a_clock_in_still_ahead_is_refused(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $user = $this->staff($club, 'reception');
        $employee = $this->asTenant($club, fn () => Employee::create(['name' => 'سارة', 'basic_salary' => 400, 'status' => 'active', 'user_id' => $user->id]));
        $this->travelTo(now()->startOfDay()->setTime(10, 0));
        $yesterday = now()->subDay()->toDateString();
        $this->asTenant($club, fn () => StaffAttendance::create(['user_id' => $user->id, 'clock_in_at' => $yesterday.' 08:00', 'method' => 'manual']));

        $this->actingAs($owner)->post(route('club.employees.attendance', $employee), ['date' => $yesterday, 'in' => '08:00', 'out' => '16:00', 'notes' => 'نسيت الانصراف'])->assertSessionHasNoErrors();

        $this->asTenant($club, function () {
            $this->assertSame(1, StaffAttendance::count(), 'the open day is closed, not doubled');
            $this->assertSame('16:00', StaffAttendance::sole()->clock_out_at->format('H:i'));
        });

        $this->actingAs($owner)->post(route('club.employees.attendance', $employee), ['date' => now()->toDateString(), 'in' => '23:00', 'notes' => 'مسبق'])->assertSessionHasErrors('in');
        $this->asTenant($club, fn () => $this->assertSame(1, StaffAttendance::count()));
    }

    public function test_emptying_an_amount_on_a_payroll_line_saves_it_as_zero(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->asTenant($club, fn () => Employee::create(['name' => 'سارة', 'basic_salary' => '400.000', 'allowances' => [['name' => 'مواصلات', 'amount' => '50.000']], 'status' => 'active']));
        $this->actingAs($owner)->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $item = $this->asTenant($club, fn () => PayrollRun::sole()->items()->sole());

        $this->actingAs($owner)->put(route('club.payroll.items.update', $item), ['allowances' => '50.000', 'overtime_hours' => '0', 'overtime' => '0', 'deductions' => '30', 'deduction_notes' => 'تأخير', 'notes' => ''])->assertSessionHasNoErrors();
        $this->assertSame('420.000', $item->fresh()->net);

        // What the page sends once the field is emptied: an empty string.
        $this->actingAs($owner)->put(route('club.payroll.items.update', $item), ['allowances' => '50.000', 'overtime_hours' => '0', 'overtime' => '', 'deductions' => '', 'deduction_notes' => '', 'notes' => ''])->assertSessionHasNoErrors();
        $this->assertSame('0.000', $item->fresh()->deductions);
        $this->assertSame('450.000', $item->fresh()->net);
    }

    public function test_nobody_sets_their_own_salary_by_linking_their_own_account_to_a_new_file(): void
    {
        $club = $this->club();
        $hr = $this->asTenant($club, fn () => Role::create(['key' => 'hr', 'name' => 'شؤون الموظفين', 'permissions' => ['employees.view', 'employees.create', 'employees.manage', 'employees.access', 'payroll.salaries', 'payroll.compensation']]));
        $user = $this->staff($club, 'reception');
        $user->forceFill(['role_id' => $hr->id])->save();

        $this->actingAs($user->fresh())->post(route('club.employees.store'), [
            'name' => 'أنا', 'status' => 'active', 'basic_salary' => '9000', 'payment_method' => 'cash', 'account_mode' => 'link', 'account_user_id' => $user->id,
        ])->assertSessionHasErrors('basic_salary');

        $this->asTenant($club, fn () => $this->assertSame(0, Employee::count()));
    }

    public function test_attendance_shows_the_person_by_their_staff_file_name(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $user = $this->staff($club, 'reception', ['name' => 'sara.login']);
        $this->asTenant($club, fn () => Employee::create(['name' => 'سارة الهنائي', 'basic_salary' => 400, 'status' => 'active', 'user_id' => $user->id]));
        $this->asTenant($club, fn () => app(AccessControlService::class)->staffPunch($user));

        $this->actingAs($owner)->get(route('club.staff-attendance.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('rows.data.0.user', 'سارة الهنائي'));
        $this->actingAs($owner)->get(route('club.reports.show', ['report' => 'staff-attendance']))->assertOk()
            ->assertInertia(fn ($page) => $page->where('data.summary.0.user', 'سارة الهنائي'));
    }
}
