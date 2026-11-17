<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\GiftService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * #192 on the website and the portal: «إهداء هذه الباقة» only on published plans offered as gifts; bought without an
 * account and paid only by the signed Paymob webhook (never the return page); the card at its secret link shows no
 * price or phone; the recipient signs in or signs up, picks a start date and redeems it once.
 */
class GiftSiteTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'test-hmac-secret';

    private function configurePaymob(Tenant $club): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 9911], 201)]);
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111, 222'],
        ]));
    }

    private function paidBy(PaymentIntent $intent, int $cents)
    {
        $obj = [
            'id' => 555001, 'pending' => false, 'amount_cents' => $cents, 'success' => true, 'is_auth' => false, 'is_capture' => false,
            'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true, 'integration_id' => 111,
            'has_parent_transaction' => false, 'order' => ['id' => 9911, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-10-28T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ];

        return $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, self::HMAC), ['type' => 'TRANSACTION', 'obj' => $obj]);
    }

    /** @return array<string, string> */
    private function buyer(array $overrides = []): array
    {
        return $overrides + ['giver_name' => 'Sara Giver', 'giver_phone' => '91234567', 'recipient_name' => 'Noor', 'message' => 'Happy birthday!', 'accept_terms' => '1'];
    }

    private function paidGift(Tenant $club): GiftMembership
    {
        $plan = $this->plan($club, ['price' => 30]);

        return $this->asTenant($club, function () use ($plan) {
            $gift = app(GiftService::class)->issue($plan, ['giver_name' => 'Sara Giver', 'giver_phone' => '96891234567', 'recipient_name' => 'Noor', 'message' => 'Enjoy'], 'site');
            app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash');

            return $gift->fresh();
        });
    }

    private function token(Tenant $club, GiftMembership $gift): string
    {
        return $this->asTenant($club, fn () => $gift->fresh()->linkToken());
    }

    public function test_only_published_plans_offered_as_gifts_have_the_gift_option_and_page(): void
    {
        $club = $this->club();
        $open = $this->plan($club, ['name' => 'Open']);
        $closed = $this->plan($club, ['name' => 'NoGift', 'giftable' => false]);
        $hidden = $this->plan($club, ['name' => 'Hidden', 'is_public' => false]);
        $inactive = $this->plan($club, ['name' => 'Off', 'is_active' => false]);

        $this->get(route('site.plans', ['club' => $club->slug]))->assertOk()->assertInertia(fn ($page) => $page
            ->where('plans', fn ($plans) => collect($plans)->firstWhere('id', $open->id)['gift_url'] !== null
                && collect($plans)->firstWhere('id', $closed->id)['gift_url'] === null
                && collect($plans)->pluck('id')->doesntContain($hidden->id)));

        $this->get(route('site.gift.create', ['club' => $club->slug, 'plan' => $open->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Site/GiftBuy', false)->where('summary.total', '25.000')->where('onlinePayments', false));
        foreach ([$closed, $hidden, $inactive] as $plan) {
            $this->get(route('site.gift.create', ['club' => $club->slug, 'plan' => $plan->id]))->assertNotFound();
            $this->post(route('site.gift.store', ['club' => $club->slug, 'plan' => $plan->id]), $this->buyer())->assertNotFound();
        }
        $this->asTenant($club, fn () => $this->assertSame(0, GiftMembership::count()));
    }

    public function test_without_online_payment_nothing_is_bought_from_the_site(): void
    {
        $club = $this->club();
        $plan = $this->plan($club);

        $this->post(route('site.gift.store', ['club' => $club->slug, 'plan' => $plan->id]), $this->buyer())->assertSessionHasErrors('error');
        $this->post(route('site.gift.store', ['club' => $club->slug, 'plan' => $plan->id]), $this->buyer(['website' => 'bot']))->assertSessionHasErrors('website');
        $this->asTenant($club, fn () => $this->assertSame(0, GiftMembership::count() + Invoice::count()));
    }

    public function test_a_gift_bought_online_is_usable_only_after_the_signed_webhook_never_from_the_return_page(): void
    {
        $club = $this->club();
        $this->configurePaymob($club);
        $plan = $this->plan($club, ['price' => 30]);

        $this->post(route('site.gift.store', ['club' => $club->slug, 'plan' => $plan->id]), $this->buyer())->assertRedirectContains('unifiedcheckout');

        [$gift, $intent] = $this->asTenant($club, fn () => [GiftMembership::sole(), PaymentIntent::sole()]);
        $this->assertSame(GiftMembership::PENDING, $gift->status);
        $this->assertSame('site', $gift->source);
        $this->asTenant($club, fn () => $this->assertNull($gift->invoice->billable_id, 'No member account for the giver.'));
        $this->asTenant($club, fn () => $this->assertSame(0, Member::count()));

        // Back from the gateway: the return page only reads, and points at the gift's card.
        $this->get(route('site.pay.return', ['club' => $club->slug, 'reference' => $intent->reference]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('gift', $this->token($club, $gift)));
        $this->asTenant($club, fn () => $this->assertSame(GiftMembership::PENDING, $gift->fresh()->status));

        // The buyer (this browser) sees «أكملي الدفع»; anyone else with the link does not.
        $this->get(route('site.gift.show', ['club' => $club->slug, 'token' => $this->token($club, $gift)]))
            ->assertInertia(fn ($page) => $page->component('Site/GiftCard', false)->where('gift.status', 'pending')->where('is_buyer', true)
                ->where('pay_url', fn ($url) => $url !== null)->where('claim_url', null));
        $this->flushSession();
        $this->get(route('site.gift.show', ['club' => $club->slug, 'token' => $this->token($club, $gift)]))
            ->assertInertia(fn ($page) => $page->where('is_buyer', false)->where('pay_url', null));

        $this->paidBy($intent, 30000)->assertOk()->assertJson(['outcome' => 'processed']);
        $this->asTenant($club, function () use ($gift) {
            $this->assertSame(GiftMembership::PAID, $gift->fresh()->status);
            $this->assertSame(Invoice::PAID, $gift->invoice->fresh()->status);
        });
    }

    public function test_the_public_card_shows_the_gift_but_no_price_invoice_or_phone(): void
    {
        $club = $this->club();
        $gift = $this->paidGift($club);
        $token = $this->token($club, $gift);

        $html = $this->get(route('site.gift.show', ['club' => $club->slug, 'token' => $token]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('gift.recipient_name', 'Noor')->where('gift.giver_name', 'Sara Giver')
                ->where('gift.status', 'paid')->where('claim_url', fn ($url) => str_contains($url, '/claim'))
                ->where('whatsapp', fn ($url) => str_starts_with($url, 'https://wa.me/?text=')))
            ->getContent();
        foreach (['91234567', '30.000', 'INV-'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, $secret.' is on the public card.');
        }

        $this->get('/c/'.$club->slug.'/gift/'.str_repeat('a', 48))->assertNotFound();
        $other = $this->club('Other');
        $this->get(route('site.gift.show', ['club' => $other->slug, 'token' => $token]))->assertNotFound();
    }

    public function test_the_card_reads_in_english_on_the_english_site(): void
    {
        $club = $this->club();
        $plan = $this->plan($club, ['name' => 'شهري', 'name_en' => 'Monthly', 'duration_value' => 3, 'duration_unit' => 'month']);
        $gift = $this->asTenant($club, function () use ($plan) {
            $gift = app(GiftService::class)->issue($plan, ['giver_name' => 'Sara', 'recipient_name' => 'Noor'], 'staff');
            app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash');

            return $gift->fresh();
        });

        $this->get('/c/'.$club->slug.'/en/gift/'.$this->token($club, $gift))->assertOk()
            ->assertInertia(fn ($page) => $page->where('locale', 'en')->where('gift.plan_name', 'Monthly')->where('gift.duration_label', '3 months'));
    }

    public function test_a_new_recipient_signs_up_picks_a_start_date_and_redeems_once(): void
    {
        $club = $this->club();
        $gift = $this->paidGift($club);
        $token = $this->token($club, $gift);
        $start = today()->addDays(7)->toDateString();

        $this->get(route('site.gift.claim', ['club' => $club->slug, 'token' => $token]))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Site/GiftClaim', false));

        $this->post(route('site.gift.register', ['club' => $club->slug, 'token' => $token]), [
            'full_name' => 'Noor Recipient', 'phone' => '95556666', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1', 'accept_terms' => '1',
        ])->assertRedirect(route('portal.gift.redeem', ['club' => $club->slug, 'token' => $token]));
        $member = $this->asTenant($club, fn () => Member::where('full_name', 'Noor Recipient')->sole());
        $this->assertAuthenticatedAs($member, 'member');

        $this->get(route('portal.gift.redeem', ['club' => $club->slug, 'token' => $token]))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Portal/GiftRedeem', false)->where('suggested', today()->toDateString()));
        $this->post(route('portal.gift.redeem.store', ['club' => $club->slug, 'token' => $token]), ['starts_on' => $start])
            ->assertRedirect(route('portal.dashboard', ['club' => $club->slug]));

        $this->asTenant($club, function () use ($gift, $member, $start) {
            $gift = $gift->fresh();
            $this->assertSame(GiftMembership::REDEEMED, $gift->status);
            $this->assertSame('link', $gift->redeemed_via);
            $this->assertNull($gift->redeemed_by);
            $this->assertSame($member->id, $gift->member_id);
            $this->assertSame(Membership::SCHEDULED, $gift->membership->status);
            $this->assertSame($start, $gift->membership->starts_on->toDateString());
            $this->assertSame(1, Payment::count(), 'The recipient paid nothing.');
        });

        $this->post(route('portal.gift.redeem.store', ['club' => $club->slug, 'token' => $token]), ['starts_on' => $start])
            ->assertSessionHasErrors(['error' => 'استُخدمت هذه الهدية من قبل.']);
        $this->get(route('portal.gift.redeem', ['club' => $club->slug, 'token' => $token]))
            ->assertRedirect(route('site.gift.show', ['club' => $club->slug, 'token' => $token]));
        $this->get(route('site.gift.show', ['club' => $club->slug, 'token' => $token]))
            ->assertInertia(fn ($page) => $page->where('gift.status', 'redeemed')->where('claim_url', null)->where('whatsapp', null));
        $this->asTenant($club, fn () => $this->assertSame(1, Membership::count()));
    }

    public function test_an_existing_member_signs_in_and_comes_back_to_redeem_and_overlap_is_refused(): void
    {
        $club = $this->club();
        $member = $this->member($club, ['phone' => '97778888', 'password' => 'secret-pass-1']);
        $membership = $this->activeMembership($club, $member);
        $gift = $this->paidGift($club);
        $token = $this->token($club, $gift);

        $this->get(route('site.gift.claim', ['club' => $club->slug, 'token' => $token]))->assertOk();
        $this->post(route('portal.login.store', ['club' => $club->slug]), ['login' => '97778888', 'password' => 'secret-pass-1'])
            ->assertRedirect(route('portal.gift.redeem', ['club' => $club->slug, 'token' => $token]));

        $after = $membership->ends_on->addDay()->toDateString();
        $this->get(route('portal.gift.redeem', ['club' => $club->slug, 'token' => $token]))
            ->assertInertia(fn ($page) => $page->where('suggested', $after)->where('covered_until', $membership->ends_on->toDateString()));
        $this->get(route('site.gift.claim', ['club' => $club->slug, 'token' => $token]))
            ->assertRedirect(route('portal.gift.redeem', ['club' => $club->slug, 'token' => $token]));

        $this->post(route('portal.gift.redeem.store', ['club' => $club->slug, 'token' => $token]), ['starts_on' => today()->toDateString()])
            ->assertSessionHasErrors('starts_on');
        $this->asTenant($club, fn () => $this->assertSame(GiftMembership::PAID, $gift->fresh()->status));

        $this->post(route('portal.gift.redeem.store', ['club' => $club->slug, 'token' => $token]), ['starts_on' => $after])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(GiftMembership::REDEEMED, $gift->fresh()->status));
    }

    public function test_redeeming_needs_a_signed_in_member_and_a_usable_gift(): void
    {
        $club = $this->club();
        $gift = $this->paidGift($club);
        $token = $this->token($club, $gift);

        $this->get(route('portal.gift.redeem', ['club' => $club->slug, 'token' => $token]))->assertRedirect(route('portal.login', ['club' => $club->slug]));
        $this->post(route('portal.gift.redeem.store', ['club' => $club->slug, 'token' => $token]), ['starts_on' => today()->toDateString()])
            ->assertRedirect(route('portal.login', ['club' => $club->slug]));

        $this->asTenant($club, fn () => app(GiftService::class)->cancel($gift->fresh(), 'test'));
        $this->get(route('site.gift.claim', ['club' => $club->slug, 'token' => $token]))->assertRedirect(route('site.gift.show', ['club' => $club->slug, 'token' => $token]));
        $this->actingAs($this->member($club), 'member');
        $this->post(route('portal.gift.redeem.store', ['club' => $club->slug, 'token' => $token]), ['starts_on' => today()->toDateString()])
            ->assertSessionHasErrors(['error' => 'هذه الهدية ملغاة.']);
        $this->asTenant($club, fn () => $this->assertSame(0, Membership::count()));
    }

    public function test_money_arriving_for_a_gift_cancelled_meanwhile_is_held_not_applied(): void
    {
        $club = $this->club();
        $this->configurePaymob($club);
        $plan = $this->plan($club, ['price' => 30]);
        $this->post(route('site.gift.store', ['club' => $club->slug, 'plan' => $plan->id]), $this->buyer());
        [$gift, $intent] = $this->asTenant($club, fn () => [GiftMembership::sole(), PaymentIntent::sole()]);
        // A desk payment on part of it keeps its invoice from being voided by the cancellation.
        $this->asTenant($club, function () use ($gift) {
            app(PaymentService::class)->record($gift->invoice, '5.000', 'cash');
            app(GiftService::class)->cancel($gift->fresh(), 'Changed mind');
        });

        $this->paidBy($intent, 30000)->assertOk();

        $this->asTenant($club, function () use ($gift) {
            $this->assertSame(GiftMembership::CANCELLED, $gift->fresh()->status);
            $held = Payment::where('gateway', 'paymob')->sole();
            $this->assertSame('30.000', $held->credit_amount, 'Nothing applied to the cancelled gift.');
            $this->assertSame('gift_not_pending', AuditLog::where('event', 'payment.gateway_held')->sole()->properties['reason']);
            $this->assertNotSame(Invoice::PAID, $gift->invoice->fresh()->status);
        });
    }
}
