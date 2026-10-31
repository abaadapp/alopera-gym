<?php

namespace App\Http\Controllers\Club;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\DemoAccounts;
use App\Core\Auth\Grants;
use App\Core\Auth\SystemRoles;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Modules\Platform\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Club staff accounts. Every query is explicitly limited to the current club. */
class UserController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function index(Request $request): Response
    {
        $tid = $this->tenants->require()->id;
        $actor = $request->user();

        return Inertia::render('Club/Settings/Users', [
            'users' => User::ofTenant($tid)->with('role', 'branch:id,name')->orderBy('name')->get()
                ->map(fn (User $u) => $u->only('id', 'name', 'email', 'phone', 'is_active', 'role_id', 'branch_id') + [
                    'role' => $u->role?->name, 'role_key' => $u->role?->key, 'branch' => $u->branch?->name,
                    'last_login_at' => $u->last_login_at?->format('Y-m-d H:i'),
                    'own_permissions' => $u->hasOwnPermissions(),
                    // An account that opens more than this user is shown, not edited.
                    'may_manage' => Grants::mayManage($actor, $u),
                ]),
            'roles' => Role::orderBy('id')->get(['id', 'name', 'key', 'permissions'])->map(fn (Role $r) => $r->only('id', 'name', 'key') + ['assignable' => Grants::mayAssign($actor, $r)]),
            'branches' => Branch::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, PlanLimits $limits, AuditLogger $audit): RedirectResponse
    {
        $limits->assertCanAddStaff();
        $data = $this->validated($request);
        $this->guardRole($request, (int) $data['role_id']);

        $user = new User(collect($data)->only('name', 'email', 'phone', 'password')->all());
        $user->email = strtolower($user->email);
        $user->tenant_id = $this->tenants->require()->id;
        $user->role_id = $data['role_id'];
        $user->branch_id = $data['branch_id'] ?? null;
        $user->save();

        $audit->log('user.created', $user, ['role_id' => $user->role_id]);

        return back()->with('success', 'تمت إضافة المستخدم.');
    }

    public function update(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $this->ensureOwnClub($user);
        abort_unless($user->id === $request->user()->id || Grants::mayManage($request->user(), $user), 403, 'هذا الحساب يفتح ما لا تفتحه، فلا تعدّله.');
        $data = $this->validated($request, $user);
        if ((int) $data['role_id'] !== $user->role_id) {
            $this->guardRole($request, (int) $data['role_id']);
        }

        // The club must always keep at least one active owner.
        $ownerRole = Role::where('key', SystemRoles::OWNER)->value('id');
        $losingOwner = $user->role_id === $ownerRole && ((int) $data['role_id'] !== $ownerRole || ! ($data['is_active'] ?? true));
        if ($losingOwner && User::ofTenant($user->tenant_id)->where('role_id', $ownerRole)->where('is_active', true)->count() <= 1) {
            return back()->with('error', 'يجب أن يبقى مالك واحد نشط على الأقل.');
        }

        $old = $user->only('role_id', 'is_active', 'email');
        $user->fill(collect($data)->only('name', 'phone')->all());
        $user->email = strtolower($data['email']);
        $user->role_id = $data['role_id'];
        $user->branch_id = $data['branch_id'] ?? null;
        $user->is_active = $data['is_active'] ?? true;
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        $audit->log('user.updated', $user, old: $old, new: $user->only('role_id', 'is_active', 'email'));

        return back()->with('success', 'تم حفظ المستخدم.');
    }

    public function destroy(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $this->ensureOwnClub($user);
        abort_unless(Grants::mayManage($request->user(), $user), 403, 'هذا الحساب يفتح ما لا تفتحه، فلا توقفه.');
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'لا يمكنك إيقاف حسابك.');
        }
        if ($user->isOwner() && User::ofTenant($user->tenant_id)->where('role_id', $user->role_id)->where('is_active', true)->count() <= 1) {
            return back()->with('error', 'يجب أن يبقى مالك واحد نشط على الأقل.');
        }

        $user->forceFill(['is_active' => false])->save();
        $audit->log('user.deactivated', $user);

        return back()->with('success', 'تم إيقاف حساب المستخدم.');
    }

    /** A role is given only by someone who holds everything it opens (the owner role: only by an owner). */
    private function guardRole(Request $request, int $roleId): void
    {
        $role = Role::findOrFail($roleId);
        if (! Grants::mayAssign($request->user(), $role)) {
            throw ValidationException::withMessages(['role_id' => 'لا يمكنك إسناد دور يفتح ما لا تملكه.']);
        }
    }

    private function ensureOwnClub(User $user): void
    {
        // Users are not under the global scope; the route binding could resolve another club's user.
        abort_unless($user->tenant_id === $this->tenants->require()->id, 404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id), DemoAccounts::rule()],
            'phone' => ['nullable', 'string', 'max:32'],
            'role_id' => ['required', TenantRule::exists('roles')],
            'branch_id' => ['nullable', TenantRule::exists('branches')],
            'is_active' => ['boolean'],
            'password' => [$user ? 'nullable' : 'required', Password::defaults()],
        ]);
    }
}
