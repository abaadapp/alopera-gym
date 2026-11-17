<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\SubmittedOnce;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Http\GymPresenter;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\GiftService;
use App\Modules\Gym\Services\MemberService;
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Website\Support\GiftLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «هدايا الاشتراكات» (#192): issue a gift for an existing plan and collect it with the usual methods (its own invoice,
 * through PaymentService), list them, redeem one for a member (existing or new) and cancel one not used.
 * Money and state stay in GiftService: nothing here marks a gift paid or redeems it outside it.
 */
final class GiftController extends Controller
{
    /** The desk's methods; a bank transfer is recorded pending, and the gift waits for finance to confirm it. */
    private const METHODS = ['cash', 'card', 'bank_transfer', 'other'];

    public function __construct(private readonly GiftService $gifts, private readonly GiftLinks $links) {}

    public function index(Request $request): Response
    {
        $filter = $request->string('filter')->toString() ?: 'all';
        $today = today()->toDateString();
        $term = trim($request->string('q')->toString());

        $query = BranchContext::scope(GiftMembership::with(['invoice:id,number,status,total,paid_total,credited_total', 'member:id,full_name,member_no']))
            ->when($filter === 'paid', fn ($q) => $q->where('status', GiftMembership::PAID)->where(fn ($w) => $w->whereNull('expires_on')->orWhere('expires_on', '>=', $today)))
            ->when($filter === 'expired', fn ($q) => $q->where(fn ($w) => $w->where('status', GiftMembership::EXPIRED)
                ->orWhere(fn ($p) => $p->where('status', GiftMembership::PAID)->where('expires_on', '<', $today))))
            ->when(in_array($filter, [GiftMembership::PENDING, GiftMembership::REDEEMED, GiftMembership::CANCELLED, GiftMembership::REFUNDED], true), fn ($q) => $q->where('status', $filter))
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('number', 'ilike', $like)->orWhere('giver_name', 'ilike', $like)->orWhere('recipient_name', 'ilike', $like));
            })
            ->latest('id');

        $user = $request->user();

        return Inertia::render('Gym/Gifts/Index', [
            'gifts' => $query->paginate(in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 25)->withQueryString()
                ->through(fn (GiftMembership $g) => GymPresenter::gift($g)),
            'counts' => BranchContext::scope(GiftMembership::selectRaw('status, count(*) as how_many'))->groupBy('status')->pluck('how_many', 'status'),
            'plans' => $user->can('gifts.create') ? $this->giftablePlans() : [],
            'methods' => $user->can('payments.record') ? self::METHODS : [],
            'filters' => $request->only('filter', 'q', 'per_page'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'membership_plan_id' => ['required', TenantRule::exists('gym_membership_plans')->where('is_active', true)->where('giftable', true)->whereNull('deleted_at')],
            'giver_name' => ['required', 'string', 'max:120'],
            'giver_phone' => ['nullable', 'string', 'max:32', 'regex:/^[+0-9٠-٩\s-]{8,20}$/u'],
            'giver_email' => ['nullable', 'email', 'max:190'],
            'recipient_name' => ['required', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:300'],
            // «لاحقًا» issues the gift with its invoice open; it becomes usable once the invoice is paid in full.
            'payment_method' => ['required', 'in:later,'.implode(',', self::METHODS)],
            'payment_reference' => ['nullable', 'string', 'max:120', 'required_if:payment_method,card'],
            'client_key' => SubmittedOnce::RULE,
        ]);
        abort_if($data['payment_method'] !== 'later' && ! $request->user()->can('payments.record'), 403);

        $gift = SubmittedOnce::run($data['client_key'] ?? null, 'gift-issue', fn () => DB::transaction(function () use ($data) {
            $plan = MembershipPlan::findOrFail($data['membership_plan_id']);
            $gift = $this->gifts->issue($plan, $data + ['branch_id' => app(BranchContext::class)->id()], 'staff');
            if ($data['payment_method'] !== 'later' && $gift->invoice->isOpen()) {
                app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), $data['payment_method'], [
                    'reference' => $data['payment_reference'] ?? null,
                    'notes' => 'عند إصدار الهدية '.$gift->number,
                ]);
            }

            return $gift->fresh();
        }));

        return redirect()->route('club.gifts.show', $gift)->with('success', match (true) {
            $gift->status === GiftMembership::PAID => 'صدرت الهدية '.$gift->number.' وهي جاهزة للمشاركة.',
            $data['payment_method'] === 'bank_transfer' => 'صدرت الهدية '.$gift->number.'. التحويل ينتظر تأكيد المالية، وتصبح الهدية صالحة بعده.',
            default => 'صدرت الهدية '.$gift->number.' بانتظار الدفع، وتصبح صالحة بعد سداد فاتورتها كاملة.',
        });
    }

    public function show(Request $request, GiftMembership $gift): Response
    {
        $gift->load(['invoice', 'member:id,full_name,member_no']);
        $user = $request->user();
        $people = User::whereIn('id', array_filter([$gift->created_by, $gift->redeemed_by]))->pluck('name', 'id');
        // The link and its share card while the gift is usable, and for reference once used; not on a closed one.
        $linkReady = in_array($gift->displayStatus(), [GiftMembership::PAID, GiftMembership::REDEEMED], true);
        $online = app(OnlinePaymentService::class);

        return Inertia::render('Gym/Gifts/Show', [
            'gift' => GymPresenter::gift($gift) + [
                'created_by' => $people[$gift->created_by] ?? null,
                'redeemed_by' => $people[$gift->redeemed_by] ?? null,
                'plan_snapshot' => array_intersect_key($gift->plan_snapshot ?? [], array_flip(['type', 'sessions_limit', 'freeze_allowed', 'max_freeze_days', 'max_freezes'])),
            ],
            // The link and its share text only once the gift is paid: before that it opens nothing usable.
            'link' => $linkReady ? $this->links->card($gift) : null,
            'whatsapp' => $gift->isRedeemable() ? $this->links->whatsapp($gift) : null,
            // While it waits for its money: the pay page the buyer can be sent, when the club takes online payments.
            'pay_link' => $gift->status === GiftMembership::PENDING && $gift->invoice?->isOpen() && $online->available()
                ? route('site.pay.show', ['club' => app(TenantContext::class)->require()->slug, 'token' => $gift->invoice->public_token]) : null,
            'pending_transfer' => $gift->invoice_id ? Payment::where('invoice_id', $gift->invoice_id)->where('status', Payment::PENDING)->exists() : false,
            'events' => AuditLog::where('tenant_id', $gift->tenant_id)->where('subject_type', $gift->getMorphClass())->where('subject_id', $gift->id)
                ->where('event', 'like', 'gift.%')->latest('id')->limit(30)->get(['id', 'event', 'actor_name', 'created_at'])
                ->map(fn (AuditLog $a) => ['id' => $a->id, 'event' => $a->event, 'by' => $a->actor_name, 'at' => $a->created_at?->toIso8601String()]),
            'methods' => $user->can('payments.record') && $user->can('gifts.create') && $gift->status === GiftMembership::PENDING ? self::METHODS : [],
            'redeem' => [
                'min' => today()->toDateString(),
                'max' => today()->addDays(GiftService::MAX_START_DAYS)->toDateString(),
                'can_search' => $user->can('gifts.redeem'),
            ],
        ]);
    }

    /** Collect the gift's invoice in full with a desk method (PaymentService); InvoicePaid makes the gift usable. */
    public function pay(Request $request, GiftMembership $gift): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', 'in:'.implode(',', self::METHODS)],
            'reference' => ['nullable', 'string', 'max:120', 'required_if:method,card'],
            'client_key' => SubmittedOnce::RULE,
        ]);

        SubmittedOnce::run($data['client_key'] ?? null, 'gift-payment:'.$gift->id, function () use ($gift, $data) {
            $invoice = $gift->invoice_id ? Invoice::find($gift->invoice_id) : null;
            if ($gift->status !== GiftMembership::PENDING || ! $invoice?->isOpen()) {
                throw new BusinessRuleException('gift.not_pending', 'لا مبلغ مستحق على هذه الهدية.');
            }
            if (Payment::where('invoice_id', $invoice->id)->where('status', Payment::PENDING)->exists()) {
                throw new BusinessRuleException('gift.transfer_pending', 'على فاتورة الهدية تحويل ينتظر تأكيد المالية.');
            }

            return app(PaymentService::class)->record($invoice, $invoice->balance(), $data['method'], [
                'reference' => $data['reference'] ?? null,
                'notes' => 'تحصيل الهدية '.$gift->number,
            ]);
        });

        $gift->refresh();

        return back()->with('success', $gift->status === GiftMembership::PAID
            ? 'تم تحصيل الهدية '.$gift->number.' وهي جاهزة للمشاركة.'
            : 'سُجّل التحويل وينتظر تأكيد المالية، وتصبح الهدية صالحة بعد التأكيد.');
    }

    /** «تفعيل الهدية»: for a member found by search, or one created here; once, whoever asks first (GiftService). */
    public function redeem(Request $request, GiftMembership $gift): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:existing,new'],
            'member_id' => ['required_if:mode,existing', 'nullable', TenantRule::exists('gym_members')->whereNull('deleted_at')],
            'full_name' => ['required_if:mode,new', 'nullable', 'string', 'max:160'],
            'phone' => ['required_if:mode,new', 'nullable', 'string', 'max:32', 'regex:/^[+0-9٠-٩\s-]{8,20}$/u'],
            'email' => ['nullable', 'email', 'max:255', TenantRule::unique('gym_members', 'email')],
            'starts_on' => ['required', 'date_format:Y-m-d'],
        ]);

        $gift = DB::transaction(function () use ($data, $gift) {
            $member = $data['mode'] === 'existing'
                ? Member::findOrFail($data['member_id'])
                : app(MemberService::class)->create([
                    'full_name' => $data['full_name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null,
                    'branch_id' => app(BranchContext::class)->assign($gift->branch_id),
                ]);

            return $this->gifts->redeem($gift, $member, $data['starts_on'], 'staff');
        });

        return back()->with('success', 'تم تفعيل الهدية '.$gift->number.' للعضو '.$gift->member?->full_name.'.');
    }

    public function cancel(Request $request, GiftMembership $gift): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:250']]);
        $gift = $this->gifts->cancel($gift, $data['reason']);

        return back()->with('success', $gift->paid_at !== null
            ? 'أُلغيت الهدية '.$gift->number.'. المبلغ المدفوع باقٍ على فاتورتها: ردّه من المالية بإشعار دائن.'
            : 'أُلغيت الهدية '.$gift->number.' وفاتورتها.');
    }

    /** The member search of the redeem dialog, for whoever may redeem (the members list may be closed to them). */
    public function members(Request $request): JsonResponse
    {
        return response()->json(Member::with(['currentMembership', 'upcomingMembership'])->search($request->string('q')->toString())->limit(10)->get()
            ->map(fn (Member $m) => GymPresenter::memberRow($m)));
    }

    /** @return list<array<string, mixed>> */
    private function giftablePlans(): array
    {
        return MembershipPlan::where('is_active', true)->where('giftable', true)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (MembershipPlan $p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->price, 'duration_label' => $p->durationLabel(), 'is_public' => (bool) $p->is_public])
            ->all();
    }
}
