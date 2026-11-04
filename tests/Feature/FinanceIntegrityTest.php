<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\CashShift;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Money goes back the way it came, a drawer is counted against everything that moved it, and the books keep what the screens say. */
class FinanceIntegrityTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function balance(Tenant $club, string $key): string
    {
        return $this->asTenant($club, fn () => app(FinanceReports::class)->balanceOf($key));
    }

    public function test_a_website_order_paid_through_paymob_is_refunded_through_paymob_not_out_of_the_cash_drawer(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 4411], 201)]);
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'hmac-test', 'integration_ids' => '111'],
        ]));
        $shake = $this->asTenant($club, function () {
            $p = Product::create(['name' => 'Shake', 'price' => 2.5, 'show_on_website' => true]);
            app(StockService::class)->move($p, 10, 'adjustment', null, 'opening', 1);

            return $p->fresh();
        });
        $this->post(route('site.shop.order.store', ['club' => $club->slug]), [
            'items' => [['product_id' => $shake->id, 'quantity' => 2]], 'name' => 'Online', 'phone' => '91234567', 'payment_method' => 'paymob', 'total' => 0.01,
        ])->assertRedirectContains('clientSecret=cs_abc');
        [$sale, $intent] = $this->asTenant($club, fn () => [Sale::firstOrFail(), PaymentIntent::firstOrFail()]);
        $obj = ['id' => 777002, 'pending' => false, 'amount_cents' => 5000, 'success' => true, 'is_voided' => false, 'is_refunded' => false, 'error_occured' => false,
            'currency' => 'OMR', 'order' => ['id' => 4411, 'merchant_order_id' => $intent->reference], 'created_at' => '2026-10-30T10:00:00',
            'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true, 'is_3d_secure' => true, 'integration_id' => 111,
            'has_parent_transaction' => false, 'owner' => 1, 'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card']];
        $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, 'hmac-test'), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
        $this->assertSame('5.000', $this->balance($club, 'paymob_clearing'));

        $this->actingAs($this->owner($club))->post(route('club.sales.orders.cancel', $sale), ['reason' => 'Out of stock', 'refund_method' => 'paymob'])->assertSessionHasNoErrors();
        $refund = $this->asTenant($club, fn () => Payment::where('kind', Payment::KIND_REFUND)->sole());
        $this->assertSame(['paymob', Payment::PENDING], [$refund->method, $refund->status], 'made in Paymob, then confirmed');

        $this->actingAs($this->owner($club))->post(route('club.payments.confirm', $refund), ['reference' => 'PM-REF-1'])->assertSessionHasNoErrors();
        $this->assertSame('0.000', $this->balance($club, 'paymob_clearing'));
        $this->assertSame('0.000', $this->balance($club, 'cash'));
    }

    public function test_salaries_paid_from_the_drawer_during_a_shift_lower_the_cash_it_should_hold(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $cash = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'));
        $this->actingAs($owner)->post(route('club.cash-shifts.store'), ['account_id' => $cash->id, 'opening_float' => 500])->assertSessionHasNoErrors();
        $shift = $this->asTenant($club, fn () => CashShift::sole());

        $this->asTenant($club, fn () => Employee::create(['name' => 'سارة', 'basic_salary' => '300.000', 'status' => 'active']));
        $this->actingAs($owner)->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $run = $this->asTenant($club, fn () => PayrollRun::sole());
        $this->actingAs($owner)->post(route('club.payroll.approve', $run))->assertSessionHasNoErrors();
        $item = $this->asTenant($club, fn () => $run->items()->sole());
        $this->actingAs($owner)->post(route('club.payroll.pay', $run), ['items' => [$item->id], 'account_id' => $cash->id, 'paid_on' => today()->toDateString(), 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        // The drawer really holds 200: counting 200 is no difference, and no shortage is posted.
        $this->actingAs($owner)->post(route('club.cash-shifts.close', $shift), ['counted_cash' => 200])->assertSessionHasNoErrors();
        $this->assertSame('0.000', $shift->fresh()->difference);
        $this->assertSame('0.000', $this->balance($club, 'cash_over_short'));
    }

    public function test_a_transfer_that_reached_the_bank_is_confirmed_even_after_the_invoice_was_paid_at_the_desk(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);
        $invoice = $this->asTenant($club, fn () => app(InvoiceService::class)->issue($member, [['description' => 'Line', 'quantity' => 1, 'unit_price' => 100]], ['type' => 'other']));
        $transfer = $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice, 100, 'bank_transfer'));
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice->fresh(), 100, 'cash'));

        $this->actingAs($owner)->post(route('club.payments.confirm', $transfer), ['reference' => 'TRX-1'])->assertSessionHasNoErrors();

        $transfer = $transfer->fresh();
        $this->assertSame(Payment::COMPLETED, $transfer->status);
        $this->assertSame('100.000', $transfer->credit_amount, 'the customer has it as credit, to apply or pay back');
        $this->assertSame('100.000', $this->balance($club, 'bank'));
        $this->assertSame('100.000', $this->balance($club, 'customer_credits'));
        $this->assertSame('0.000', $this->balance($club, 'receivable'));
    }

    public function test_an_account_holding_money_is_not_stopped_from_the_chart_of_accounts(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.finance.banks.store'), ['name' => 'Bank Muscat', 'kind' => 'bank', 'opening_balance' => 500, 'opening_date' => today()->toDateString()])->assertSessionHasNoErrors();
        $bank = $this->asTenant($club, fn () => Account::where('name', 'Bank Muscat')->sole());

        $this->actingAs($owner)->put(route('club.finance.accounts.update', $bank), ['name' => 'Bank Muscat', 'is_active' => false])->assertSessionHasErrors('is_active');
        $this->assertTrue($bank->fresh()->is_active);
    }

    public function test_an_opening_balance_set_again_after_zeroing_it_is_posted(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.finance.banks.store'), ['name' => 'Bank Muscat', 'kind' => 'bank', 'opening_balance' => 1000, 'opening_date' => today()->toDateString()])->assertSessionHasNoErrors();
        $bank = $this->asTenant($club, fn () => Account::where('name', 'Bank Muscat')->sole());

        $this->actingAs($owner)->post(route('club.finance.banks.opening', $bank), ['amount' => 0, 'date' => today()->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.finance.banks.opening', $bank), ['amount' => 800, 'date' => today()->toDateString()])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($bank) {
            $live = JournalEntry::where(['source_type' => 'account', 'source_id' => $bank->id, 'source_event' => 'opening'])->whereNull('reversed_at')->get();
            $this->assertCount(1, $live);
            $this->assertSame('800.000', $live->sole()->total);
            $this->assertSame('800.000', app(FinanceReports::class)->balance($bank));
        });
    }
}
