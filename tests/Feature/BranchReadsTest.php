<?php

namespace Tests\Feature;

use App\Console\Commands\SendDuesReminders;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\PaymentAllocation;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\AccessCredential;
use App\Modules\Gym\Models\AccessDevice;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Gym\Models\Member;
use App\Modules\Inventory\Models\PosDevice;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Inventory\Models\SupplierPayment;
use App\Modules\Messaging\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** A manager tied to a branch reads only that branch's doors, tills, reminders and money, on every screen that lists them. */
class BranchReadsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array{0: Tenant, 1: Branch, 2: User} the club, its main branch, and the manager of its second branch */
    private function khoudManager(): array
    {
        $club = $this->club();
        [$main, $khoud] = $this->asTenant($club, fn () => [Branch::orderByDesc('is_main')->orderBy('id')->firstOrFail(), Branch::create(['name' => 'فرع الخوض', 'is_active' => true])]);

        return [$club, $main, $this->staff($club, 'manager', ['branch_id' => $khoud->id])];
    }

    private function mainInvoice(Tenant $club, Branch $main, Member $member, string $amount = '10'): Invoice
    {
        return $this->asTenant($club, fn () => app(InvoiceService::class)->issue($member, [['description' => 'خدمة', 'quantity' => 1, 'unit_price' => $amount]], ['type' => 'other', 'branch_id' => $main->id]));
    }

    /** @return array<string, mixed> figure label => value */
    private function figures($response): array
    {
        $figures = [];
        $response->assertOk()->assertInertia(function (Assert $page) use (&$figures) {
            foreach ($page->toArray()['props']['data']['figures'] as $f) {
                $figures[$f['label']] = $f['value'];
            }
        });

        return $figures;
    }

    public function test_a_branch_manager_sees_only_their_branchs_door_readings_and_staff_cards(): void
    {
        [$club, $main, $manager] = $this->khoudManager();
        $member = $this->member($club);
        $mainStaff = $this->staff($club, 'reception', ['branch_id' => $main->id, 'name' => 'موظفة الرئيسي']);
        $this->asTenant($club, function () use ($main, $member, $mainStaff) {
            $door = (new AccessDevice(['name' => 'باب الرئيسي', 'type' => 'qr_reader', 'branch_id' => $main->id]))->forceFill(['token_hash' => hash('sha256', 'door'), 'token_last4' => 'door']);
            $door->save();
            CheckIn::create(['member_id' => $member->id, 'branch_id' => $main->id, 'access_device_id' => $door->id, 'method' => 'qr', 'result' => 'allowed', 'checked_in_at' => now()]);
            AccessCredential::create(['holder_type' => 'user', 'holder_id' => $mainStaff->id, 'type' => 'card', 'identifier' => '55667788']);
        });

        $this->actingAs($manager)->get(route('club.devices.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events', [])
            ->where('staffCredentials', [])
            ->where('staff', fn ($staff) => ! collect($staff)->contains('id', $mainStaff->id)));
    }

    public function test_a_branch_manager_cannot_give_or_take_a_door_card_of_another_branchs_staff(): void
    {
        [$club, $main, $manager] = $this->khoudManager();
        $mainStaff = $this->staff($club, 'reception', ['branch_id' => $main->id]);
        $card = $this->asTenant($club, fn () => AccessCredential::create(['holder_type' => 'user', 'holder_id' => $mainStaff->id, 'type' => 'card', 'identifier' => '55667788']));

        $this->actingAs($manager)->post(route('club.devices.staff-credentials'), ['holder_id' => $mainStaff->id, 'type' => 'card', 'identifier' => '11223344'])
            ->assertSessionHasErrors('holder_id');
        $this->actingAs($manager)->delete(route('club.devices.staff-credentials.destroy', $card))->assertNotFound();

        $this->asTenant($club, fn () => $this->assertSame(['55667788'], AccessCredential::where('holder_id', $mainStaff->id)->pluck('identifier')->all()));
    }

    public function test_a_branch_manager_sees_only_their_branchs_till_devices(): void
    {
        [$club, $main, $manager] = $this->khoudManager();
        $this->asTenant($club, fn () => (new PosDevice(['name' => 'كاشير الرئيسي', 'branch_id' => $main->id]))->forceFill(['status' => PosDevice::ACTIVE])->save());

        $this->actingAs($manager)->get(route('club.settings.pos-devices'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('devices', []));
    }

    public function test_the_dues_reminder_log_shows_only_the_branchs_invoices(): void
    {
        [$club, $main, $manager] = $this->khoudManager();
        $member = $this->member($club);
        $invoice = $this->mainInvoice($club, $main, $member);
        $this->asTenant($club, fn () => Message::create(['member_id' => $member->id, 'direction' => 'out', 'type' => 'template', 'status' => 'sent',
            'template_key' => SendDuesReminders::TEMPLATES[1], 'related_type' => 'invoice', 'related_id' => $invoice->id]));

        $this->actingAs($manager)->get(route('club.dues'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('reminders.log', []));
    }

    public function test_a_members_statement_for_a_branch_holds_only_that_branchs_invoices(): void
    {
        [$club, $main, $manager] = $this->khoudManager();
        $member = $this->member($club);
        $invoice = $this->mainInvoice($club, $main, $member);
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice, '4', 'cash'));

        $f = $this->figures($this->actingAs($manager)->get(route('club.reports.show', ['report' => 'member-statement', 'member_id' => $member->id])));

        $this->assertSame(['0.000', '0.000'], [$f['المطالبات (مدين)'], $f['المدفوعات والتخفيضات (دائن)']]);
    }

    public function test_the_suppliers_and_adjustments_reports_of_a_branch_leave_out_another_branchs_payments_and_credit(): void
    {
        [$club, $main, $manager] = $this->khoudManager();
        $member = $this->member($club);
        $this->asTenant($club, function () use ($main) {
            $supplier = Supplier::create(['name' => 'مورد الرئيسي']);
            $bill = SupplierBill::create(['number' => 'SB-MAIN', 'supplier_id' => $supplier->id, 'branch_id' => $main->id, 'reference' => 'MAIN-1', 'issue_date' => today(), 'subtotal' => 10, 'total' => 10, 'status' => SupplierBill::APPROVED]);
            $bill->forceFill(['paid_total' => 4])->save();
            SupplierPayment::create(['number' => 'SP-MAIN', 'supplier_bill_id' => $bill->id, 'supplier_id' => $supplier->id, 'account_id' => app(ChartOfAccounts::class)->account('cash')->id, 'amount' => 4, 'paid_on' => today()]);
        });
        $paid = $this->mainInvoice($club, $main, $member, '5');
        $open = $this->mainInvoice($club, $main, $member, '3');
        $this->asTenant($club, function () use ($paid, $open) {
            $payment = app(PaymentService::class)->record($paid, '5', 'cash');
            PaymentAllocation::create(['payment_id' => $payment->id, 'invoice_id' => $open->id, 'amount' => 1]);
        });

        $suppliers = $this->figures($this->actingAs($manager)->get(route('club.reports.show', ['report' => 'suppliers'])));
        $this->assertSame([0.0, 0.0], [(float) $suppliers['المدفوع'], (float) $suppliers['المستحق اليوم']]);

        $adjustments = $this->figures($this->actingAs($manager)->get(route('club.reports.show', ['report' => 'adjustments'])));
        $this->assertSame(0, $adjustments['عمليات التسوية']);
    }
}
