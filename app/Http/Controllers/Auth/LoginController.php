<?php

namespace App\Http\Controllers\Auth;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\DemoAccounts;
use App\Core\Tenancy\HomeGym;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $credentials['email'] = strtolower($credentials['email']);

        if (DemoAccounts::blocked($credentials['email'])) {
            $audit->log('auth.demo_blocked', null, ['email' => $credentials['email']]);
            throw ValidationException::withMessages(['email' => 'بيانات الدخول غير صحيحة.']);
        }

        if (! Auth::guard('web')->attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            $audit->log('auth.failed', null, ['email' => $credentials['email']]);
            throw ValidationException::withMessages(['email' => 'بيانات الدخول غير صحيحة.']);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();
        // Only the gym's staff sign in here: an account that belongs to no gym (an old platform account) or to a club
        // record this system does not serve (HomeGym) has no screen.
        if ($user->tenant_id === null || ! HomeGym::allows($user->tenant_id)) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages(['email' => 'هذا الحساب لا يملك صلاحية الدخول إلى النظام.']);
        }

        // The screens of whoever used this browser before are not kept in its history.
        Inertia::clearHistory();

        return redirect()->intended(route($user->homeRoute()));
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        if ($request->user()) {
            $audit->log('auth.logout', $request->user());
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // Signed out: the pages left in the browser's history can no longer be read («back» shows nothing of them).
        Inertia::clearHistory();

        return redirect()->route('login');
    }
}
