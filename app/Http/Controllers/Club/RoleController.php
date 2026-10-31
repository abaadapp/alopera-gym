<?php

namespace App\Http\Controllers\Club;

use App\Core\Auth\Grants;
use App\Core\Auth\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Club/Settings/Roles', [
            // What this user may give: the editor disables the rest instead of offering what the server refuses.
            'grantable' => Grants::grantable($request->user()),
            'roles' => Role::withCount('users')->orderBy('id')->get()->map(fn (Role $r) => $r->only('id', 'key', 'name', 'description', 'is_system', 'is_locked', 'users_count') + [
                'permissions' => $r->expandedPermissions(),
            ]),
            'groups' => collect(PermissionRegistry::editorGroups())->map(fn ($g, $key) => ['key' => $key, 'label' => $g['label'], 'permissions' => collect($g['permissions'])->map(fn ($label, $perm) => ['key' => $perm, 'label' => $label])->values()])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->guardGrant($request, $data['permissions'] ?? []);
        Role::create([
            'key' => 'custom_'.Str::lower(Str::random(8)),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => PermissionRegistry::sanitize($data['permissions'] ?? []),
            'is_system' => false,
        ]);

        return back()->with('success', 'تم إنشاء الدور.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        if ($role->is_locked) {
            return back()->with('error', 'دور المالك لا يمكن تعديله.');
        }
        $data = $this->validated($request);
        // Only the permissions this user holds change; any other permission on the role stays as it was.
        $role->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => [...Grants::merge($request->user(), PermissionRegistry::sanitize($role->permissions ?? []), $data['permissions'] ?? []), ...PermissionRegistry::dormant($role->permissions ?? [])],
        ]);

        return back()->with('success', 'تم حفظ صلاحيات الدور.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->with('error', 'لا يمكن حذف الأدوار الافتراضية.');
        }
        if ($role->users()->exists()) {
            return back()->with('error', 'الدور مسند لمستخدمين. انقلهم إلى دور آخر أولًا.');
        }
        $role->delete();

        return back()->with('success', 'تم حذف الدور.');
    }

    /** A new role carries only permissions its author holds. */
    private function guardGrant(Request $request, array $permissions): void
    {
        if (! Grants::covers($request->user(), PermissionRegistry::sanitize($permissions))) {
            throw ValidationException::withMessages(['permissions' => 'لا يمكنك منح صلاحية لا تملكها.']);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'in:'.implode(',', PermissionRegistry::all())],
        ]);
    }
}
