<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Auth\DemoAccounts;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Phone;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Http\GymPresenter;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\MemberService;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Website\Http\RendersSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Online sign-up (choose a plan, register, pay) and member portal sign-in.
 * The new member and her pending membership appear in the club panel at once.
 */
class PortalAuthController extends Controller
{
    use RendersSite;

    public function register(Request $request, TenantContext $tenants, OnlinePaymentService $online): Response
    {
        abort_unless($tenants->require()->setting('member_signup_enabled', true), 404);

        return $this->page('Site/Join', [
            'plans' => MembershipPlan::public()->orderBy('sort')->get()->map(fn ($p) => GymPresenter::plan($p)),
            'selected' => $request->integer('plan') ?: null,
            'onlinePayments' => $online->available(),
            'payAtReception' => (bool) $tenants->require()->setting('allow_pay_at_reception', true),
        ], 'اشترك الآن', 'سجّل واختر باقتك وادفع أونلاين خلال دقيقة.');
    }

    public function storeRegistration(Request $request, MemberService $members, MembershipService $memberships, OnlinePaymentService $online, TenantContext $tenants): HttpResponse
    {
        abort_unless($tenants->require()->setting('member_signup_enabled', true), 404);

        $data = $request->validate([
            'membership_plan_id' => ['required', TenantRule::exists('gym_membership_plans')->where('is_public', true)->where('is_active', true)->whereNull('deleted_at')],
            'full_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^[+0-9٠-٩\s-]{8,20}$/u'],
            'email' => ['nullable', 'email', 'max:255', TenantRule::unique('gym_members', 'email')],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'marketing_opt_in' => ['boolean'],
            'accept_terms' => ['accepted'],
            'pay_now' => ['boolean'],
            'website' => ['prohibited'], // honeypot
        ], ['accept_terms.accepted' => 'يجب الموافقة على الشروط والأحكام.']);

        if (Member::withTrashed()->where('phone', Phone::normalize($data['phone']))->exists()) {
            throw ValidationException::withMessages(['phone' => 'رقم الهاتف مسجّل مسبقًا. سجّل الدخول إلى بوابة الأعضاء أو تواصل مع الاستقبال.']);
        }

        $membership = DB::transaction(function () use ($data, $members, $memberships) {
            $member = $members->create([
                'full_name' => $data['full_name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'source' => 'website',
                'marketing_opt_in' => (bool) ($data['marketing_opt_in'] ?? false),
                'whatsapp_opt_in' => true,
            ], $data['password']);

            return $memberships->sell($member, MembershipPlan::findOrFail($data['membership_plan_id']), array_filter([
                'coupon_code' => $data['coupon_code'] ?? null,
            ]));
        });

        Auth::guard('member')->login($membership->member);
        $request->session()->regenerate();

        $invoice = $membership->invoice;
        if (($data['pay_now'] ?? true) && $invoice->isOpen() && $online->available()) {
            try {
                $intent = $online->start($invoice, 'website');

                return Inertia::location($intent->checkout_url);
            } catch (BusinessRuleException) {
                // fall through to the portal with a pay-later message
            }
        }

        return redirect()->route('portal.dashboard')->with('success', $invoice->isOpen()
            ? 'تم التسجيل! أكمل الدفع لتفعيل اشتراكك (أونلاين من صفحة الفواتير أو في الاستقبال).'
            : 'تم التسجيل وتفعيل اشتراكك. أهلًا بك!');
    }

    public function login(): Response
    {
        return $this->page('Portal/Login', [], 'دخول الأعضاء');
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $field = str_contains($data['login'], '@') ? 'email' : 'phone';
        $value = $field === 'phone' ? Phone::normalize($data['login']) : strtolower($data['login']);

        // Member::where is tenant-scoped: a member can only sign in to her own club's portal.
        $member = Member::where($field, $value)->first();

        if (! $member || ! $member->password || $member->status === Member::BLOCKED || DemoAccounts::blocked($member->email)
            || ! Auth::guard('member')->attempt(['id' => $member->id, 'password' => $data['password']], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['login' => 'بيانات الدخول غير صحيحة. إن لم تُفعّل حسابك بعد، تواصل مع الاستقبال.']);
        }

        $request->session()->regenerate();
        $member->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect()->intended(route('portal.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('member')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('site.home');
    }
}
