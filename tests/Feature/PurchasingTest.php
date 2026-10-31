<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Inventory\Models\SupplierPayment;
use App\Modules\Inventory\Services\PurchaseService;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Purchasing: orders in purchase units with discount, shipping and tax; approval; receipts in parts, approved once,
 * onto the receiving branch's shelf; supplier bills that reach the books once (never twice for one purchase);
 * payments made from a cash box or bank; reversals; permissions per step; isolation.
 */
class PurchasingTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function setup2(Tenant $club): array
    {
        return $this->asTenant($club, function () {
            $khoud = Branch::create(['name' => 'فرع الخوض', 'is_active' => true]);

            return [Supplier::create(['name' => 'Al Noor', 'payment_terms_days' => 30]), Product::create(['name' => 'Water', 'price' => 0.2]), $khoud];
        });
    }

    private function balance(Tenant $club, string $key): string
    {
        return $this->asTenant($club, fn () => app(FinanceReports::class)->balanceOf($key));
    }

    /** A draft order: 5 cartons of 24 bottles at 4.800 a carton, discount 1.000, shipping 2.000, VAT 5%. */
    private function order(Tenant $club, Supplier $supplier, Product $water, Branch $branch): PurchaseOrder
    {
        $this->actingAs($this->owner($club))->post(route('club.purchases.store'), [
            'supplier_id' => $supplier->id, 'branch_id' => $branch->id, 'order_date' => now()->toDateString(), 'discount' => 1, 'shipping' => 2, 'tax_rate' => 5,
            'items' => [['product_id' => $water->id, 'quantity' => 5, 'unit_cost' => 4.8, 'unit' => 'كرتون', 'unit_factor' => 24]], 'client_key' => 'po-'.$supplier->id,
        ])->assertSessionHasNoErrors();

        return $this->asTenant($club, fn () => PurchaseOrder::with('items')->latest('id')->firstOrFail());
    }

    public function test_an_order_is_an_intention_it_moves_no_stock_and_posts_nothing(): void
    {
        $club = $this->club();
        [$supplier, $water, $khoud] = $this->setup2($club);
        $order = $this->order($club, $supplier, $water, $khoud);
        // A repeated form is one order.
        $this->actingAs($this->owner($club))->post(route('club.purchases.store'), ['supplier_id' => $supplier->id, 'branch_id' => $khoud->id, 'order_date' => now()->toDateString(), 'items' => [['product_id' => $water->id, 'quantity' => 1, 'unit_cost' => 1]], 'client_key' => 'po-'.$supplier->id]);
        $this->asTenant($club, fn () => $this->assertSame(1, PurchaseOrder::count()));

        // 24.000 − 1.000 + 2.000 = 25.000; VAT 5% = 1.250; total 26.250.
        $this->assertSame(['24.000', '1.000', '2.000', '1.250', '26.250', PurchaseOrder::DRAFT], [$order->subtotal, $order->discount, $order->shipping, $order->tax, $order->total, $order->status]);
        $this->actingAs($this->staff($club, 'finance'))->post(route('club.purchases.approve', $order))->assertForbidden();
        $this->actingAs($this->staff($club, 'manager'))->post(route('club.purchases.approve', $order))->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($order, $water) {
            $this->assertSame(PurchaseOrder::ORDERED, $order->fresh()->status);
            $this->assertSame('0.000', $water->fresh()->stock_quantity);
            $this->assertSame(0, JournalEntry::count());
        });
        $this->actingAs($this->owner($club))->get(route('club.purchases.print', $order))->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Purchases/OrderPrint')->where('order.total', '26.250'));
    }

    public function test_goods_arrive_in_parts_onto_the_branch_shelf_only_when_approved_and_once(): void
    {
        $club = $this->club();
        [$supplier, $water, $khoud] = $this->setup2($club);
        $order = $this->order($club, $supplier, $water, $khoud);
        $this->asTenant($club, fn () => app(PurchaseService::class)->approve($order));
        $item = $order->items->first();
        $reception = $this->staff($club, 'reception');
        $manager = $this->staff($club, 'manager');

        // Recorded: nothing moves. More than ordered (counting the waiting note) is refused.
        $this->actingAs($reception)->post(route('club.purchases.receive', $order), ['lines' => [$item->id => ['quantity' => 2]], 'received_on' => now()->toDateString()])->assertForbidden();
        $this->actingAs($manager)->post(route('club.purchases.receive', $order), ['lines' => [$item->id => ['quantity' => 2, 'note' => 'one carton short']], 'received_on' => now()->toDateString(), 'client_key' => 'grn-1'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('club.purchases.receive', $order), ['lines' => [$item->id => ['quantity' => 2]], 'received_on' => now()->toDateString(), 'client_key' => 'grn-1'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('club.purchases.receive', $order), ['lines' => [$item->id => ['quantity' => 4]], 'received_on' => now()->toDateString()])->assertSessionHasErrors('lines');
        $receipt = $this->asTenant($club, fn () => GoodsReceipt::sole());
        $this->assertSame('0.000', $this->asTenant($club, fn () => $water->fresh()->stock_quantity));

        // Approved: 2 cartons × 24 = 48 bottles on Al Khoud's shelf at 0.200 a bottle; the order is partly received.
        $this->actingAs($manager)->post(route('club.inventory.receipts.decide', [$receipt, 'approve']))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('club.inventory.receipts.decide', [$receipt, 'approve']))->assertSessionHasErrors();
        $this->asTenant($club, function () use ($water, $khoud, $order) {
            $this->assertSame(['48.000', '0.200'], [app(StockService::class)->quantity($water, $khoud->id), $water->fresh()->cost]);
            $this->assertSame(PurchaseOrder::PARTIAL, $order->fresh()->status);
            $this->assertSame(0, JournalEntry::count(), 'a receipt posts nothing: the bill does');
        });

        // The rest, approved at once: the order is received.
        $this->actingAs($this->owner($club))->post(route('club.purchases.receive', $order), ['lines' => [$item->id => ['quantity' => 3]], 'received_on' => now()->toDateString(), 'approve' => true])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($water, $khoud, $order) {
            $this->assertSame('120.000', app(StockService::class)->quantity($water, $khoud->id));
            $this->assertSame(PurchaseOrder::RECEIVED, $order->fresh()->status);
        });
        $this->actingAs($this->owner($club))->post(route('club.purchases.cancel', $order), ['reason' => 'too late'])->assertSessionHasErrors();
    }

    public function test_a_supplier_bill_is_the_debt_once_and_payments_leave_the_chosen_account(): void
    {
        Storage::fake('local');
        $club = $this->club();
        [$supplier, $water, $khoud] = $this->setup2($club);
        $order = $this->order($club, $supplier, $water, $khoud);
        $this->asTenant($club, fn () => app(PurchaseService::class)->approve($order));
        $owner = $this->owner($club);
        $bill = fn (array $over = []) => $this->actingAs($owner)->post(route('club.purchases.bills.store'), $over + [
            'supplier_id' => $supplier->id, 'purchase_order_id' => $order->id, 'reference' => 'INV-77', 'issue_date' => now()->toDateString(), 'subtotal' => 26, 'discount' => 1, 'tax' => 1.25,
            'attachment' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
        ]);

        // More than the order is refused; then recorded, waiting for approval: nothing in the books yet.
        $bill(['subtotal' => 30])->assertSessionHasErrors('subtotal');
        $bill()->assertSessionHasNoErrors();
        $record = $this->asTenant($club, fn () => SupplierBill::sole());
        $this->assertSame(['26.250', SupplierBill::PENDING, now()->addDays(30)->toDateString()], [$record->total, $record->status, $record->due_date->toDateString()]);
        $this->actingAs($owner)->get(route('club.purchases.bills.attachment', $record))->assertOk();
        // The same supplier invoice number, or a second bill on the order, would count the purchase twice.
        $bill(['purchase_order_id' => null])->assertSessionHasErrors('reference');
        $bill(['reference' => 'INV-78'])->assertSessionHasErrors('purchase_order_id');
        $this->assertSame('0.000', $this->balance($club, 'suppliers_payable'));

        // Approved once: Dr purchases 25.000, Dr VAT 1.250, Cr suppliers 26.250.
        $this->actingAs($this->staff($club, 'finance'))->post(route('club.purchases.bills.decide', [$record, 'approve']))->assertForbidden();
        $this->actingAs($owner)->post(route('club.purchases.bills.decide', [$record, 'approve']))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.purchases.bills.decide', [$record, 'approve']))->assertSessionHasErrors();
        $this->assertSame(['26.250', '25.000', '-1.250'], [$this->balance($club, 'suppliers_payable'), $this->balance($club, 'purchases'), $this->balance($club, 'vat_payable')]);
        $this->asTenant($club, fn () => $this->assertSame(1, JournalEntry::where('source_type', 'supplier_bill')->count()));

        // Paid by finance from the bank, in two parts; a repeated form records once; more than owed is refused.
        $finance = $this->staff($club, 'finance');
        $bank = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('bank'));
        $pay = fn (float $amount, string $key) => $this->actingAs($finance)->post(route('club.purchases.bills.pay', $record), ['account_id' => $bank->id, 'amount' => $amount, 'paid_on' => now()->toDateString(), 'reference' => 'TRX', 'client_key' => $key]);
        $pay(20, 'pay-1')->assertSessionHasNoErrors();
        $pay(20, 'pay-1')->assertSessionHasNoErrors();
        $pay(10, 'pay-2')->assertSessionHasErrors('amount');
        $this->actingAs($this->staff($club, 'manager'))->post(route('club.purchases.bills.pay', $record), ['account_id' => $bank->id, 'amount' => 1, 'paid_on' => now()->toDateString()])->assertForbidden();
        $this->asTenant($club, fn () => $this->assertSame(['20.000', 1], [$record->fresh()->paid_total, SupplierPayment::count()]));
        $this->assertSame(['6.250', '-20.000'], [$this->balance($club, 'suppliers_payable'), $this->balance($club, 'bank')]);

        // A paid bill is not voided; the payment is reversed first, then the bill.
        $this->actingAs($owner)->post(route('club.purchases.bills.decide', [$record, 'void']), ['reason' => 'wrong supplier'])->assertSessionHasErrors();
        $payment = $this->asTenant($club, fn () => SupplierPayment::sole());
        $this->actingAs($finance)->post(route('club.purchases.payments.reverse', $payment), ['reason' => 'entered twice'])->assertSessionHasNoErrors();
        $this->actingAs($finance)->post(route('club.purchases.payments.reverse', $payment), ['reason' => 'again'])->assertSessionHasErrors();
        $this->actingAs($owner)->post(route('club.purchases.bills.decide', [$record, 'void']), ['reason' => 'wrong supplier'])->assertSessionHasNoErrors();
        $this->assertSame(['0.000', '0.000', '0.000'], [$this->balance($club, 'suppliers_payable'), $this->balance($club, 'purchases'), $this->balance($club, 'bank')]);
        $this->asTenant($club, fn () => $this->assertSame([SupplierBill::VOID, 4], [$record->fresh()->status, JournalEntry::count()]));
    }

    public function test_a_bill_without_an_order_is_a_direct_purchase_listed_once_in_the_register(): void
    {
        $club = $this->club();
        [$supplier, $water, $khoud] = $this->setup2($club);
        $order = $this->order($club, $supplier, $water, $khoud);
        $this->asTenant($club, fn () => app(PurchaseService::class)->approve($order));
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.purchases.bills.store'), ['supplier_id' => $supplier->id, 'purchase_order_id' => $order->id, 'reference' => 'A-1', 'issue_date' => now()->toDateString(), 'subtotal' => 25, 'tax' => 1.25, 'approve' => true])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.purchases.bills.store'), ['supplier_id' => $supplier->id, 'reference' => 'CASH-9', 'issue_date' => now()->toDateString(), 'subtotal' => 4, 'approve' => true])->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('club.purchases.register'))->assertOk()->assertInertia(fn ($page) => $page
            ->has('rows', 2)->where('summary.total', fn ($t) => (float) $t === 30.25)->where('summary.due', fn ($d) => (float) $d === 30.25));
        $this->actingAs($owner)->get(route('club.purchases.suppliers.show', $supplier))->assertOk()->assertInertia(fn ($page) => $page->where('totals.due', fn ($d) => (float) $d === 30.25)->has('products', 1));
    }

    public function test_purchasing_screens_follow_each_steps_permission_and_stay_in_their_club(): void
    {
        $club = $this->club();
        [$supplier, $water, $khoud] = $this->setup2($club);
        $order = $this->order($club, $supplier, $water, $khoud);
        $owner = $this->owner($club);
        foreach (['club.purchases.register', 'club.purchases.index', 'club.purchases.create', 'club.purchases.bills', 'club.purchases.suppliers'] as $name) {
            $this->actingAs($owner)->get(route($name))->assertOk();
        }
        $this->actingAs($owner)->get(route('club.purchases.show', $order))->assertOk();
        $this->actingAs($owner)->get(route('club.purchases.edit', $order))->assertOk();
        $reception = $this->staff($club, 'reception');
        $this->actingAs($reception)->get(route('club.purchases.index'))->assertForbidden();
        $this->actingAs($reception)->post(route('club.purchases.store'), [])->assertForbidden();

        $other = $this->club('Other');
        $this->actingAs($this->owner($other))->get(route('club.purchases.show', $order))->assertNotFound();
        $this->actingAs($this->owner($other))->post(route('club.purchases.bills.store'), ['supplier_id' => $supplier->id, 'reference' => 'X', 'issue_date' => now()->toDateString(), 'subtotal' => 1])->assertSessionHasErrors('supplier_id');
        $this->actingAs($owner)->delete(route('club.purchases.suppliers.destroy', $supplier))->assertSessionHasErrors();
    }
}
