<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\AccessDevice;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Gym\Models\Member;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Branches of one club, enforced on the server: a user tied to a branch gets 404 for another branch's records by
 * their address, saves new records in their own branch and cannot name another; records without a branch count as
 * the main branch's; the member record stays one per club. And the panel has no help-and-support section.
 */
class BranchIsolationTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array{0: Branch, 1: Branch, 2: Branch} the main branch and two more */
    private function branches(Tenant $club): array
    {
        return $this->asTenant($club, fn () => [
            Branch::where('is_main', true)->firstOrFail(),
            Branch::create(['name' => 'فرع الخوض', 'is_active' => true]),
            Branch::create(['name' => 'فرع صلالة', 'is_active' => true]),
        ]);
    }

    private function invoice(Tenant $club, ?int $branch, ?Member $member = null): Invoice
    {
        return $this->asTenant($club, fn () => app(InvoiceService::class)->issue($member, [['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 5]], ['type' => 'other', 'branch_id' => $branch], $member ? null : 'زبون'));
    }

    public function test_a_branch_tied_user_gets_404_for_another_branchs_records(): void
    {
        $club = $this->club();
        [$main, $khoud, $salalah] = $this->branches($club);
        $reception = $this->staff($club, 'reception', ['branch_id' => $khoud->id]);
        $manager = $this->staff($club, 'manager', ['branch_id' => $khoud->id]);
        $owner = $this->owner($club);

        $theirs = $this->invoice($club, $salalah->id);
        $own = $this->invoice($club, $khoud->id);
        $legacy = $this->invoice($club, null);

        $this->actingAs($reception)->get(route('club.invoices.show', $own))->assertOk();
        $this->actingAs($reception)->get(route('club.invoices.show', $theirs))->assertNotFound();
        $this->actingAs($reception)->get(route('club.invoices.print', $theirs))->assertNotFound();
        $this->actingAs($reception)->post(route('club.payments.store', $theirs), ['amount' => 5, 'method' => 'cash'])->assertNotFound();
        $this->assertSame('0.000', $theirs->fresh()->paid_total);
        // Without a branch is the main branch's, not Al Khoud's.
        $this->actingAs($reception)->get(route('club.invoices.show', $legacy))->assertNotFound();

        // A club-wide user opens everything, whatever branch the top bar shows.
        $this->actingAs($owner)->post(route('club.branch.switch'), ['branch_id' => $khoud->id]);
        $this->actingAs($owner)->get(route('club.invoices.show', $theirs))->assertOk();

        // Staff of the main branch reach the records without a branch.
        $mainDesk = $this->staff($club, 'reception', ['branch_id' => $main->id]);
        $this->actingAs($mainDesk)->get(route('club.invoices.show', $legacy))->assertOk();
        $this->actingAs($mainDesk)->get(route('club.invoices.show', $theirs))->assertNotFound();

        // Payments, sessions and bookings, expenses, devices, employees: the same guard.
        $payment = $this->asTenant($club, fn () => app(PaymentService::class)->record($theirs, 5, 'cash'));
        $this->actingAs($reception)->get(route('club.payments.receipt', $payment))->assertNotFound();

        $session = $this->classSession($club);
        $this->asTenant($club, fn () => $session->forceFill(['branch_id' => $salalah->id])->save());
        $this->actingAs($reception)->get(route('club.sessions.show', $session))->assertNotFound();
        $member = $this->member($club);
        $booking = $this->asTenant($club, fn () => Booking::create(['class_session_id' => $session->id, 'member_id' => $member->id, 'status' => 'booked', 'source' => 'staff']));
        $this->actingAs($reception)->post(route('club.bookings.cancel', $booking))->assertNotFound();

        $expense = $this->asTenant($club, fn () => Expense::create(['number' => 'EXP-T1', 'branch_id' => $salalah->id, 'description' => 'كهرباء', 'amount' => 10, 'expense_date' => today(), 'payment_method' => 'cash']));
        $this->actingAs($manager)->get(route('club.expenses.attachment', $expense))->assertNotFound();
        $this->actingAs($manager)->delete(route('club.expenses.destroy', $expense))->assertNotFound();
        $this->assertNotNull($this->asTenant($club, fn () => Expense::find($expense->id)));

        $device = $this->asTenant($club, function () use ($salalah) {
            $device = new AccessDevice(['name' => 'بوابة صلالة', 'type' => 'qr_reader', 'direction' => 'in', 'purpose' => 'members', 'branch_id' => $salalah->id]);
            $device->issueToken();
            $device->save();

            return $device;
        });
        $this->actingAs($manager)->delete(route('club.devices.destroy', $device))->assertNotFound();

        $employee = $this->asTenant($club, fn () => Employee::create(['name' => 'موظف صلالة', 'branch_id' => $salalah->id]));
        $this->actingAs($manager)->put(route('club.employees.update', $employee), ['name' => 'تغيير', 'status' => 'active'])->assertNotFound();
        $this->assertSame('موظف صلالة', $employee->fresh()->name);
    }

    public function test_lists_and_notifications_keep_to_the_users_branch(): void
    {
        $club = $this->club();
        [, $khoud, $salalah] = $this->branches($club);
        $finance = $this->staff($club, 'finance', ['branch_id' => $khoud->id]);
        $clubFinance = $this->staff($club, 'finance');

        $this->invoice($club, $khoud->id);
        $theirs = $this->invoice($club, $salalah->id);
        $this->invoice($club, null);

        $this->actingAs($finance)->get(route('club.invoices.index'))->assertInertia(fn ($page) => $page->has('invoices.data', 1));
        $this->actingAs($clubFinance)->get(route('club.invoices.index'))->assertInertia(fn ($page) => $page->has('invoices.data', 3));

        // A transfer waiting for confirmation in Salalah: the Salalah amount and customer are not announced in Al Khoud.
        $this->asTenant($club, fn () => app(PaymentService::class)->record($theirs, 5, 'bank_transfer'));
        $this->assertSame(0, StaffNotification::withoutGlobalScopes()->where('user_id', $finance->id)->count());
        $this->assertSame(1, StaffNotification::withoutGlobalScopes()->where('user_id', $clubFinance->id)->where('kind', 'payment')->count());
    }

    public function test_new_records_are_saved_in_the_branch_where_they_happen(): void
    {
        $club = $this->club();
        [, $khoud, $salalah] = $this->branches($club);
        $reception = $this->staff($club, 'reception', ['branch_id' => $khoud->id]);

        // A member form cannot name another branch; left empty, the member joins the user's branch.
        $this->actingAs($reception)->post(route('club.members.store'), ['full_name' => 'عضو جديد', 'phone' => '96891112233', 'branch_id' => $salalah->id])
            ->assertSessionHasErrors('branch_id');
        $this->actingAs($reception)->post(route('club.members.store'), ['full_name' => 'عضو جديد', 'phone' => '96891112233'])->assertSessionHasNoErrors();
        $this->assertSame($khoud->id, (int) $this->asTenant($club, fn () => Member::where('full_name', 'عضو جديد')->value('branch_id')));

        // The member record is one per club: Al Khoud's reception serves a Salalah member, and the visit is Al Khoud's.
        $visitor = $this->member($club, ['full_name' => 'عضوة صلالة', 'branch_id' => $salalah->id]);
        $this->activeMembership($club, $visitor);
        $this->actingAs($reception)->postJson(route('club.checkins.store'), ['member_id' => $visitor->id])->assertOk()->assertJson(['allowed' => true]);
        $this->assertSame($khoud->id, (int) $this->asTenant($club, fn () => CheckIn::where('member_id', $visitor->id)->value('branch_id')));

        // Her profile opens; her Salalah invoice is listed but not opened from Al Khoud.
        $this->actingAs($reception)->get(route('club.members.show', $visitor))->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoices.0.reachable', false)->where('memberships.0.invoice_reachable', false));

        // A point-of-sale sale is Al Khoud's, and takes Al Khoud's stock (stock is kept per branch).
        $product = $this->asTenant($club, function () use ($khoud) {
            $product = Product::create(['name' => 'ماء', 'price' => 0.5]);
            app(StockService::class)->move($product, 10, 'adjustment', null, 'opening', 0.2);
            app(StockService::class)->move($product, 4, 'adjustment', null, 'opening', 0.2, branchId: $khoud->id);

            return $product;
        });
        $this->actingAs($reception)->post(route('club.pos.checkout'), ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame($khoud->id, (int) $this->asTenant($club, fn () => Sale::latest('id')->value('branch_id')));
        $this->asTenant($club, fn () => $this->assertSame(['3.000', '13.000'], [app(StockService::class)->quantity($product, $khoud->id), $product->fresh()->stock_quantity]));

        // A club-wide user's manual invoice goes to the branch the top bar shows.
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.branch.switch'), ['branch_id' => $salalah->id]);
        $this->actingAs($owner)->post(route('club.invoices.store'), ['customer_name' => 'زبون', 'lines' => [['description' => 'تأجير قاعة', 'quantity' => 1, 'unit_price' => 20]]])->assertSessionHasNoErrors();
        $this->assertSame($salalah->id, (int) $this->asTenant($club, fn () => Invoice::latest('id')->value('branch_id')));
    }

    public function test_another_club_never_reaches_a_record_whatever_its_branch(): void
    {
        $club = $this->club();
        $other = $this->club('B');
        $invoice = $this->invoice($club, null);

        $this->actingAs($this->owner($other))->get(route('club.invoices.show', $invoice->id))->assertNotFound();
        $this->actingAs($this->staff($other, 'reception'))->get(route('club.invoices.show', $invoice->id))->assertNotFound();
    }

    public function test_the_panel_address_takes_staff_without_a_dashboard_to_their_own_screen(): void
    {
        $club = $this->club();
        $this->actingAs($this->staff($club, 'reception'))->get(route('club.dashboard'))->assertRedirect(route('club.reception'));
        $this->actingAs($this->staff($club, 'trainer'))->get(route('club.dashboard'))->assertRedirect(route('club.trainer'));
        $this->actingAs($this->owner($club))->get(route('club.dashboard'))->assertOk();
        // Saving tiles still needs the dashboard.
        $this->actingAs($this->staff($club, 'reception'))->put(route('club.dashboard.tiles'), ['order' => [], 'hidden' => []])->assertForbidden();
    }

    public function test_the_panel_has_no_help_and_support_section_and_every_menu_link_exists(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => (string) $r->getName().' '.$r->uri());
        $this->assertSame([], $names->filter(fn ($n) => preg_match('/help|support|ticket|faq/i', $n))->values()->all());

        // Every route the sidebar, its tabs and the settings hub name is a real route.
        $sources = file_get_contents(resource_path('js/lib/nav.ts')).file_get_contents(resource_path('js/Pages/Club/Settings/Index.tsx'));
        preg_match_all("/route: '([a-z0-9_.\\-]+)'/", $sources, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $route) {
            $this->assertTrue(Route::has($route), "missing route {$route}");
        }
        $this->assertStringNotContainsString('المساعدة', $sources);
        $this->assertStringNotContainsString('الدعم', $sources);
    }
}
