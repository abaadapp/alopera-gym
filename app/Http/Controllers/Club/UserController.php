<?php

namespace App\Http\Controllers\Club;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\DemoAccounts;
use App\Core\Auth\Grants;
use App\Core\Auth\PermissionRegistry;
use App\Core\Auth\SystemRoles;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        // A user tied to a branch sees that branch's accounts. Each opens its permissions in its staff file, if it has one.
        $files = $actor->hasPermission('employees.access') ? Employee::whereNotNull('user_id')->pluck('id', 'user_id') : collect();

        return Inertia::render('Club/Settings/Users', [
            'users' => User::ofTenant($tid)->when($actor->branch_id, fn ($q, $branch) => $q->where('branch_id', $branch))->with('role', 'branch:id,name')->orderBy('name')->get()
                ->map(fn (User $u) => $u->only('id', 'name', 'email', 'phone', 'is_active', 'role_id', 'branch_id') + [
                    'role' => $u->role?->name, 'role_key' => $u->role?->key, 'branch' => $u->branch?->name,
                    'last_login_at' => $u->last_login_at?->format('Y-m-d H:i'),
                    'own_permissions' => $u->hasOwnPermissions(),
                    'custom' => $u->hasOwnPermissions() ? PermissionRegistry::sanitize($u->custom_permissions ?? []) : null,
                    'employee_id' => $files[$u->id] ?? null,
                    // An account that opens more than this user is shown, not edited.
                    'may_manage' => Grants::mayManage($actor, $u),
                ]),
            'roles' => Role::orderBy('id')->get(['id', 'name', 'key', 'permissions'])->map(fn (Role $r) => $r->only('id', 'name', 'key') + ['assignable' => Grants::mayAssign($actor, $r), 'permissions' => $r->expandedPermissions()]),
            // The same picker as the staff file and the roles page, for an account's own list.
            'groups' => PermissionRegistry::editorPayload(),
            'decides' => Grants::decides($actor),
            'sensitive' => PermissionRegistry::sensitive(),
            'requires' => PermissionRegistry::REQUIRES,
            'branches' => Branch::where('is_active', true)->when($actor->branch_id, fn ($q, $branch) => $q->whereKey($branch))->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $this->guardRole($request, (int) $data['role_id']);
        $this->guardBranch($request, $data['branch_id'] ?? null);

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
        // Nobody changes their own role, branch reach or on/off: that is for whoever manages them.
        $branch = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        if ($user->id === $request->user()->id && ((int) $data['role_id'] !== $user->role_id || $branch !== $user->branch_id || ! ($data['is_active'] ?? true))) {
            throw ValidationException::withMessages(['role_id' => 'لا تغيّر دورك أو فرعك أو حالة حسابك بنفسك.']);
        }
        if ((int) $data['role_id'] !== $user->role_id) {
            $this->guardRole($request, (int) $data['role_id']);
        }
        $this->guardBranch($request, $branch);

        // The club must always keep at least one active owner.
        $ownerRole = Role::where('key', SystemRoles::OWNER)->value('id');
        $losingOwner = $user->role_id === $ownerRole && ((int) $data['role_id'] !== $ownerRole || ! ($data['is_active'] ?? true));
        if ($losingOwner && User::ofTenant($user->tenant_id)->where('role_id', $ownerRole)->where('is_active', true)->count() <= 1) {
            return back()->with('error', 'يجب أن يبقى مالك واحد نشط على الأقل.');
        }

        // Read again under its lock, then decided and saved: an edit at the same moment as the owner's (taking
        // plans.manage off this list) works from what the owner saved, and never puts back what the owner took off.
        [$user, $old] = DB::transaction(function () use ($request, $user, $data) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->id === $request->user()->id || Grants::mayManage($request->user(), $user), 403, 'هذا الحساب يفتح ما لا تفتحه، فلا تعدّله.');

            // Following the role, or a list of its own (sent only by the editor that shows it): the actor decides only
            // the permissions they hold, the sensitive ones only if they are the owner; nobody sets their own.
            $custom = $user->custom_permissions;
            if ($request->has('custom')) {
                abort_if($user->id === $request->user()->id, 403, 'لا تعدّل صلاحياتك بنفسك.');
                $role = Role::findOrFail($data['role_id']);
                $custom = null;
                if ($request->boolean('custom') && $role->key !== SystemRoles::OWNER) {
                    // A list of its own replaces the role's, so one started from the role would keep the role's sensitive
                    // permissions (plans.manage among them) past the owner taking them off the role. Only the owner
                    // starts one for an account whose role carries what only the owner decides, as only the owner gives
                    // such a role (Grants::mayAssign).
                    if ($user->custom_permissions === null && ! Grants::mayAssign($request->user(), $role)) {
                        throw ValidationException::withMessages(['custom' => 'دور هذا الحساب يحمل صلاحيات يقررها صاحب النشاط وحده، فقائمته الخاصة يضعها صاحب النشاط.']);
                    }
                    $stored = $user->custom_permissions !== null ? PermissionRegistry::sanitize($user->custom_permissions) : $role->expandedPermissions();
                    $custom = [...Grants::merge($request->user(), $stored, (array) $request->input('permissions', [])), ...PermissionRegistry::dormant($user->custom_permissions ?? [])];
                }
                if ($custom === null && $user->custom_permissions !== null && ! Grants::mayAssign($request->user(), $role)) {
                    throw ValidationException::withMessages(['custom' => 'يفتح الدور ما لا تملكه؛ لا يمكنك إعادة هذا الحساب إلى صلاحيات دوره كاملة.']);
                }
            }

            $old = $user->only('role_id', 'is_active', 'email', 'custom_permissions');
            $user->fill(collect($data)->only('name', 'phone')->all());
            $user->email = strtolower($data['email']);
            $user->role_id = $data['role_id'];
            $user->branch_id = $data['branch_id'] ?? null;
            $user->is_active = $data['is_active'] ?? true;
            $user->custom_permissions = $custom;
            if (! empty($data['password'])) {
                $user->password = $data['password'];
                // A new password ends the account's «remember me» logins, as one changed from «حسابي» does.
                $user->setRememberToken(Str::random(60));
            }
            $user->save();

            return [$user, $old];
        });
        if (! empty($data['password']) && $user->is($request->user())) {
            // Your own password, changed here: this device signs in with it from now on (auth.session keeps the
            // signed-in account's fingerprint), so only your other devices are signed out.
            Auth::guard('web')->setUser($user);
        }

        $audit->log('user.updated', $user, old: $old, new: $user->only('role_id', 'is_active', 'email', 'custom_permissions'));

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

    /** A user tied to a branch opens and keeps accounts in that branch only (Grants::mayPlace). */
    private function guardBranch(Request $request, int|string|null $branchId): void
    {
        if (! Grants::mayPlace($request->user(), $branchId === null ? null : (int) $branchId)) {
            throw ValidationException::withMessages(['branch_id' => 'حسابك مرتبط بفرعك: الحسابات التي تديرها تبقى في فرعك.']);
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
        // Checked as it is saved, in lower case: another case of a taken address is a field error, not a server error.
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id), DemoAccounts::rule()],
            'phone' => ['nullable', 'string', 'max:32'],
            'role_id' => ['required', TenantRule::exists('roles')],
            'branch_id' => ['nullable', TenantRule::exists('branches')],
            'is_active' => ['boolean'],
            'password' => [$user ? 'nullable' : 'required', Password::defaults()],
            'custom' => ['sometimes', 'boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'in:'.implode(',', PermissionRegistry::all())],
        ]);
    }
}
