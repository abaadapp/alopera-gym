<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Opening a sign-in account from the add-employee form: an email and a password chosen there (or a temporary one shown
 * once), saved hashed, never flashed back nor shown again; the employee signs in with them, a wrong password is refused,
 * a taken email is refused with nothing saved, and the account belongs to the file, the club and the branch chosen.
 */
class EmployeeAccountTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function file(Role $role, array $account): array
    {
        return $account + ['name' => 'سالم', 'email' => 'salem@x.test', 'status' => 'active', 'basic_salary' => '300', 'payment_method' => 'cash', 'account_mode' => 'new', 'account_email' => 'salem@x.test', 'account_role_id' => $role->id];
    }

    public function test_an_account_opened_with_a_chosen_password_signs_in_with_it(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $role = $this->asTenant($club, fn () => Role::where('key', 'reception')->sole());

        $this->actingAs($owner)->post(route('club.employees.store'), $this->file($role, ['account_password' => 'Strong-pass-42', 'account_password_confirmation' => 'Strong-pass-42']))
            ->assertSessionHasNoErrors()->assertSessionMissing('password');
        $user = User::where('email', 'salem@x.test')->sole();
        $this->assertNotSame('Strong-pass-42', $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('Strong-pass-42', $user->password));
        $this->assertSame($club->id, $user->tenant_id);
        $this->assertSame($user->id, $this->asTenant($club, fn () => Employee::where('name', 'سالم')->value('user_id')));
        $this->assertSame(['members.view'], array_values(array_intersect(['members.view'], $user->permissions())), 'its role, nothing added');
        $this->assertFalse($user->hasOwnPermissions());

        auth()->logout();
        $this->post(route('login.store'), ['email' => 'salem@x.test', 'password' => 'wrong-pass-00'])->assertSessionHasErrors();
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => 'salem@x.test', 'password' => 'Strong-pass-42']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_mismatch_or_a_taken_email_is_refused_and_nothing_is_saved(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $role = $this->asTenant($club, fn () => Role::where('key', 'reception')->sole());

        $this->actingAs($owner)->post(route('club.employees.store'), $this->file($role, ['account_password' => 'Strong-pass-42', 'account_password_confirmation' => 'Other-pass-42']))
            ->assertSessionHasErrors('account_password')->assertSessionMissing('_old_input.account_password');
        $this->actingAs($owner)->post(route('club.employees.store'), $this->file($role, ['account_email' => $owner->email]))->assertSessionHasErrors('account_email');
        $this->assertSame(0, $this->asTenant($club, fn () => Employee::where('name', 'سالم')->count()));
        $this->assertSame(0, User::where('email', 'salem@x.test')->count());
    }

    public function test_an_account_opened_without_a_password_gets_a_temporary_one_shown_once(): void
    {
        $club = $this->club();
        $role = $this->asTenant($club, fn () => Role::where('key', 'reception')->sole());

        $response = $this->actingAs($this->owner($club))->post(route('club.employees.store'), $this->file($role, []))->assertSessionHasNoErrors();
        $temporary = $response->getSession()->get('password');
        $this->assertIsString($temporary);
        $this->assertTrue(Hash::check($temporary, User::where('email', 'salem@x.test')->sole()->password));
    }
}
