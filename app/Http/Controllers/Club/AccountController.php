<?php

namespace App\Http\Controllers\Club;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Phone;
use App\Core\Tenancy\BranchContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «حسابي»: the signed-in staff member's own account — what they are (role, branch, permissions in short), their name
 * and phone, and their password (the current one is asked first). Their email, role and branch are set by whoever
 * manages users.
 */
class AccountController extends Controller
{
    public function show(Request $request, BranchContext $branches): Response
    {
        $user = $request->user()->loadMissing('role:id,name,key', 'branch:id,name');

        return Inertia::render('Club/Account', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role?->name,
                'branch' => $user->branch?->name,
                'branch_locked' => ! $branches->canSwitch($user),
                'last_login_at' => $user->last_login_at?->format('Y-m-d H:i'),
                'created_at' => $user->created_at?->format('Y-m-d'),
            ],
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);
        $user = $request->user();
        $user->fill(['name' => $data['name'], 'phone' => $data['phone'] ? Phone::normalize($data['phone']) : null])->save();
        $audit->log('user.profile_updated', $user);

        return back()->with('success', 'تم حفظ بيانات حسابك.');
    }

    public function password(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], ['current_password.current_password' => 'كلمة المرور الحالية غير صحيحة.']);

        $user = $request->user();
        $user->forceFill(['password' => $data['password']])->save();
        $audit->log('user.password_changed', $user);

        return back()->with('success', 'تم تغيير كلمة المرور.');
    }
}
