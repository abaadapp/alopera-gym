<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Finance\Models\CashShift;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CashShifts;
use App\Modules\Finance\Services\InvoiceService;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * A shift closed while a cash payment of it is cancelled, or a cash expense of it deleted, at the same moment, in
 * separate processes on PostgreSQL. Once closed, the shift's expected cash is what its records say: the closing
 * counted the drawer against them and put any difference in the books, so a record it counted is never changed after.
 */
class CashShiftCloseRaceTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    /** The cancel or delete reads the shift, then is slowed down: the closing runs in that moment. */
    private const SHIFT_READ = 'select * from "gym_cash_shifts" where "gym_cash_shifts"."id" = ?';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    /** A drawer's shift opened with 20.000, a 50.000 cash payment and a 5.000 cash expense in it. */
    private function desk(): array
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $drawer = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'));
        $this->actingAs($owner)->post(route('club.cash-shifts.store'), ['account_id' => $drawer->id, 'opening_float' => '20', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $invoice = $this->asTenant($club, fn () => app(InvoiceService::class)->issue(null, [['description' => 'خدمة', 'unit_price' => '50']], ['type' => 'other'], 'عميل'));
        $this->actingAs($owner)->post(route('club.payments.store', $invoice), ['amount' => '50', 'method' => 'cash', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.expenses.store'), ['description' => 'ماء', 'amount' => '5', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash', 'client_key' => $this->key()])->assertSessionHasNoErrors();

        return $this->asTenant($club, fn () => [$club, $owner, CashShift::sole(), Payment::where('invoice_id', $invoice->id)->sole(), Expense::sole()]);
    }

    private function close(User $owner, CashShift $shift, string $dir): int
    {
        self::waitFor("$dir/inside");

        return $this->actingAs($owner)->post(route('club.cash-shifts.close', $shift), ['counted_cash' => '65', 'close_note' => 'عدّ الدرج', 'client_key' => $this->key()])->getStatusCode();
    }

    private function assertCountUnchanged($club, CashShift $shift, array $results): void
    {
        $this->asTenant($club, function () use ($shift, $results) {
            $shift = CashShift::findOrFail($shift->id);
            $now = app(CashShifts::class)->summary($shift)['expected'];
            $records = 'payment '.Payment::query()->value('status').', expenses '.Expense::count().' — '.json_encode($results, JSON_UNESCAPED_UNICODE);
            $this->assertSame(CashShift::CLOSED, $shift->status, $records);
            $this->assertSame($shift->expected_cash, $now, "closed expecting {$shift->expected_cash}, its records now say {$now}: {$records}");
        });
    }

    public function test_a_cash_payment_cancelled_while_its_shift_closes_is_counted_once(): void
    {
        [$club, $owner, $shift, $payment] = $this->desk();

        $results = $this->concurrently([
            function (string $dir) use ($owner, $payment) {
                self::pauseAfter(self::SHIFT_READ, $dir, 1.5);

                return $this->actingAs($owner)->post(route('club.payments.cancel', $payment), ['reason' => 'دفعة بالخطأ'])->getStatusCode();
            },
            fn (string $dir) => $this->close($owner, $shift, $dir),
        ]);

        $this->assertCountUnchanged($club, $shift, $results);
    }

    public function test_a_cash_expense_deleted_while_its_shift_closes_is_counted_once(): void
    {
        [$club, $owner, $shift, , $expense] = $this->desk();

        $results = $this->concurrently([
            function (string $dir) use ($owner, $expense) {
                self::pauseAfter(self::SHIFT_READ, $dir, 1.5);

                return $this->actingAs($owner)->delete(route('club.expenses.destroy', $expense))->getStatusCode();
            },
            fn (string $dir) => $this->close($owner, $shift, $dir),
        ]);

        $this->assertCountUnchanged($club, $shift, $results);
    }
}
