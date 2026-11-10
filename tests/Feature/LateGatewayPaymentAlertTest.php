<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Online money that its invoice no longer takes — Paymob confirming a website order after it was released — becomes
 * the customer's credit, and whoever may pay it back hears of it in the bell, once, until the credit is dealt with.
 */
class LateGatewayPaymentAlertTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function shop(Tenant $club): Product
    {
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'hmac-test', 'integration_ids' => '111'],
        ]));

        return $this->asTenant($club, function () {
            $product = Product::create(['name' => 'Protein bar', 'price' => 1.5, 'show_on_website' => true]);
            app(StockService::class)->move($product, 5, 'adjustment', null, 'opening', 0.8);

            return $product->fresh();
        });
    }

    /** @return array{0: Sale, 1: PaymentIntent} */
    private function onlineOrder(Tenant $club, Product $product): array
    {
        $this->post(route('site.shop.order.store', ['club' => $club->slug]), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'name' => 'Online', 'phone' => '91234567', 'payment_method' => 'paymob',
        ])->assertRedirectContains('clientSecret=');

        return $this->asTenant($club, fn () => [Sale::latest('id')->firstOrFail(), PaymentIntent::latest('id')->firstOrFail()]);
    }

    private function paymobConfirms(PaymentIntent $intent, int $transaction): void
    {
        $obj = ['id' => $transaction, 'pending' => false, 'amount_cents' => (int) round((float) $intent->amount * 1000), 'success' => true, 'is_voided' => false,
            'is_refunded' => false, 'error_occured' => false, 'currency' => 'OMR', 'order' => ['id' => 4500, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-10T10:00:00', 'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true, 'is_3d_secure' => true,
            'integration_id' => 111, 'has_parent_transaction' => false, 'owner' => 1, 'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card']];
        $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, 'hmac-test'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
    }

    /** @return array<int, int> user id => their credit alerts */
    private function creditAlerts(Tenant $club): array
    {
        return StaffNotification::withoutGlobalScopes()->where('tenant_id', $club->id)->where('key', 'like', 'payment-credit:%')
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id')->map(fn ($n) => (int) $n)->all();
    }

    public function test_a_payment_confirmed_after_the_order_was_cancelled_is_told_once_to_whoever_may_refund_it(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_late', 'intention_order_id' => 4500], 201)]);
        $club = $this->club();
        $bar = $this->shop($club);
        $owner = $this->owner($club);
        $finance = $this->staff($club, 'finance');
        $reception = $this->staff($club, 'reception'); // hands orders over, but does not pay money back
        $elsewhere = $this->staff($club, 'finance', ['branch_id' => $this->asTenant($club, fn () => Branch::create(['name' => 'فرع الخوض'])->id)]);

        [$sale, $intent] = $this->onlineOrder($club, $bar);
        $this->asTenant($club, fn () => $sale->forceFill(['reserved_until' => now()->subMinutes(OrderService::PAYMENT_GRACE_MINUTES + 1)])->save());
        Artisan::call('sales:expire-orders');
        $this->asTenant($club, fn () => $this->assertSame(Sale::CANCELLED, $sale->fresh()->status));
        $this->assertSame([], $this->creditAlerts($club), 'nothing to tell before the money arrives');

        $this->paymobConfirms($intent, 778001);
        $this->paymobConfirms($intent, 778001); // Paymob repeats its callback

        $payment = $this->asTenant($club, fn () => Payment::sole());
        $this->assertSame($intent->amount, $payment->credit_amount);
        $this->assertEqualsCanonicalizing([$owner->id => 1, $finance->id => 1], $this->creditAlerts($club));
        $this->assertArrayNotHasKey($reception->id, $this->creditAlerts($club));
        $this->assertArrayNotHasKey($elsewhere->id, $this->creditAlerts($club), 'a user tied to another branch');

        $alert = StaffNotification::withoutGlobalScopes()->where('user_id', $finance->id)->sole();
        $this->assertSame(['payment-credit:'.$payment->id, 'payment'], [$alert->key, $alert->kind]);
        $this->assertStringContainsString($sale->number, $alert->text);
        $this->assertStringContainsString('رصيد دائن', $alert->text);
        $this->assertSame(route('club.invoices.show', $payment->invoice_id), $alert->url);
        $this->actingAs($finance)->get($alert->url)->assertOk();

        // Paid back (a transfer waiting to be made counts): the alert is done for everyone.
        $this->actingAs($finance)->post(route('club.payments.refund-credit', $payment), ['amount' => $payment->credit_amount, 'method' => 'bank_transfer'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, StaffNotification::withoutGlobalScopes()->where('key', 'payment-credit:'.$payment->id)->whereNull('read_at')->count());
    }

    public function test_an_online_order_paid_in_time_raises_no_credit_alert(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_ok', 'intention_order_id' => 4500], 201)]);
        $club = $this->club();
        $bar = $this->shop($club);
        $this->staff($club, 'finance');

        [$sale, $intent] = $this->onlineOrder($club, $bar);
        $this->paymobConfirms($intent, 778002);

        $this->asTenant($club, function () use ($sale) {
            $this->assertNotNull($sale->fresh()->paid_at);
            $this->assertSame('0.000', Payment::sole()->credit_amount);
        });
        $this->assertSame([], $this->creditAlerts($club));
    }

    public function test_the_alert_is_written_with_the_payment_and_rolled_back_with_it(): void
    {
        $club = $this->club();
        $bar = $this->shop($club);
        $finance = $this->staff($club, 'finance');
        $sale = $this->asTenant($club, fn () => app(OrderService::class)->place([['product_id' => $bar->id, 'quantity' => 1]], ['name' => 'Ali', 'phone' => '96891234567'], 'pickup'));
        $this->asTenant($club, fn () => app(OrderService::class)->cancel($sale, 'العميل ألغى'));

        $record = fn () => app(PaymentService::class)->recordGatewayPayment($sale->invoice()->firstOrFail(), '1.500', 'paymob', 'tx-778003');
        // The gateway's money account exists already, as it does once a club took its first online payment.
        $this->asTenant($club, fn () => app(ChartOfAccounts::class)->moneyFor('paymob'));

        // The webhook's transaction fails after the payment was written: neither the money nor the alert stays.
        $this->asTenant($club, function () use ($record) {
            try {
                DB::transaction(function () use ($record) {
                    $record();
                    throw new RuntimeException('after the payment');
                });
            } catch (RuntimeException) {
            }
            $this->assertSame(0, Payment::count());
        });
        $this->assertSame([], $this->creditAlerts($club));

        $this->asTenant($club, $record);
        $this->assertSame([$finance->id => 1], array_intersect_key($this->creditAlerts($club), [$finance->id => true]));
    }
}
