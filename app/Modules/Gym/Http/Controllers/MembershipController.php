<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Export\TableExporter;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Http\GymPresenter;
use App\Modules\Gym\Models\Coupon;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\CouponService;
use App\Modules\Gym\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MembershipController extends Controller
{
    public function __construct(private readonly MembershipService $service) {}

    public function index(Request $request): Response|StreamedResponse
    {
        $filter = $request->string('filter')->toString() ?: 'active';

        $query = Membership::with('member:id,full_name,member_no,phone')
            ->when($filter === 'expiring', fn ($q) => $q->expiringWithin((int) ($request->integer('days') ?: 7))->orderBy('ends_on'))
            ->when(in_array($filter, ['active', 'frozen', 'pending', 'scheduled', 'expired', 'cancelled'], true), fn ($q) => $q->where('status', $filter))
            ->when($request->integer('plan_id'), fn ($q, $id) => $q->where('membership_plan_id', $id))
            ->when($filter !== 'expiring', fn ($q) => $q->latest('id'));

        if ($request->string('format')->toString() && $request->user()->can('members.export')) {
            $rows = function () use ($query) {
                foreach ($query->lazy(500) as $m) {
                    yield [$m->member?->member_no, $m->member?->full_name, $m->member?->phone, $m->plan_snapshot['name'] ?? '',
                        Membership::KIND_LABELS[$m->kind] ?? $m->kind, Membership::STATUS_LABELS[$m->status] ?? $m->status,
                        $m->starts_on->toDateString(), $m->ends_on->toDateString(), $m->total, $m->sessions_total, $m->sessions_used];
                }
            };

            return TableExporter::download('memberships-'.$filter, ['رقم العضوية', 'الاسم', 'الهاتف', 'الباقة', 'النوع', 'الحالة', 'من', 'إلى', 'الإجمالي', 'الحصص', 'المستخدم'], $rows(), $request->string('format')->toString());
        }

        return Inertia::render('Gym/Memberships/Index', [
            'memberships' => $query->paginate(25)->withQueryString()->through(fn (Membership $m) => GymPresenter::membership($m) + [
                'member' => $m->member?->only('id', 'full_name', 'member_no', 'phone'),
            ]),
            'counts' => Membership::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
                ->put('expiring', Membership::expiringWithin(7)->count()),
            'plans' => MembershipPlan::orderBy('sort')->get(['id', 'name']),
            'filters' => ['filter' => $filter, 'plan_id' => $request->integer('plan_id') ?: null],
        ]);
    }

    /** Price preview for the sell form (coupon + signup fee + upgrade credit). */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'member_id' => ['required', TenantRule::exists('gym_members')],
            'membership_plan_id' => ['required', TenantRule::exists('gym_membership_plans')],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'upgrade_from' => ['nullable', TenantRule::exists('gym_memberships')],
        ]);

        $member = Member::findOrFail($data['member_id']);
        $plan = MembershipPlan::findOrFail($data['membership_plan_id']);
        $credit = '0.000';
        if (! empty($data['upgrade_from'])) {
            $credit = $this->service->unusedValue(Membership::findOrFail($data['upgrade_from']));
            $credit = Money::cmp($credit, $plan->price) > 0 ? Money::normalize($plan->price) : $credit;
        }
        $isFirst = ! $member->memberships()->whereNot('status', Membership::CANCELLED)->exists();
        $fee = $isFirst && empty($data['upgrade_from']) ? Money::normalize($plan->signup_fee) : '0.000';

        $discount = '0.000';
        $couponError = null;
        if (! empty($data['coupon_code'])) {
            try {
                $coupon = DB::transaction(fn () => app(CouponService::class)->validate($data['coupon_code'], $member, $plan, Money::sub($plan->price, $credit)));
                $discount = $coupon->discountFor(Money::sub($plan->price, $credit));
            } catch (BusinessRuleException $e) {
                $couponError = $e->getMessage();
            }
        }

        $start = CarbonImmutable::today();

        return response()->json([
            'price' => Money::normalize($plan->price),
            'signup_fee' => $fee,
            'credit' => $credit,
            'discount' => $discount,
            'total' => Money::sub(Money::add($plan->price, $fee), Money::add($discount, $credit)),
            'coupon_error' => $couponError,
            'ends_on' => $plan->endDateFrom($start)->toDateString(),
        ]);
    }

    public function store(Request $request, Member $member): RedirectResponse
    {
        $data = $request->validate([
            'membership_plan_id' => ['required', TenantRule::exists('gym_membership_plans')],
            'mode' => ['required', 'in:new,renewal'],
            'starts_on' => ['nullable', 'date', 'after_or_equal:'.today()->subDays(7)->toDateString()],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if (! empty($data['discount']) && ! $request->user()->can('plans.manage')) {
            throw new BusinessRuleException('discount.forbidden', 'الخصم اليدوي يحتاج صلاحية إدارة الباقات.', 'discount');
        }

        $plan = MembershipPlan::findOrFail($data['membership_plan_id']);
        $options = collect($data)->only('coupon_code', 'discount', 'notes', 'starts_on')->filter(fn ($v) => $v !== null && $v !== '')->all();

        $membership = $data['mode'] === 'renewal'
            ? $this->service->renew($member, $plan, $options)
            : $this->service->sell($member, $plan, $options);

        return redirect()->route('club.invoices.show', $membership->invoice_id)
            ->with('success', $membership->status === Membership::PENDING
                ? 'تم إصدار الفاتورة. ستُفعّل العضوية بعد تأكيد الدفع.'
                : 'تم تفعيل العضوية.');
    }

    public function freeze(Request $request, Membership $membership): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $this->service->freeze($membership, $data['days'] ?? null, $data['reason'] ?? null);

        return back()->with('success', 'تم تجميد العضوية.');
    }

    public function resume(Membership $membership): RedirectResponse
    {
        $m = $this->service->resume($membership);

        return back()->with('success', 'تم استئناف العضوية. تاريخ الانتهاء الجديد: '.$m->ends_on->toDateString());
    }

    public function cancel(Request $request, Membership $membership): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->service->cancel($membership, $data['reason']);

        return back()->with('success', 'تم إلغاء العضوية.');
    }

    public function upgrade(Request $request, Membership $membership): RedirectResponse
    {
        $data = $request->validate([
            'membership_plan_id' => ['required', TenantRule::exists('gym_membership_plans')],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);
        $new = $this->service->upgrade($membership, MembershipPlan::findOrFail($data['membership_plan_id']), array_filter(['coupon_code' => $data['coupon_code'] ?? null]));

        return redirect()->route('club.invoices.show', $new->invoice_id)->with('success', 'تم إصدار فاتورة تغيير الباقة. ستُطبّق بعد الدفع.');
    }
}
