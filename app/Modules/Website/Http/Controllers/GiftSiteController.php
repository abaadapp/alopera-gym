<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Phone;
use App\Core\Support\SiteLanguage;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Gym\Http\GymPresenter;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\GiftService;
use App\Modules\Gym\Services\GiftWhatsApp;
use App\Modules\Gym\Services\MemberService;
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Website\Http\RendersSite;
use App\Modules\Website\Support\GiftLinks;
use Carbon\CarbonImmutable;
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
 * Gift memberships on the website (#192):
 *  - «إهداء هذه الباقة»: a published plan offered as a gift, bought without a member account and paid online through
 *    the existing Paymob path (OnlinePaymentService). The recipient's WhatsApp number is required and confirmed first,
 *    by a message she sends from it (GiftWhatsApp); only then can it be paid. The return from the gateway proves
 *    nothing: the gift is usable — and sent to her WhatsApp — only once the signed webhook pays its invoice
 *    (InvoicePaid → ActivatePaidItems).
 *  - The gift's card at its secret link: names, plan, duration, message and the redemption deadline — never the price,
 *    the invoice or anyone's phone.
 *  - «استلمي هديتك»: the recipient signs in or creates an account, picks a start date and redeems it (GiftService).
 */
class GiftSiteController extends Controller
{
    use RendersSite;

    /** Gifts this browser bought (session), so only the buyer sees «أكملي الدفع» on a gift still waiting. */
    private const BOUGHT = 'gifts.bought';

    public function __construct(private readonly GiftService $gifts, private readonly GiftLinks $links) {}

    /** Only a plan the site shows (active and public) and the club offers as a gift; anything else is not found. */
    private function giftablePlan(int $plan): MembershipPlan
    {
        return MembershipPlan::public()->where('giftable', true)->findOrFail($plan);
    }

    private function gift(string $token): GiftMembership
    {
        return GiftMembership::findByToken($token) ?? abort(404);
    }

    public function create(int $plan, OnlinePaymentService $online): Response
    {
        $plan = $this->giftablePlan($plan);

        // The total as the gift's invoice will be issued (InvoiceService computes the tax the same way): no other fees.
        $preview = app(InvoiceService::class)->preview(null, [['description' => $plan->name, 'unit_price' => $plan->price]], ['type' => 'gift']);

        return $this->page('Site/GiftBuy', [
            'plan' => GymPresenter::sitePlan($plan),
            'summary' => ['price' => $preview->subtotal, 'tax' => $preview->tax_total, 'tax_rate' => (float) $preview->tax_rate, 'total' => $preview->total],
            'onlinePayments' => $online->available(),
            // The recipient confirms her WhatsApp number before the gift is paid; said up front when that cannot be done.
            'whatsapp' => $this->whatsappState(),
            'terms' => $this->terms(),
        ], __('إهداء باقة :name', ['name' => SiteLanguage::pick($plan->name, $plan->name_en)]));
    }

    public function store(Request $request, int $plan, OnlinePaymentService $online): HttpResponse|RedirectResponse
    {
        $plan = $this->giftablePlan($plan);
        $data = $request->validate([
            'giver_name' => ['required', 'string', 'max:120'],
            'giver_phone' => ['required', 'string', 'max:32', 'regex:/^[+0-9٠-٩\s-]{8,20}$/u'],
            'giver_email' => ['nullable', 'email', 'max:190'],
            'recipient_name' => ['required', 'string', 'max:120'],
            'recipient_phone' => ['required', 'string', Phone::mobileRule(SiteLanguage::word('أدخل رقم واتساب صحيحًا للمستلمة، مثل 9XXXXXXX أو ‎+968 9XXX XXXX.'))],
            'message' => ['nullable', 'string', 'max:300'],
            'accept_terms' => ['accepted'],
            'website' => ['prohibited'], // honeypot
        ]);
        if (! $online->available()) {
            throw ValidationException::withMessages(['error' => SiteLanguage::word('الدفع الإلكتروني غير متاح حاليًا. يمكن شراء الهدية من استقبال النادي.')]);
        }
        // The website's path confirms the number before payment: without the club's WhatsApp it cannot, so no gift.
        if ($refusal = app(GiftWhatsApp::class)->verificationRefusal()) {
            throw ValidationException::withMessages(['error' => SiteLanguage::word('تأكيد رقم واتساب المستلمة غير متاح حاليًا، فلا يمكن شراء الهدية من الموقع الآن. يمكن شراؤها من استقبال النادي.')]);
        }

        $gift = DB::transaction(fn () => $this->gifts->issue($plan, [
            'giver_name' => $data['giver_name'],
            'giver_phone' => Phone::normalize($data['giver_phone']),
            'giver_email' => $data['giver_email'] ?? null,
            'recipient_name' => $data['recipient_name'],
            'recipient_phone' => $data['recipient_phone'],
            'message' => $data['message'] ?? null,
        ], 'site'));
        $request->session()->push(self::BOUGHT, $gift->id);

        // Next: the recipient confirms her number from WhatsApp; only then is the gift paid.
        return redirect()->route('site.gift.verify', ['token' => $gift->linkToken()]);
    }

    /**
     * «تأكيد رقم المستلمة»: the buyer's own page between the form and the payment. The number as typed (to check it),
     * the message the recipient sends from it to the club's number, its state (refreshed by the page), and once
     * confirmed, the payment. Only for the browser that bought it; anyone else sees the card.
     */
    public function verify(Request $request, string $token, OnlinePaymentService $online): Response|RedirectResponse
    {
        $gift = $this->gift($token);
        if (! $this->isBuyer($request, $gift) || $gift->status !== GiftMembership::PENDING) {
            return redirect()->route('site.gift.show', ['token' => $token]);
        }
        $whatsapp = app(GiftWhatsApp::class);

        return $this->page('Site/GiftVerify', [
            'gift' => $this->card($gift),
            'token' => $token,
            'recipient_phone' => $gift->recipient_phone,
            'verification' => (fn (array $v) => ['refusal_text' => $v['refusal_text'] !== null ? SiteLanguage::word($v['refusal_text']) : null] + $v)($whatsapp->verification($gift, SiteLanguage::isEnglish())),
            'total' => $gift->invoice?->balance(),
            'can_pay' => $gift->phoneVerified() && $online->available() && (bool) $gift->invoice?->isOpen(),
            'online_payments' => $online->available(),
            'terms' => $this->terms(),
        ], __('تأكيد رقم المستلمة'));
    }

    /** A new code (the old one stops), while the number waits for its confirmation. */
    public function newCode(Request $request, string $token): RedirectResponse
    {
        $gift = $this->buyersPendingGift($request, $token);
        if (! $gift->phoneVerified()) {
            app(GiftWhatsApp::class)->newCode($gift);
        }

        return redirect()->route('site.gift.verify', ['token' => $token]);
    }

    /** The buyer corrects the number before it is confirmed and before paying: a new code for the new number. */
    public function changePhone(Request $request, string $token): RedirectResponse
    {
        $gift = $this->buyersPendingGift($request, $token);
        $data = $request->validate([
            'recipient_phone' => ['required', 'string', Phone::mobileRule(SiteLanguage::word('أدخل رقم واتساب صحيحًا للمستلمة، مثل 9XXXXXXX أو ‎+968 9XXX XXXX.'))],
        ]);
        if ($gift->phoneVerified()) {
            throw ValidationException::withMessages(['recipient_phone' => SiteLanguage::word('تم تأكيد الرقم ولا يُغيَّر من هنا. تواصل مع النادي لتغييره.')]);
        }
        try {
            $gift = app(GiftWhatsApp::class)->changeRecipient($gift, $data['recipient_phone']);
        } catch (BusinessRuleException $e) {
            throw ValidationException::withMessages(['recipient_phone' => SiteLanguage::word($e->getMessage())]);
        }

        return redirect()->route('site.gift.verify', ['token' => $gift->linkToken()]);
    }

    /** Pay for the gift, once its number is confirmed (OnlinePaymentService refuses it before, whatever the page). */
    public function pay(Request $request, string $token, OnlinePaymentService $online): HttpResponse|RedirectResponse
    {
        $gift = $this->buyersPendingGift($request, $token);
        try {
            return Inertia::location($online->start($gift->invoice, 'website')->checkout_url);
        } catch (BusinessRuleException $e) {
            return redirect()->route('site.gift.verify', ['token' => $token])->with('error', SiteLanguage::word($e->getMessage()));
        }
    }

    /**
     * Where the gift's message is, as its buyer reads it: the state in the page's language and, when it did not go or
     * waits, what to do. No provider error and no number: those stay with staff.
     *
     * @return array{state: string, label: string, note: string|null}
     */
    private function buyerDelivery(GiftMembership $gift): array
    {
        $state = app(GiftWhatsApp::class)->delivery($gift)['state'];
        $note = match ($state) {
            'not_due' => $gift->phoneVerified() ? null : 'تؤكد المستلمة رقمها برسالة منها، ثم يتم الدفع، فتُرسل الهدية.',
            'waiting' => 'لم تُرسل بعد؛ يمكنك مشاركة رابط الهدية بنفسك.',
            'failed' => 'تعذّر إرسال الهدية إلى واتساب المستلمة. شارك رابط الهدية بنفسك أو تواصل مع النادي.',
            default => null,
        };

        return ['state' => $state, 'label' => SiteLanguage::word(GiftMembership::DELIVERY_LABELS[$state] ?? $state), 'note' => $note !== null ? SiteLanguage::word($note) : null];
    }

    private function isBuyer(Request $request, GiftMembership $gift): bool
    {
        return in_array($gift->id, (array) $request->session()->get(self::BOUGHT, []), true);
    }

    private function buyersPendingGift(Request $request, string $token): GiftMembership
    {
        $gift = $this->gift($token);
        abort_unless($this->isBuyer($request, $gift) && $gift->status === GiftMembership::PENDING, 404);

        return $gift;
    }

    /** @return array{available: bool, refusal: string|null} */
    private function whatsappState(): array
    {
        $refusal = app(GiftWhatsApp::class)->verificationRefusal();

        return ['available' => $refusal === null, 'refusal' => $refusal !== null ? SiteLanguage::word('تأكيد رقم واتساب المستلمة غير متاح حاليًا، فلا يمكن شراء الهدية من الموقع الآن. يمكن شراؤها من استقبال النادي.') : null];
    }

    public function show(Request $request, string $token, OnlinePaymentService $online): Response
    {
        $gift = $this->gift($token);
        $buyer = $this->isBuyer($request, $gift);
        $redeemable = $gift->isRedeemable();

        return $this->page('Site/GiftCard', [
            'gift' => $this->card($gift),
            'token' => $token,
            'link' => $gift->paid_at ? $this->links->card($gift) : null,
            'whatsapp' => $redeemable ? $this->links->whatsapp($gift, SiteLanguage::isEnglish()) : null,
            'claim_url' => $redeemable ? route('site.gift.claim', ['token' => $token]) : null,
            // The buyer, back before the webhook or after leaving the checkout: finish paying on the invoice's pay page,
            // or first confirm the recipient's number (the website's gift is paid only after that).
            'pay_url' => $buyer && $gift->status === GiftMembership::PENDING && $gift->invoice?->isOpen() && $online->available()
                ? ($gift->phoneVerified() ? route('site.pay.show', ['token' => $gift->invoice->public_token]) : route('site.gift.verify', ['token' => $token])) : null,
            'is_buyer' => $buyer,
            // The buyer's own view of where the gift's WhatsApp message is; nobody else holding the link sees it.
            'delivery' => $buyer ? $this->buyerDelivery($gift) : null,
            'terms' => $this->terms(),
        ], __('هدية لـ :name', ['name' => $gift->recipient_name]));
    }

    /** «استلمي هديتك»: a member signed in goes on to redeem; anyone else signs in or creates an account first. */
    public function claim(Request $request, string $token): Response|RedirectResponse
    {
        $gift = $this->gift($token);
        if (! $gift->isRedeemable()) {
            return redirect()->route('site.gift.show', ['token' => $token]);
        }
        if (Auth::guard('member')->check()) {
            return redirect()->route('portal.gift.redeem', ['token' => $token]);
        }
        // Signing in from here comes back to the redemption (PortalAuthController::authenticate → intended).
        $request->session()->put('url.intended', route('portal.gift.redeem', ['token' => $token]));

        return $this->page('Site/GiftClaim', [
            'gift' => $this->card($gift),
            'token' => $token,
            'login_url' => route('portal.login'),
        ], __('استلام الهدية'));
    }

    /** A new account for the recipient (no plan is sold here: the gift is the plan), then on to redeem it. */
    public function register(Request $request, string $token, MemberService $members): RedirectResponse
    {
        $gift = $this->gift($token);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^[+0-9٠-٩\s-]{8,20}$/u'],
            'email' => ['nullable', 'email', 'max:255', TenantRule::unique('gym_members', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)],
            'accept_terms' => ['accepted'],
            'website' => ['prohibited'], // honeypot
        ]);
        if (! $gift->isRedeemable()) {
            return redirect()->route('site.gift.show', ['token' => $token]);
        }
        // A gift tied to the recipient's number makes an account only with that number (the link may reach others).
        if ($gift->recipient_phone !== null && Phone::normalize($data['phone']) !== $gift->recipient_phone) {
            throw ValidationException::withMessages(['phone' => SiteLanguage::word('هذه الهدية مرتبطة برقم جوال المستلمة. أنشئي الحساب بذلك الرقم.')]);
        }
        // One account per number (and per e-mail, above), whatever the gift: a duplicate is never made from a link.
        if (Member::withTrashed()->where('phone', Phone::normalize($data['phone']))->exists()) {
            throw ValidationException::withMessages(['phone' => SiteLanguage::word('هذا الرقم مسجّل لدينا. سجّلي الدخول لاستلام الهدية.')]);
        }

        $member = $members->create([
            'full_name' => $data['full_name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null,
            'branch_id' => $gift->branch_id, 'source' => 'website',
        ], $data['password']);
        Auth::guard('member')->login($member);
        $request->session()->regenerate();

        return redirect()->route('portal.gift.redeem', ['token' => $token]);
    }

    /** Portal: the recipient picks the first day and confirms. */
    public function redeemForm(Request $request, string $token): Response|RedirectResponse
    {
        $gift = $this->gift($token);
        if (! $gift->isRedeemable()) {
            return redirect()->route('site.gift.show', ['token' => $token]);
        }
        $member = $request->user('member');
        $today = CarbonImmutable::today();
        // The day after the last paid period, when that falls inside the window: no overlap to be refused for.
        $last = Membership::where('member_id', $member->id)->whereIn('status', [Membership::ACTIVE, Membership::SCHEDULED, Membership::FROZEN])->max('ends_on');
        $suggested = $last ? CarbonImmutable::parse($last)->addDay() : $today;
        $max = $today->addDays(GiftService::MAX_START_DAYS);

        return $this->page('Portal/GiftRedeem', [
            'gift' => $this->card($gift),
            'token' => $token,
            'min' => $today->toDateString(),
            'max' => $max->toDateString(),
            'suggested' => ($suggested->lt($today) ? $today : ($suggested->gt($max) ? $max : $suggested))->toDateString(),
            'covered_until' => $last ? CarbonImmutable::parse($last)->toDateString() : null,
            'member_name' => $member->full_name,
            // Tied to another number: said before anything is chosen (the redemption refuses it anyway).
            'not_recipient' => $gift->recipient_phone !== null && Phone::normalize((string) $member->phone) !== $gift->recipient_phone,
        ], __('استلام الهدية'));
    }

    public function redeem(Request $request, string $token): RedirectResponse
    {
        $gift = $this->gift($token);
        $data = $request->validate(['starts_on' => ['required', 'date_format:Y-m-d']]);

        $this->gifts->redeem($gift, $request->user('member'), $data['starts_on'], 'link');

        return redirect()->route('portal.dashboard')->with('success', SiteLanguage::word('استلمتِ هديتكِ. أُضيف الاشتراك إلى حسابكِ ويبدأ في التاريخ الذي اخترتِه.'));
    }

    /** What the card shows, in the page's language. @return array<string, mixed> */
    private function card(GiftMembership $gift): array
    {
        $snapshot = $gift->plan_snapshot ?? [];
        $english = SiteLanguage::isEnglish();
        $n = (int) ($snapshot['duration_value'] ?? 0);

        return [
            'status' => $gift->displayStatus(),
            'recipient_name' => $gift->recipient_name,
            'giver_name' => $gift->giver_name,
            'message' => $gift->message,
            'plan_name' => $english ? (($snapshot['name_en'] ?? null) ?: ($snapshot['name'] ?? '')) : ($snapshot['name'] ?? ''),
            'duration_label' => $english ? $n.' '.($snapshot['duration_unit'] ?? '').($n === 1 ? '' : 's') : ($snapshot['duration_label'] ?? ''),
            'expires_on' => $gift->paid_at ? $gift->expires_on?->toDateString() : null,
            'club' => app(TenantContext::class)->require()->name,
            // Whether only the recipient's number can take it; the number itself is never shown on the card.
            'for_recipient_number' => $gift->recipient_phone !== null,
        ];
    }

    /**
     * The gift's conditions, as every gift page states them, before payment too: the window (and the last day if paid
     * today), when the period starts, used once, no part refunded, and no refund once expired or used.
     *
     * @return array{redeem_days: int, max_start_days: int, expires_if_paid_today: string}
     */
    private function terms(): array
    {
        $days = $this->gifts->redeemDays();

        return ['redeem_days' => $days, 'max_start_days' => GiftService::MAX_START_DAYS, 'expires_if_paid_today' => today()->addDays($days)->toDateString()];
    }
}
