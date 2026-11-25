<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipCheckout;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A membership or a personal-training package is sold only when it is paid (owner's decision, 2026-11-25): no invoice
 * before the money and no «بانتظار الدفع» — at the desk the sale and the whole payment are one step; from afar, a
 * payment link sells it once Paymob confirms the money.
 */
class PaidSalesTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'test-hmac-secret';

    private function online(Tenant $club): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 9911], 201), '*' => Http::response([], 200)]);
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));
    }

    public function test_a_desk_sale_is_paid_issued_and_active_in_one_step(): void
    {
        $club = $this->club();
        $this->fundAccounts($club);
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        $this->actingAs($this->owner($club))->post(route('club.memberships.store', $member), [
            'membership_plan_id' => $plan->id, 'mode' => 'new', 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->asTenant($club, function () {
            $membership = Membership::sole();
            $this->assertSame(Membership::ACTIVE, $membership->status);
            $this->assertSame(Invoice::PAID, Invoice::sole()->status);
            $this->assertSame('25.000', Payment::sole()->amount);
        });
    }

    public function test_without_a_payment_method_nothing_is_sold(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club);

        $this->actingAs($this->owner($club))->post(route('club.memberships.store', $member), ['membership_plan_id' => $plan->id, 'mode' => 'new'])
            ->assertSessionHasErrors('payment_method');
        $this->actingAs($this->owner($club))->post(route('club.memberships.store', $member), ['membership_plan_id' => $plan->id, 'mode' => 'new', 'payment_method' => 'card'])
            ->assertSessionHasErrors('payment_reference');
        $this->asTenant($club, fn () => $this->assertSame([0, 0], [Membership::count(), Invoice::count()]));
    }

    public function test_a_transfer_is_a_sale_only_for_who_may_confirm_transfers(): void
    {
        $club = $this->club();
        $this->fundAccounts($club);
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        $this->actingAs($this->staff($club, 'reception'))->post(route('club.memberships.store', $member), ['membership_plan_id' => $plan->id, 'mode' => 'new', 'payment_method' => 'bank_transfer'])
            ->assertSessionHasErrors('method');
        $this->asTenant($club, fn () => $this->assertSame(0, Membership::count()));

        $this->actingAs($this->owner($club))->post(route('club.memberships.store', $member), ['membership_plan_id' => $plan->id, 'mode' => 'new', 'payment_method' => 'bank_transfer', 'payment_reference' => 'TRX-1'])
            ->assertSessionHasNoErrors();
        $this->asTenant($club, function () {
            $this->assertSame(Membership::ACTIVE, Membership::sole()->status);
            $this->assertSame(Payment::COMPLETED, Payment::sole()->status);
        });
    }

    public function test_a_personal_training_package_is_sold_paid_and_active(): void
    {
        $club = $this->club();
        $this->fundAccounts($club);
        $member = $this->member($club);
        $package = $this->asTenant($club, fn () => PtPackage::create(['name' => 'PT 8', 'sessions_count' => 8, 'session_minutes' => 60, 'price' => 80, 'validity_days' => 60, 'is_active' => true]));

        $this->actingAs($this->owner($club))->post(route('club.pt.store', $member), ['pt_package_id' => $package->id])->assertSessionHasErrors('payment_method');
        $this->actingAs($this->owner($club))->post(route('club.pt.store', $member), ['pt_package_id' => $package->id, 'payment_method' => 'cash'])->assertSessionHasNoErrors();

        $this->asTenant($club, function () {
            $this->assertNotSame(PtSubscription::PENDING, PtSubscription::sole()->status);
            $this->assertSame(Invoice::PAID, Invoice::sole()->status);
        });
    }

    public function test_a_payment_link_sells_nothing_until_paid_then_sells_it_paid(): void
    {
        $club = $this->club();
        $this->online($club);
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        $this->actingAs($this->owner($club))->post(route('club.memberships.store', $member), ['membership_plan_id' => $plan->id, 'mode' => 'new', 'payment_method' => 'link', 'discount' => 5])
            ->assertSessionHasNoErrors()->assertRedirect(route('club.members.show', $member));
        $checkout = $this->asTenant($club, fn () => MembershipCheckout::sole());
        $this->assertSame('link', $checkout->channel);
        $this->asTenant($club, fn () => $this->assertSame([0, 0], [Membership::count(), Invoice::count()]));

        // Her file shows the link to send; the page she opens shows what she pays.
        $this->actingAs($this->owner($club))->get(route('club.members.show', $member))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('paymentLink.amount', '20.000')->where('paymentLink.url', fn ($url) => str_contains((string) $url, '/pay/checkout/')));
        auth()->logout();
        $this->get(route('site.checkout.show', ['club' => $club->slug, 'token' => $checkout->token]))
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Site/CheckoutPay')->where('state', 'open')->where('total', '20.000'));
        $this->post(route('site.checkout.start', ['club' => $club->slug, 'token' => $checkout->token]));

        $intent = $this->asTenant($club, fn () => PaymentIntent::sole());
        $obj = ['id' => 777, 'pending' => false, 'amount_cents' => 20000, 'success' => true, 'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true,
            'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true, 'integration_id' => 111, 'has_parent_transaction' => false,
            'order' => ['id' => 9911, 'merchant_order_id' => $intent->reference], 'created_at' => '2026-11-25T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card']];
        $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, self::HMAC), ['type' => 'TRANSACTION', 'obj' => $obj])->assertJson(['outcome' => 'processed']);

        $this->asTenant($club, function () {
            $this->assertSame(Membership::ACTIVE, Membership::sole()->status);
            $this->assertSame('5.000', Membership::sole()->discount);
            $this->assertSame(Invoice::PAID, Invoice::sole()->status);
        });
        $this->get(route('site.checkout.show', ['club' => $club->slug, 'token' => $checkout->token]))->assertInertia(fn (AssertableInertia $p) => $p->where('state', 'completed'));
    }

    public function test_a_withdrawn_link_opens_no_payment(): void
    {
        $club = $this->club();
        $this->online($club);
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);
        $this->actingAs($this->owner($club))->post(route('club.memberships.store', $member), ['membership_plan_id' => $plan->id, 'mode' => 'new', 'payment_method' => 'link']);
        $checkout = $this->asTenant($club, fn () => MembershipCheckout::sole());

        $this->actingAs($this->owner($club))->post(route('club.memberships.link.cancel', $checkout))->assertSessionHas('success');
        auth()->logout();
        $this->get(route('site.checkout.show', ['club' => $club->slug, 'token' => $checkout->token]))->assertInertia(fn (AssertableInertia $p) => $p->where('state', 'abandoned'));
        $this->post(route('site.checkout.start', ['club' => $club->slug, 'token' => $checkout->token]))->assertRedirect();
        $this->asTenant($club, fn () => $this->assertSame(0, PaymentIntent::count()));
    }
}
