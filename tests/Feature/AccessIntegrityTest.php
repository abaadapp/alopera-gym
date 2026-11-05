<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Modules\Documents\Models\BusinessDocument;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** A branch's records stay its own through every door, and a secret is never written where others read. */
class AccessIntegrityTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array{0: Branch, 1: Branch} */
    private function branches(Tenant $club): array
    {
        return $this->asTenant($club, fn () => [Branch::orderByDesc('is_main')->orderBy('id')->firstOrFail(), Branch::create(['name' => 'فرع الخوض', 'is_active' => true])]);
    }

    public function test_a_customer_credit_is_not_applied_to_another_branchs_invoice(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->branches($club);
        $member = $this->member($club);
        // A credit in the main branch: a transfer confirmed after the invoice was paid at the desk.
        $credit = $this->asTenant($club, function () use ($member, $main) {
            $invoice = app(InvoiceService::class)->issue($member, [['description' => 'A', 'quantity' => 1, 'unit_price' => 10]], ['type' => 'other', 'branch_id' => $main->id]);
            $transfer = app(PaymentService::class)->record($invoice, 10, 'bank_transfer');
            app(PaymentService::class)->record($invoice->fresh(), 10, 'cash');

            return app(PaymentService::class)->confirm($transfer);
        });
        $theirs = $this->asTenant($club, fn () => app(InvoiceService::class)->issue($member, [['description' => 'B', 'quantity' => 1, 'unit_price' => 5]], ['type' => 'other', 'branch_id' => $khoud->id]));
        $reception = $this->staff($club, 'reception', ['branch_id' => $main->id]);
        $reception->role->update(['permissions' => array_merge($reception->role->permissions, ['payments.refund'])]);

        $this->actingAs($reception->fresh())->post(route('club.payments.allocate', $credit), ['invoice_id' => $theirs->id, 'amount' => 5, 'client_key' => (string) Str::uuid()])->assertNotFound();

        $this->assertSame('0.000', $theirs->fresh()->paid_total);
    }

    public function test_a_door_pin_is_not_written_to_the_audit_trail(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $this->actingAs($this->owner($club))->post(route('club.members.credentials.store', $member), ['type' => 'pin', 'identifier' => '482913'])->assertSessionHasNoErrors();

        $this->actingAs($this->staff($club, 'manager'))->get(route('club.audit.index'))->assertOk()->assertDontSee('482913');
        $this->actingAs($this->owner($club))->get(route('club.audit.index'))->assertOk()->assertDontSee('482913');
    }

    public function test_a_confidential_document_stays_off_the_audit_screen_of_someone_who_may_not_see_it(): void
    {
        $club = $this->club();
        $this->asTenant($club, function () {
            DocumentType::ensureDefaults();
            BusinessDocument::create(['name' => 'عقد الإيجار', 'document_type_id' => DocumentType::value('id'), 'reference_number' => 'LEASE-77731', 'expiry_date' => today()->addYear(), 'status' => 'active', 'renewal_months' => 12, 'is_confidential' => true]);
        });

        $this->actingAs($this->staff($club, 'manager'))->get(route('club.audit.index'))->assertOk()->assertDontSee('LEASE-77731');
        $this->actingAs($this->owner($club))->get(route('club.audit.index'))->assertOk()->assertSee('LEASE-77731');
    }

    public function test_a_supplier_bill_is_not_raised_against_another_branchs_order_and_ones_own_lands_in_ones_branch(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->branches($club);
        [$supplier, $order] = $this->asTenant($club, function () use ($main) {
            $supplier = Supplier::create(['name' => 'مورد']);

            return [$supplier, PurchaseOrder::create(['number' => 'PO-1', 'supplier_id' => $supplier->id, 'branch_id' => $main->id, 'status' => 'approved', 'order_date' => today(), 'subtotal' => 10, 'total' => 10])];
        });
        $finance = $this->staff($club, 'finance', ['branch_id' => $khoud->id]);
        $bill = fn (array $extra) => ['supplier_id' => $supplier->id, 'reference' => 'X-'.Str::random(4), 'issue_date' => today()->toDateString(), 'subtotal' => 1] + $extra;

        $this->actingAs($finance)->post(route('club.purchases.bills.store'), $bill(['purchase_order_id' => $order->id]))->assertNotFound();
        $this->asTenant($club, fn () => $this->assertSame(0, SupplierBill::count()));

        $this->actingAs($finance)->post(route('club.purchases.bills.store'), $bill([]))->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame($khoud->id, SupplierBill::sole()->branch_id));
    }

    public function test_cancelling_another_branchs_sale_does_not_void_its_invoice(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->branches($club);
        $member = $this->member($club);
        $sale = $this->asTenant($club, fn () => app(MembershipService::class)->sell($member, $this->plan($club), ['branch_id' => $khoud->id]));
        $manager = $this->staff($club, 'manager', ['branch_id' => $main->id]);

        $this->actingAs($manager)->post(route('club.memberships.cancel', $sale), ['reason' => 'x'])->assertNotFound();

        $this->asTenant($club, function () use ($sale) {
            $this->assertSame(Membership::PENDING, $sale->fresh()->status);
            $this->assertNotSame(Invoice::VOID, $sale->invoice()->first()->status);
        });
    }
}
