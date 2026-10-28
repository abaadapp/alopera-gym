<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Spec 6.1: pay from the website/portal/WhatsApp link, confirmation only via
 * a signed webhook, linked to the invoice and membership, never twice, and
 * never from the browser's return page.
 */
class PaymobPaymentTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'test-hmac-secret';

    private function configurePaymob(Tenant $club): void
    {
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111, 222'],
        ]));
    }

    /** @return array{0: Membership, 1: PaymentIntent} */
    private function pendingWithIntent(Tenant $club): array
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 9911], 201)]);
        $this->configurePaymob($club);
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        return $this->asTenant($club, function () use ($member, $plan) {
            $membership = app(MembershipService::class)->sell($member, $plan);
            $intent = app(OnlinePaymentService::class)->start($membership->invoice, 'portal');

            return [$membership, $intent];
        });
    }

    /** @return array<string, mixed> */
    private function paymobCallback(PaymentIntent $intent, array $overrides = []): array
    {
        $obj = array_replace_recursive([
            'id' => 555001, 'pending' => false, 'amount_cents' => 25000, 'success' => true, 'is_auth' => false, 'is_capture' => false,
            'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true, 'integration_id' => 111,
            'has_parent_transaction' => false, 'order' => ['id' => 9911, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-10-28T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ], $overrides);

        return ['type' => 'TRANSACTION', 'obj' => $obj];
    }

    private function postWebhook(array $payload, ?string $hmac = null)
    {
        $hmac ??= PaymobSignature::compute($payload['obj'], self::HMAC);

        return $this->postJson(route('webhooks.paymob').'?hmac='.$hmac, $payload);
    }

    public function test_checkout_is_opened_with_the_clubs_keys_and_exact_amount(): void
    {
        [, $intent] = $this->pendingWithIntent($this->club());

        $this->assertStringContainsString('unifiedcheckout/?publicKey=pk_test&clientSecret=cs_abc', $intent->checkout_url);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Token sk_test')
            && $request['amount'] === 25000 && $request['currency'] === 'OMR'
            && $request['payment_methods'] === [111, 222]
            && $request['special_reference'] === $intent->reference);
    }

    public function test_online_payment_is_unavailable_without_complete_credentials(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $membership = $this->asTenant($club, fn () => app(MembershipService::class)->sell($member, $this->plan($club)));

        $this->expectException(BusinessRuleException::class);
        $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start($membership->invoice));
    }

    public function test_a_callback_with_a_bad_signature_changes_nothing(): void
    {
        $club = $this->club();
        [$membership, $intent] = $this->pendingWithIntent($club);

        $this->postWebhook($this->paymobCallback($intent), str_repeat('0', 128))->assertStatus(401);

        $this->asTenant($club, function () use ($membership) {
            $this->assertSame(Membership::PENDING, $membership->fresh()->status);
            $this->assertSame(0, Payment::count());
        });
        $this->assertSame('rejected_signature', PaymentWebhookEvent::latest('id')->first()->outcome);
    }

    public function test_a_verified_callback_pays_the_invoice_and_activates_the_membership_once(): void
    {
        $club = $this->club();
        [$membership, $intent] = $this->pendingWithIntent($club);
        $payload = $this->paymobCallback($intent);

        $this->postWebhook($payload)->assertOk()->assertJson(['outcome' => 'processed']);
        $this->postWebhook($payload)->assertOk()->assertJson(['outcome' => 'duplicate']);

        $this->asTenant($club, function () use ($membership, $intent) {
            $this->assertSame(Membership::ACTIVE, $membership->fresh()->status);
            $this->assertSame(Invoice::PAID, $membership->invoice->fresh()->status);
            $this->assertSame(1, Payment::where('gateway', 'paymob')->count());
            $this->assertSame(PaymentIntent::PAID, $intent->fresh()->status);
            $this->assertSame('555001', Payment::first()->gateway_transaction_id);
        });
    }

    public function test_amount_or_currency_mismatch_is_not_recorded(): void
    {
        $club = $this->club();
        [$membership, $intent] = $this->pendingWithIntent($club);

        $this->postWebhook($this->paymobCallback($intent, ['amount_cents' => 100]))->assertOk()->assertJson(['outcome' => 'amount_mismatch']);
        $this->postWebhook($this->paymobCallback($intent, ['id' => 555002, 'currency' => 'EGP']))->assertOk()->assertJson(['outcome' => 'amount_mismatch']);

        $this->asTenant($club, fn () => $this->assertSame(Membership::PENDING, $membership->fresh()->status));
    }

    public function test_a_declined_payment_marks_the_attempt_failed_and_keeps_the_membership_pending(): void
    {
        $club = $this->club();
        [$membership, $intent] = $this->pendingWithIntent($club);

        $this->postWebhook($this->paymobCallback($intent, ['success' => false]))->assertOk()->assertJson(['outcome' => 'failed_payment']);

        $this->asTenant($club, function () use ($membership, $intent) {
            $this->assertSame(PaymentIntent::FAILED, $intent->fresh()->status);
            $this->assertSame(Membership::PENDING, $membership->fresh()->status);
        });
    }

    public function test_the_return_page_never_activates_anything(): void
    {
        $club = $this->club();
        [$membership, $intent] = $this->pendingWithIntent($club);

        // A forged "success" redirect from the browser
        $this->get(route('site.pay.return', ['club' => $club->slug, 'reference' => $intent->reference]).'?success=true&txn_response_code=APPROVED')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Site/PaymentReturn')->where('status', 'pending'));

        $this->asTenant($club, fn () => $this->assertSame(Membership::PENDING, $membership->fresh()->status));
        $this->getJson(route('site.pay.status', ['club' => $club->slug, 'reference' => $intent->reference]))->assertJson(['status' => 'pending']);
    }

    public function test_unknown_references_are_rejected(): void
    {
        $this->postJson(route('webhooks.paymob').'?hmac=x', ['obj' => ['id' => 1, 'order' => ['merchant_order_id' => 'PI-NOPE']]])->assertNotFound();
    }

    public function test_a_club_secret_cannot_sign_another_clubs_payment(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        [$membership, $intent] = $this->pendingWithIntent($b);
        $this->asTenant($a, fn () => TenantIntegration::create(['provider' => 'paymob', 'is_enabled' => true, 'credentials' => ['hmac_secret' => 'club-a-secret']]));

        $payload = $this->paymobCallback($intent);
        $this->postWebhook($payload, PaymobSignature::compute($payload['obj'], 'club-a-secret'))->assertStatus(401);
        $this->asTenant($b, fn () => $this->assertSame(Membership::PENDING, $membership->fresh()->status));
    }

    public function test_whatsapp_pay_link_opens_the_checkout(): void
    {
        $club = $this->club();
        [$membership] = $this->pendingWithIntent($club);
        $token = $this->asTenant($club, fn () => $membership->invoice->public_token);

        $this->get(route('site.pay.show', ['club' => $club->slug, 'token' => $token]))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Site/Pay')->where('onlinePayments', true));
        $this->post(route('site.pay.start', ['club' => $club->slug, 'token' => $token]))
            ->assertRedirectContains('unifiedcheckout');
    }
}
