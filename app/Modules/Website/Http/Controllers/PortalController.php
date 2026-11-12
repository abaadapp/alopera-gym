<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Phone;
use App\Core\Support\SiteLanguage;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Http\GymPresenter;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Website\Http\RendersSite;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Member portal: status and expiry, QR for the door, renewal and payment, invoices, class booking. */
class PortalController extends Controller
{
    use RendersSite;

    /** A membership as the portal shows it: on the English portal its plan's English name, when written. @return array<string, mixed> */
    private function membership(Membership $m): array
    {
        $row = GymPresenter::membership($m);
        if (SiteLanguage::isEnglish() && ($english = MembershipPlan::withTrashed()->whereKey($m->membership_plan_id)->value('name_en'))) {
            $row['plan'] = $english;
        }

        return $row;
    }

    private function member(Request $request): Member
    {
        /** @var Member $member */
        $member = $request->user('member');

        return $member;
    }

    public function dashboard(Request $request, OnlinePaymentService $online): Response
    {
        $member = $this->member($request);
        $current = Membership::where('member_id', $member->id)->whereIn('status', [Membership::ACTIVE, Membership::FROZEN])->orderByDesc('ends_on')->first();
        $next = Membership::where('member_id', $member->id)->whereIn('status', [Membership::SCHEDULED, Membership::PENDING])->orderBy('starts_on')->first();

        return $this->page('Portal/Dashboard', [
            'profile' => $member->only('full_name', 'member_no', 'phone', 'email') + ['qr_token' => $member->status === Member::ACTIVE ? $member->qr_token : null, 'photo_url' => $member->photoUrl()],
            'current' => $current ? $this->membership($current) : null,
            'next' => $next ? $this->membership($next) : null,
            'upcoming' => Booking::with(['session.program:id,name,name_en,color', 'session.trainer:id,name,name_en'])->where('member_id', $member->id)
                ->where('status', Booking::BOOKED)->whereHas('session', fn ($q) => $q->where('starts_at', '>', now()))
                ->get()->sortBy(fn ($b) => $b->session->starts_at)->values()->take(5)
                ->map(fn ($b) => ['id' => $b->id, 'session' => GymPresenter::siteSession($b->session)]),
            'openInvoices' => Invoice::where('billable_type', 'member')->where('billable_id', $member->id)->open()->latest('id')->get()
                ->map(fn ($i) => $i->only('id', 'number', 'total') + ['balance' => $i->balance(), 'issue_date' => $i->issue_date->toDateString()]),
            'visits' => CheckIn::where('member_id', $member->id)->where('result', 'allowed')->where('checked_in_at', '>=', now()->subDays(30))->count(),
            'onlinePayments' => $online->available(),
        ], __('حسابي'));
    }

    public function renew(Request $request): Response
    {
        $member = $this->member($request);
        $last = Membership::where('member_id', $member->id)->whereNot('status', Membership::CANCELLED)->orderByDesc('ends_on')->first();

        return $this->page('Portal/Renew', [
            'plans' => MembershipPlan::public()->orderBy('sort')->get()->map(fn ($p) => GymPresenter::sitePlan($p)),
            'current' => $last ? GymPresenter::membership($last) : null,
            'hasPending' => Membership::where('member_id', $member->id)->where('status', Membership::PENDING)->exists(),
        ], __('تجديد الاشتراك'));
    }

    public function storeRenewal(Request $request, MembershipService $memberships, OnlinePaymentService $online): HttpResponse
    {
        $member = $this->member($request);
        $data = $request->validate([
            'membership_plan_id' => ['required', TenantRule::exists('gym_membership_plans')->where('is_public', true)->where('is_active', true)->whereNull('deleted_at')],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);

        if (Membership::where('member_id', $member->id)->where('status', Membership::PENDING)->exists()) {
            throw new BusinessRuleException('renew.pending', 'لديك طلب اشتراك بانتظار الدفع. أكمل دفعه من صفحة الفواتير.', 'membership_plan_id');
        }

        $membership = $memberships->renew($member, MembershipPlan::findOrFail($data['membership_plan_id']), array_filter(['coupon_code' => $data['coupon_code'] ?? null]));
        $invoice = $membership->invoice;

        if ($invoice->isOpen() && $online->available()) {
            return Inertia::location($online->start($invoice, 'portal')->checkout_url);
        }

        return redirect()->route('portal.invoices')->with('success', $invoice->isOpen() ? __('تم إنشاء فاتورة التجديد. ادفعها في الاستقبال لتفعيل الاشتراك.') : __('تم التجديد.'));
    }

    public function invoices(Request $request, OnlinePaymentService $online): Response
    {
        $member = $this->member($request);

        return $this->page('Portal/Invoices', [
            'invoices' => Invoice::with(['items:id,invoice_id,description,line_total', 'payments' => fn ($q) => $q->where('status', 'completed')])
                ->where('billable_type', 'member')->where('billable_id', $member->id)
                ->latest('id')->limit(100)->get()
                ->map(fn (Invoice $i) => $i->only('id', 'number', 'status', 'total', 'paid_total', 'currency') + [
                    'balance' => $i->balance(), 'issue_date' => $i->issue_date->toDateString(),
                    'items' => $i->items->map(fn ($it) => $it->only('description', 'line_total')),
                    'payments' => $i->payments->map(fn ($p) => $p->only('receipt_number', 'kind', 'method', 'amount') + ['paid_at' => $p->paid_at?->format('Y-m-d')]),
                    'public_url' => route('site.invoice', ['token' => $i->public_token]),
                ]),
            'onlinePayments' => $online->available(),
            'history' => Membership::where('member_id', $member->id)->latest('id')->get()->map(fn ($m) => GymPresenter::membership($m)),
        ], __('الفواتير والسجل'));
    }

    public function pay(Request $request, Invoice $invoice, OnlinePaymentService $online): HttpResponse
    {
        $member = $this->member($request);
        abort_unless($invoice->billable_type === 'member' && (int) $invoice->billable_id === $member->id, 404);

        return Inertia::location($online->start($invoice, 'portal')->checkout_url);
    }

    public function classes(Request $request, TenantContext $tenants): Response
    {
        $member = $this->member($request);
        $tenant = $tenants->require();
        $start = CarbonImmutable::now();
        $window = (int) $tenant->setting('booking_window_days', 14);

        $myBookings = Booking::where('member_id', $member->id)->where('status', Booking::BOOKED)->pluck('id', 'class_session_id');

        return $this->page('Portal/Classes', [
            'sessions' => ClassSession::with(['program:id,name,name_en,color', 'trainer:id,name,name_en'])
                ->where('status', ClassSession::SCHEDULED)->where('starts_at', '>', $start)->where('starts_at', '<', $start->addDays($window))
                ->whereHas('program', fn ($q) => $q->where('is_active', true))
                ->orderBy('starts_at')->get()
                ->map(fn ($s) => GymPresenter::siteSession($s) + ['booking_id' => $myBookings[$s->id] ?? null]),
            'membership' => ($m = Membership::where('member_id', $member->id)
                ->whereIn('status', [Membership::ACTIVE, Membership::FROZEN, Membership::SCHEDULED])
                ->orderByRaw("case status when 'active' then 0 when 'frozen' then 1 else 2 end")->orderBy('starts_on')->first())
                ? GymPresenter::membership($m) : null,
            'cutoffHours' => (int) $tenant->setting('booking_cancel_cutoff_hours', 3),
            'history' => Booking::with('session.program:id,name,name_en')->where('member_id', $member->id)->latest('id')->limit(20)->get()
                ->map(fn ($b) => ['id' => $b->id, 'status' => $b->status, 'program' => SiteLanguage::pick($b->session?->program?->name, $b->session?->program?->name_en), 'starts_at' => $b->session?->starts_at->format('Y-m-d H:i')]),
        ], __('حجز الحصص'));
    }

    public function book(Request $request, ClassSession $session, BookingService $bookings): RedirectResponse
    {
        $bookings->book($this->member($request), $session, 'portal');

        return back()->with('success', __('تم تأكيد حجزك.'));
    }

    public function cancelBooking(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        abort_unless($booking->member_id === $this->member($request)->id, 404);
        $bookings->cancel($booking, false, 'إلغاء من العضو');

        return back()->with('success', __('تم إلغاء الحجز.'));
    }

    public function profile(Request $request): Response
    {
        $member = $this->member($request);

        return $this->page('Portal/Profile', [
            'profile' => $member->only('full_name', 'phone', 'email', 'marketing_opt_in', 'emergency_contact_name', 'emergency_contact_phone') + [
                'date_of_birth' => $member->date_of_birth?->toDateString(),
            ],
        ], __('ملفي الشخصي'));
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $member = $this->member($request);
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255', TenantRule::unique('gym_members', 'email')->ignore($member->id)],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'marketing_opt_in' => ['boolean'],
            'current_password' => ['nullable', 'required_with:password', 'current_password:member'],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);

        if (array_key_exists('marketing_opt_in', $data)) {
            $optIn = (bool) $data['marketing_opt_in'];
            if ($optIn !== $member->marketing_opt_in) {
                $member->forceFill($optIn
                    ? ['marketing_opt_in' => true, 'marketing_consent_at' => now(), 'marketing_opt_out_at' => null]
                    : ['marketing_opt_in' => false, 'marketing_opt_out_at' => now()]);
            }
        }
        if (! empty($data['emergency_contact_phone'])) {
            $data['emergency_contact_phone'] = Phone::normalize($data['emergency_contact_phone']);
        }
        $member->fill(collect($data)->only('email', 'emergency_contact_name', 'emergency_contact_phone')->all());
        if (! empty($data['password'])) {
            // A new remember token ends the sessions remembered on other devices; this one stays signed in.
            $member->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)]);
        }
        $member->save();

        return back()->with('success', __('تم حفظ بياناتك.'));
    }
}
