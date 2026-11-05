<?php

namespace App\Http\Middleware;

use App\Modules\Gym\Models\Member;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A member blocked by staff is out of the portal at once, not only at the next sign-in: an open session (or a
 * remembered one) no longer reads their invoices or changes their details.
 */
final class EnsureMemberActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $member = Auth::guard('member')->user();
        if ($member instanceof Member && $member->status === Member::BLOCKED) {
            Auth::guard('member')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('portal.login', ['club' => $request->route('club')])->with('error', 'تعذّر الدخول إلى البوابة. تواصل مع الاستقبال.');
        }

        return $next($request);
    }
}
