<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\CashShift;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CashShifts;
use App\Modules\Finance\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The cash drawer's shift: it counts what passed through its drawer (cash in, refunds and expenses out, money moved to
 * the bank — a transfer, not revenue) and the other methods taken during it; it closes once with the count, a reason
 * for any difference, and the difference in the books; one open shift per drawer, on its own permission.
 */
class CashShiftsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function invoice($club, string $price): Invoice
    {
        return $this->asTenant($club, fn () => app(InvoiceService::class)->issue(null, [['description' => 'خدمة', 'unit_price' => $price]], ['type' => 'other'], 'عميل'));
    }

    public function test_a_shift_counts_its_drawer_and_closes_once_with_its_difference_in_the_books(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$drawer, $bank] = $this->asTenant($club, fn () => [app(ChartOfAccounts::class)->account('cash'), app(ChartOfAccounts::class)->account('bank')]);

        $this->actingAs($owner)->post(route('club.cash-shifts.store'), ['account_id' => $drawer->id, 'opening_float' => '20', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $shift = $this->asTenant($club, fn () => CashShift::sole());

        // What happens at the desk while it is open belongs to it.
        $a = $this->invoice($club, '50');
        $b = $this->invoice($club, '30');
        $this->actingAs($owner)->post(route('club.payments.store', $a), ['amount' => '50', 'method' => 'cash', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.payments.store', $b), ['amount' => '30', 'method' => 'card', 'reference' => 'T-77', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.expenses.store'), ['description' => 'ماء', 'amount' => '5', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.credit-notes.store', $a), ['amount' => '10', 'reason' => 'خصم', 'refund_now' => true, 'method' => 'cash', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.cash-shifts.move', $shift), ['direction' => 'out', 'amount' => '40', 'counter_account_id' => $bank->id, 'reason' => 'إيداع في البنك', 'client_key' => $this->key()])->assertRedirect()->assertSessionHasNoErrors();
        // More than the drawer holds cannot be taken to the bank.
        $this->actingAs($owner)->post(route('club.cash-shifts.move', $shift), ['direction' => 'out', 'amount' => '16', 'counter_account_id' => $bank->id, 'reason' => 'إيداع', 'client_key' => $this->key()])->assertSessionHasErrors('amount');

        $this->asTenant($club, function () use ($shift) {
            // Two payments and the refund, and the expense.
            $this->assertSame([3, 1], [Payment::where('cash_shift_id', $shift->id)->count(), DB::table('expenses')->where('cash_shift_id', $shift->id)->count()]);
            $summary = app(CashShifts::class)->summary($shift);
            $this->assertSame(['50.000', '10.000'], [$summary['methods']['cash']['received'], $summary['methods']['cash']['refunded']]);
            $this->assertSame('30.000', $summary['methods']['card']['received']);
            // 20 + 50 − 10 − 5 − 40
            $this->assertSame('15.000', $summary['expected']);
        });
        // Moving cash to the bank is a transfer: the bank has it, and no revenue came of it.
        $this->assertSame('40.000', $this->asTenant($club, fn () => app(FinanceReports::class)->balance($bank)));
        $this->assertSame('70.000', $this->asTenant($club, fn () => app(FinanceReports::class)->incomeStatement(today()->toDateString(), today()->toDateString())['total_revenue']));

        // Counted one short: a reason is required, then it closes once, the shortage in the books.
        $close = fn (array $data, ?string $key = null) => $this->actingAs($owner)->post(route('club.cash-shifts.close', $shift), $data + ['client_key' => $key ?? $this->key()]);
        $close(['counted_cash' => '14'])->assertSessionHasErrors('close_note');
        $key = $this->key();
        $close(['counted_cash' => '14', 'close_note' => 'فكة ناقصة'], $key)->assertSessionHasNoErrors();
        $close(['counted_cash' => '14', 'close_note' => 'فكة ناقصة'], $key)->assertSessionHasNoErrors();
        $close(['counted_cash' => '14', 'close_note' => 'مرة ثانية'])->assertSessionHasErrors();

        $this->asTenant($club, function () use ($shift) {
            $shift = $shift->fresh();
            $this->assertSame([CashShift::CLOSED, '15.000', '14.000', '-1.000'], [$shift->status, $shift->expected_cash, $shift->counted_cash, $shift->difference]);
            $this->assertSame('1.000', app(FinanceReports::class)->balanceOf('cash_over_short'));
            $this->assertSame(1, DB::table('audit_logs')->where('event', 'cash_shift.closed')->count());
            $this->assertSame(1, DB::table('gym_journal_entries')->where(['source_type' => 'cash_shift', 'source_id' => $shift->id])->count());
        });

        // Closed: a payment now belongs to no shift.
        $c = $this->invoice($club, '5');
        $this->actingAs($owner)->post(route('club.payments.store', $c), ['amount' => '5', 'method' => 'cash', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertNull(Payment::where('invoice_id', $c->id)->sole()->cash_shift_id));
        $this->actingAs($owner)->get(route('club.cash-shifts.show', $shift))->assertOk();
    }

    public function test_one_open_shift_per_drawer_on_its_own_permission_within_its_branch(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $drawer = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'));
        $open = fn ($user) => $this->actingAs($user)->post(route('club.cash-shifts.store'), ['account_id' => $drawer->id, 'opening_float' => '0', 'client_key' => $this->key()]);

        // Acting on shifts is the owner's unless granted; finance reads them (the cash closing report) and opens none.
        $this->actingAs($this->staff($club, 'finance'))->get(route('club.cash-shifts.index'))->assertOk();
        $open($this->staff($club, 'finance'))->assertForbidden();
        $this->actingAs($this->staff($club, 'manager'))->get(route('club.cash-shifts.index'))->assertForbidden();
        $open($this->staff($club, 'reception'))->assertForbidden();
        $this->asTenant($club, fn () => Role::create(['name' => 'مراجعة الصندوق', 'key' => 'drawer_view', 'permissions' => ['cash_shifts.view']]));
        $viewer = $this->staff($club, 'drawer_view');
        $this->actingAs($viewer)->get(route('club.cash-shifts.index'))->assertOk();
        $open($viewer)->assertForbidden();

        $open($owner)->assertSessionHasNoErrors();
        $open($owner)->assertSessionHasErrors('account_id');
        $this->asTenant($club, fn () => $this->assertSame(1, CashShift::count()));

        // A branch's staff do not see another branch's shift.
        $khoud = $this->asTenant($club, fn () => Branch::create(['name' => 'فرع الخوض', 'is_active' => true]));
        $this->asTenant($club, fn () => CashShift::query()->update(['branch_id' => $khoud->id]));
        $this->asTenant($club, fn () => Role::create(['name' => 'صندوق الفرع', 'key' => 'branch_drawer', 'permissions' => ['cash_shifts.view', 'cash_shifts.manage']]));
        $mainDesk = $this->staff($club, 'branch_drawer', ['branch_id' => $this->asTenant($club, fn () => Branch::where('id', '!=', $khoud->id)->value('id') ?? Branch::create(['name' => 'الرئيسي', 'is_active' => true])->id)]);
        $this->actingAs($mainDesk)->get(route('club.cash-shifts.show', $this->asTenant($club, fn () => CashShift::sole())))->assertNotFound();
    }
}
