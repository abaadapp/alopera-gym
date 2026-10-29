<?php

namespace App\Http\Controllers\Auth;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\DemoAccounts;
use App\Core\Auth\PermissionRegistry;
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
        // The platform owner's screens are switched off; clubs are managed from the command line instead.
        if ($user->isPlatformAdmin() && ! PermissionRegistry::moduleEnabled('platform_panel')) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages(['email' => 'لوحة إدارة المنصة غير مفعّلة. تُدار الأندية من خادم النظام.']);
        }
        if (! $user->isPlatformAdmin() && ! $user->tenant?->isOperational() && ! $user->hasPermission('billing.view')) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages(['email' => 'حساب النادي موقوف. تواصل مع إدارة النادي.']);
        }

        return redirect()->intended(route($user->homeRoute()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
