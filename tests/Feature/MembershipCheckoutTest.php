<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MemberNote;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipCheckout;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A membership bought online is sold after it is paid, not before (owner's decision, 2026-11-24): until Paymob confirms
 * the money there is no membership, no invoice, nothing owed and no message — only the member's account.
 */
class MembershipCheckoutTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'test-hmac-secret';

    private function onlineClub(): Tenant
    {
        Http::fake([
            '*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 9911], 201),
            '*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200),
        ]);
        $club = $this->club();
        $this->asTenant($club, function () {
            TenantIntegration::create([
                'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
                'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
            ]);
            TenantIntegration::create([
                'provider' => 'whatsapp', 'is_enabled' => true, 'public_identifier' => 'PNID-1',
                'credentials' => ['phone_number_id' => 'PNID-1', 'access_token' => 'EAAG-test'],
            ]);
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => 'approved']);
        });

        return $club;
    }

    /** @param array<string, mixed> $overrides */
    private function signUp(Tenant $club, int $planId, array $overrides = [])
    {
        return $this->post(route('site.join.store', ['club' => $club->slug]), $overrides + [
            'membership_plan_id' => $planId, 'full_name' => 'نورة الكندية', 'phone' => '9123 4567',
            'password' => 'secret-pass', 'password_confirmation' => 'secret-pass', 'accept_terms' => true, 'pay_now' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function confirm(PaymentIntent $intent, array $overrides = [])
    {
        $obj = array_replace_recursive([
            'id' => 555001, 'pending' => false, 'amount_cents' => (int) round((float) $intent->amount * 1000), 'success' => true, 'is_auth' => false,
            'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true,
            'integration_id' => 111, 'has_parent_transaction' => false, 'order' => ['id' => 9911, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-24T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ], $overrides);

        return $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, self::HMAC), ['type' => 'TRANSACTION', 'obj' => $obj]);
    }

    private function intent(Tenant $club): PaymentIntent
    {
        return $this->asTenant($club, fn () => PaymentIntent::latest('id')->firstOrFail());
    }

    public function test_signing_up_to_pay_online_sells_nothing_and_sends_nothing_before_the_payment(): void
    {
        $club = $this->onlineClub();
        $plan = $this->plan($club, ['price' => 25]);

        $response = $this->signUp($club, $plan->id);
        $this->assertStringContainsString('unifiedcheckout', (string) $response->headers->get('X-Inertia-Location', $response->headers->get('Location')));
        $this->assertAuthenticated('member');

        $this->asTenant($club, function () {
            $member = Member::where('phone', '96891234567')->firstOrFail();
            $this->assertSame(0, Membership::count(), 'No membership before the payment.');
            $this->assertSame(0, Invoice::count(), 'No invoice in any list, total or report before the payment.');
            $this->assertSame(0, Invoice::drafts()->count(), 'The checkout is not one of the editor\'s drafts.');
            $this->assertSame([], Message::where('member_id', $member->id)->whereNotIn('template_key', ['member_welcome'])->pluck('template_key')->all());
            $checkout = MembershipCheckout::firstOrFail();
            $this->assertSame(MembershipCheckout::OPEN, $checkout->status);
            $this->assertSame('25.000', PaymentIntent::firstOrFail()->amount);
        });
        $this->actingAs($this->owner($club))->get(route('club.invoices.index'))->assertInertia(fn ($p) => $p->where('invoices.total', 0));
    }

    public function test_the_confirmed_payment_makes_the_sale_activates_it_and_announces_only_the_payment(): void
    {
        $club = $this->onlineClub();
        $plan = $this->plan($club, ['price' => 25]);
        $this->signUp($club, $plan->id);
        $intent = $this->intent($club);

        $this->confirm($intent)->assertOk()->assertJson(['outcome' => 'processed']);
        $this->confirm($intent)->assertOk()->assertJson(['outcome' => 'duplicate']);

        $this->asTenant($club, function () use ($intent) {
            $membership = Membership::sole();
            $invoice = Invoice::sole();
            $this->assertSame(Membership::ACTIVE, $membership->status);
            $this->assertSame($invoice->id, $membership->invoice_id);
            $this->assertSame(Invoice::PAID, $invoice->status);
            $this->assertNotNull($invoice->number);
            $this->assertSame(1, Payment::where('gateway', 'paymob')->count());
            $this->assertSame($invoice->id, $intent->fresh()->invoice_id);
            $this->assertSame(PaymentIntent::PAID, $intent->fresh()->status);
            $this->assertSame(0, Invoice::withoutGlobalScope('issued')->where('status', Invoice::DRAFT)->count(), 'The checkout\'s draft is gone.');
            $checkout = MembershipCheckout::sole();
            $this->assertSame(MembershipCheckout::COMPLETED, $checkout->status);
            $this->assertSame($membership->id, $checkout->membership_id);

            $keys = Message::where('member_id', $membership->member_id)->pluck('template_key')->all();
            $this->assertContains('payment_received', $keys);
            $this->assertContains('membership_activated', $keys);
            $this->assertSame([], array_values(array_intersect($keys, ['invoice_issued', 'invoice_issued_membership', 'invoice_issued_membership_en'])));
        });
    }

    public function test_a_declined_payment_sells_nothing_and_she_can_pay_again_from_her_account(): void
    {
        $club = $this->onlineClub();
        $plan = $this->plan($club, ['price' => 25]);
        $this->signUp($club, $plan->id);
        $intent = $this->intent($club);

        $this->confirm($intent, ['success' => false])->assertOk()->assertJson(['outcome' => 'failed_payment']);

        $this->asTenant($club, function () {
            $this->assertSame(0, Membership::count());
            $this->assertSame(0, Invoice::count());
            $this->assertSame(0, Message::whereIn('template_key', ['payment_failed', 'invoice_issued_membership'])->count());
        });
        $this->get(route('site.pay.return', ['club' => $club->slug, 'reference' => $intent->reference]))
            ->assertInertia(fn ($p) => $p->where('status', 'failed')->where('retry_url', fn ($u) => str_ends_with((string) $u, '/portal'))->where('invoice.token', null));
        $this->get(route('portal.dashboard', ['club' => $club->slug]))
            ->assertInertia(fn ($p) => $p->where('checkout.amount', '25.000')->where('openInvoices', []));
        $again = $this->post(route('portal.checkout.pay', ['club' => $club->slug]));
        $this->assertStringContainsString('unifiedcheckout', (string) ($again->headers->get('X-Inertia-Location') ?? $again->headers->get('Location')));
    }

    public function test_paying_at_the_desk_creates_only_her_account_and_a_note_for_reception(): void
    {
        $club = $this->onlineClub();
        $plan = $this->plan($club, ['price' => 25, 'name' => 'الشهرية']);

        $this->signUp($club, $plan->id, ['pay_now' => false])->assertRedirect(route('portal.dashboard', ['club' => $club->slug]));

        $this->asTenant($club, function () {
            $member = Member::where('phone', '96891234567')->firstOrFail();
            $this->assertSame(0, Membership::count());
            $this->assertSame(0, Invoice::count());
            $this->assertSame(0, MembershipCheckout::count());
            $this->assertStringContainsString('الشهرية', MemberNote::where('member_id', $member->id)->sole()->body);
        });
        // A code the desk would refuse is refused now, and nothing is kept.
        $this->post(route('portal.logout', ['club' => $club->slug]));
        $this->signUp($club, $plan->id, ['pay_now' => false, 'phone' => '9222 3333', 'coupon_code' => 'NOPE'])->assertSessionHasErrors('coupon_code');
        $this->asTenant($club, fn () => $this->assertFalse(Member::where('phone', '96892223333')->exists()));
    }

    public function test_a_renewal_from_the_portal_is_sold_when_it_is_paid_and_starts_after_the_current_period(): void
    {
        $club = $this->onlineClub();
        $member = $this->member($club, ['password' => 'secret123']);
        $plan = $this->plan($club, ['price' => 25]);
        $current = $this->activeMembership($club, $member, $plan);
        $this->actingAs($member, 'member');

        $this->post(route('portal.renew.store', ['club' => $club->slug]), ['membership_plan_id' => $plan->id]);
        // The same choice again (a double press) opens the same page; nothing waits in the books meanwhile.
        $this->post(route('portal.renew.store', ['club' => $club->slug]), ['membership_plan_id' => $plan->id])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($member) {
            $this->assertSame(0, Membership::where('member_id', $member->id)->where('status', Membership::PENDING)->count());
            $this->assertSame(1, MembershipCheckout::where('status', MembershipCheckout::OPEN)->count());
            $this->assertSame(1, PaymentIntent::count());
        });
        // Another plan while that page may still take money: refused, or she could pay both.
        $other = $this->plan($club, ['price' => 40]);
        $this->post(route('portal.renew.store', ['club' => $club->slug]), ['membership_plan_id' => $other->id])->assertSessionHasErrors('membership_plan_id');

        $this->confirm($this->intent($club))->assertJson(['outcome' => 'processed']);
        $this->asTenant($club, function () use ($current) {
            $renewal = Membership::where('kind', Membership::KIND_RENEWAL)->sole();
            $this->assertSame(Membership::SCHEDULED, $renewal->status);
            $this->assertSame($current->ends_on->addDay()->toDateString(), $renewal->starts_on->toDateString());
        });
    }

    public function test_money_for_a_plan_withdrawn_meanwhile_gets_an_invoice_and_is_held_for_review(): void
    {
        $club = $this->onlineClub();
        $plan = $this->plan($club, ['price' => 25]);
        $this->signUp($club, $plan->id);
        $this->asTenant($club, fn () => $plan->forceFill(['is_active' => false])->save());

        $this->confirm($this->intent($club))->assertOk()->assertJson(['outcome' => 'held_for_review']);

        $this->asTenant($club, function () {
            $this->assertSame(0, Membership::count(), 'Nothing is activated that can no longer be sold.');
            $invoice = Invoice::sole();
            $this->assertNotNull($invoice->number);
            $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
            $this->assertSame('checkout_unavailable', MembershipCheckout::sole()->problem);
        });
    }

    public function test_renewing_without_online_payment_sells_nothing(): void
    {
        $club = $this->club();
        $member = $this->member($club, ['password' => 'secret123']);
        $plan = $this->plan($club);
        $this->activeMembership($club, $member, $plan);

        $this->actingAs($member, 'member')->post(route('portal.renew.store', ['club' => $club->slug]), ['membership_plan_id' => $plan->id])
            ->assertSessionHasErrors('membership_plan_id');
        $this->assertSame(0, $this->asTenant($club, fn () => Membership::where('status', Membership::PENDING)->count()));
    }
}
