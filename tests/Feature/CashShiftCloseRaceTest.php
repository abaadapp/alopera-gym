<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\CashShift;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CashShifts;
use App\Modules\Finance\Services\InvoiceService;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * A shift closed while a cash payment of it is cancelled, or a cash expense of it deleted or changed, at the same
 * moment, in separate processes on PostgreSQL. Once closed, the shift's expected cash is what its records say: the
 * closing counted the drawer against them and put any difference in the books, so a record it counted is never changed
 * after. Whichever comes first, the other waits for it: a change first is in the count; a closing first refuses it.
 */
class CashShiftCloseRaceTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    /** The cancel, delete or change reads the shift, then is slowed down: the closing runs in that moment. */
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

    /**
     * A drawer's shift opened with 20.000, $payments cash payments of 50.000 and a 5.000 cash expense in it.
     *
     * @return array{0: mixed, 1: User, 2: CashShift, 3: list<Payment>, 4: Expense}
     */
    private function desk(int $payments = 1): array
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $drawer = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'));
        $this->actingAs($owner)->post(route('club.cash-shifts.store'), ['account_id' => $drawer->id, 'opening_float' => '20', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        for ($i = 0; $i < $payments; $i++) {
            $invoice = $this->asTenant($club, fn () => app(InvoiceService::class)->issue(null, [['description' => 'خدمة', 'unit_price' => '50']], ['type' => 'other'], 'عميل'));
            $this->actingAs($owner)->post(route('club.payments.store', $invoice), ['amount' => '50', 'method' => 'cash', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        }
        $this->actingAs($owner)->post(route('club.expenses.store'), ['description' => 'ماء', 'amount' => '5', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash', 'client_key' => $this->key()])->assertSessionHasNoErrors();

        return $this->asTenant($club, fn () => [$club, $owner, CashShift::sole(), Payment::orderBy('id')->get()->all(), Expense::sole()]);
    }

    /** @return array{status: int, errors: array, exception: ?string} */
    private function send(User $user, string $method, string $url, array $data = []): array
    {
        $response = $this->actingAs($user)->{$method}($url, $data);
        $errors = session('errors');

        return [
            'status' => $response->getStatusCode(),
            'errors' => $errors instanceof ViewErrorBag ? $errors->getBag('default')->toArray() : [],
            'exception' => $response->exception ? $response->exception::class.': '.$response->exception->getMessage() : null,
        ];
    }

    /** The closing, once the other request is inside (it read the shift): 65.000 counted, the drawer as it was. */
    private function close(User $owner, CashShift $shift, string $dir): array
    {
        self::waitFor("$dir/inside");

        return $this->send($owner, 'post', route('club.cash-shifts.close', $shift), ['counted_cash' => '65', 'close_note' => 'عدّ الدرج', 'client_key' => $this->key()]);
    }

    /** Refused because its shift closed (the closing put the drawer right): the rule, said to the user. */
    private static function refused(array $result): bool
    {
        return str_contains((string) ($result['value']['exception'] ?? ''), 'أُقفلت') || str_contains(json_encode($result['value']['errors'] ?? [], JSON_UNESCAPED_UNICODE), 'أُقفلت');
    }

    private function assertCountUnchanged($club, CashShift $shift, array $results): void
    {
        $this->asTenant($club, function () use ($shift, $results) {
            $shift = CashShift::findOrFail($shift->id);
            $now = app(CashShifts::class)->summary($shift)['expected'];
            $records = 'payments '.Payment::orderBy('id')->pluck('status')->implode(',').', expenses '.Expense::pluck('amount')->implode(',').' — '.json_encode($results, JSON_UNESCAPED_UNICODE);
            $this->assertSame(CashShift::CLOSED, $shift->status, $records);
            $this->assertSame($shift->expected_cash, $now, "closed expecting {$shift->expected_cash}, its records now say {$now}: {$records}");
            // Each request went through, or was refused because the shift had closed: nothing else.
            foreach ($results as $result) {
                $this->assertTrue($result['ok'], $records);
                $this->assertTrue(self::refused($result) || $result['value']['exception'] === null, $records);
            }
        });
    }

    public function test_a_cash_payment_cancelled_while_its_shift_closes_is_counted_once(): void
    {
        [$club, $owner, $shift, [$payment]] = $this->desk();

        $results = $this->concurrently([
            function (string $dir) use ($owner, $payment) {
                self::pauseAfter(self::SHIFT_READ, $dir, 1.5);

                return $this->send($owner, 'post', route('club.payments.cancel', $payment), ['reason' => 'دفعة بالخطأ']);
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

                return $this->send($owner, 'delete', route('club.expenses.destroy', $expense));
            },
            fn (string $dir) => $this->close($owner, $shift, $dir),
        ]);

        $this->assertCountUnchanged($club, $shift, $results);
    }

    public function test_a_cash_expense_changed_while_its_shift_closes_is_counted_once(): void
    {
        [$club, $owner, $shift, , $expense] = $this->desk();

        $results = $this->concurrently([
            function (string $dir) use ($owner, $expense) {
                // Slowed down once the new amount is written, before it commits: the closing runs in that moment.
                self::pauseAfter('update "expenses" set', $dir, 1.5);

                return $this->send($owner, 'put', route('club.expenses.update', $expense), [
                    'description' => 'ماء', 'amount' => '8', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash',
                ]);
            },
            fn (string $dir) => $this->close($owner, $shift, $dir),
        ]);

        $this->assertCountUnchanged($club, $shift, $results);
    }

    public function test_a_shift_closing_first_is_waited_for_and_then_the_cancel_is_refused(): void
    {
        [$club, $owner, $shift, [$payment]] = $this->desk();

        [$closing, $cancel] = $this->concurrently([
            function (string $dir) use ($owner, $shift) {
                // The closing holds its shift until the cancel is seen waiting for it.
                self::holdUntilAnotherWaits($dir, fn (string $sql) => str_contains($sql, '"gym_cash_shifts"'));
                $result = $this->send($owner, 'post', route('club.cash-shifts.close', $shift), ['counted_cash' => '65', 'close_note' => 'عدّ الدرج', 'client_key' => $this->key()]);

                return $result + ['cancel_waited' => is_file("$dir/blocked")];
            },
            function (string $dir) use ($owner, $payment) {
                self::waitFor("$dir/inside");

                return $this->send($owner, 'post', route('club.payments.cancel', $payment), ['reason' => 'دفعة بالخطأ']);
            },
        ]);

        $dump = json_encode([$closing, $cancel], JSON_UNESCAPED_UNICODE);
        $this->assertTrue($closing['value']['cancel_waited'] ?? false, 'the cancel waited for the closing: '.$dump);
        $this->assertTrue(self::refused($cancel), 'then was refused: '.$dump);
        $this->assertSame(Payment::COMPLETED, $this->asTenant($club, fn () => $payment->fresh()->status), $dump);
        $this->assertCountUnchanged($club, $shift, [$closing]);
    }

    public function test_cash_taken_out_of_the_drawer_by_another_door_while_its_shift_closes_is_counted_once(): void
    {
        // Money that leaves the drawer outside the shift's own records (a transfer to the bank, a salary, a supplier
        // paid) is counted by its entry's time: one written just before the closing and committed just after it was
        // left out of the count, and is in the closed shift's records afterwards.
        [$club, $owner, $shift] = $this->desk();
        [$drawer, $bank] = $this->asTenant($club, fn () => [app(ChartOfAccounts::class)->account('cash'), app(ChartOfAccounts::class)->account('bank')]);

        $results = $this->concurrently([
            function (string $dir) use ($owner, $drawer, $bank) {
                self::pauseAfter('insert into "gym_journal_entries"', $dir, 1.5);

                return $this->send($owner, 'post', route('club.finance.transfers.store'), [
                    'from_id' => $drawer->id, 'to_id' => $bank->id, 'amount' => '10', 'date' => today()->toDateString(), 'note' => 'إيداع', 'client_key' => $this->key(),
                ]);
            },
            fn (string $dir) => $this->close($owner, $shift, $dir),
        ]);

        $this->assertCountUnchanged($club, $shift, $results);
        $this->assertSame('10.000', $this->asTenant($club, fn () => app(FinanceReports::class)->balance($bank->fresh())), 'the transfer went through');
    }

    public function test_two_cash_payments_of_one_open_shift_cancelled_at_once_both_go_through(): void
    {
        [$club, $owner, $shift, [$first, $second]] = $this->desk(2);

        $results = $this->concurrently([
            function (string $dir) use ($owner, $first) {
                self::pauseAfter(self::SHIFT_READ, $dir, 1.0);

                return $this->send($owner, 'post', route('club.payments.cancel', $first), ['reason' => 'دفعة بالخطأ']);
            },
            function (string $dir) use ($owner, $second) {
                self::waitFor("$dir/inside");

                return $this->send($owner, 'post', route('club.payments.cancel', $second), ['reason' => 'دفعة بالخطأ']);
            },
        ]);

        $dump = json_encode($results, JSON_UNESCAPED_UNICODE);
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], $dump);
            $this->assertSame([302, [], null], [$result['value']['status'], $result['value']['errors'], $result['value']['exception']], $dump);
        }
        $this->asTenant($club, function () use ($shift, $dump) {
            $this->assertSame([Payment::CANCELLED, Payment::CANCELLED], Payment::orderBy('id')->pluck('status')->all(), $dump);
            $this->assertSame('15.000', app(CashShifts::class)->summary($shift->fresh())['expected'], '20 − 5, both cancelled: '.$dump);
        });
    }
}
