<?php

namespace Tests\Concerns;

use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantProvisioner;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Services\MemberService;
use App\Modules\Gym\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Helpers to build clubs, staff and members through the real services. */
trait BuildsClubs
{
    /** The gym (the system runs one; tests build a second to prove nothing leaks between records of two). */
    protected function club(string $name = 'Club'): Tenant
    {
        $tenant = app(TenantProvisioner::class)->provision(
            ['name' => $name.' '.Str::random(4)],
            ['name' => 'Owner', 'email' => Str::lower(Str::random(10)).'@owner.test', 'password' => 'password'],
        );
        app(TenantContext::class)->set(null);

        return $tenant->fresh();
    }

    protected function owner(Tenant $tenant): User
    {
        return User::ofTenant($tenant->id)->whereHas('role', fn ($q) => $q->where('key', 'owner'))->firstOrFail();
    }

    protected function staff(Tenant $tenant, string $roleKey, array $attributes = []): User
    {
        $roleId = app(TenantContext::class)->run($tenant, fn () => Role::where('key', $roleKey)->value('id'));
        $user = new User($attributes + ['name' => ucfirst($roleKey), 'email' => Str::lower(Str::random(10)).'@staff.test', 'password' => 'password']);
        $user->tenant_id = $tenant->id;
        $user->role_id = $roleId;
        $user->save();

        return $user;
    }

    /**
     * @template T
     *
     * @param  callable(Tenant): T  $callback
     * @return T
     */
    protected function asTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->run($tenant, $callback);
    }

    protected function plan(Tenant $tenant, array $attributes = []): MembershipPlan
    {
        return $this->asTenant($tenant, fn () => MembershipPlan::create($attributes + [
            'name' => 'Monthly', 'type' => 'combo', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 25,
            'freeze_allowed' => true, 'max_freeze_days' => 14, 'max_freezes' => 2,
        ]));
    }

    protected function member(Tenant $tenant, array $attributes = []): Member
    {
        $password = $attributes['password'] ?? null;
        unset($attributes['password']);

        return $this->asTenant($tenant, fn () => app(MemberService::class)->create($attributes + [
            'full_name' => 'Member '.Str::random(4),
            'phone' => '9689'.random_int(1000000, 9999999),
        ], $password));
    }

    /** Sell and fully pay (cash): an active membership. */
    protected function activeMembership(Tenant $tenant, Member $member, ?MembershipPlan $plan = null): Membership
    {
        $plan ??= $this->plan($tenant);

        return $this->asTenant($tenant, function () use ($member, $plan) {
            $membership = app(MembershipService::class)->sell($member, $plan);
            app(PaymentService::class)->record($membership->invoice()->first(), $membership->total, 'cash');

            return $membership->fresh();
        });
    }

    protected function program(Tenant $tenant, array $attributes = []): Program
    {
        return $this->asTenant($tenant, fn () => Program::create($attributes + ['name' => 'Zumba', 'slug' => 'zumba-'.Str::random(4)]));
    }

    protected function classSession(Tenant $tenant, ?Program $program = null, array $attributes = []): ClassSession
    {
        $program ??= $this->program($tenant);
        $start = CarbonImmutable::parse($attributes['starts_at'] ?? now()->addDay()->setTime(18, 0));

        return $this->asTenant($tenant, fn () => ClassSession::create([
            'program_id' => $program->id,
            'trainer_id' => $attributes['trainer_id'] ?? null,
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($attributes['minutes'] ?? 60),
            'capacity' => $attributes['capacity'] ?? 10,
        ]));
    }
}
