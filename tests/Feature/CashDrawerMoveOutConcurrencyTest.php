<?php

namespace Tests\Feature;

use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\CashShift;
use App\Modules\Finance\Models\CashShiftMovement;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Services\CashShifts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * Cash taken out of a drawer (to the bank) and an expense paid from the same drawer at the same moment, in separate
 * processes on PostgreSQL. Moving cash out holds the drawer's account, as every payment out of it does, so the second
 * one sees what the first spent and is refused: the drawer never goes below zero in the books or in the shift.
 */
class CashDrawerMoveOutConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** @return array{status: int, errors: array, exception: ?string} */
    private function send(User $user, string $url, array $data = []): array
    {
        $response = $this->actingAs($user)->post($url, $data);
        $errors = session('errors');

        return [
            'status' => $response->getStatusCode(),
            'errors' => $errors instanceof ViewErrorBag ? $errors->getBag('default')->toArray() : ($errors['default']['messages'] ?? []),
            'exception' => $response->exception ? $response->exception::class.': '.$response->exception->getMessage() : null,
        ];
    }

    public function test_cash_taken_to_the_bank_and_an_expense_from_the_same_drawer_at_once_cannot_spend_it_twice(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$drawer, $bank] = $this->asTenant($club, fn () => [app(ChartOfAccounts::class)->account('cash'), app(ChartOfAccounts::class)->account('bank')]);
        // The drawer holds 100.000 in the books: its opening balance, set as finance sets it.
        $this->actingAs($owner)->post(route('club.finance.banks.opening', $drawer), ['amount' => '100.000', 'date' => today()->toDateString()])->assertSessionHasNoErrors();
        $shift = $this->asTenant($club, fn () => app(CashShifts::class)->open($drawer, '100.000', $owner)); // and counted 100.000

        $results = $this->concurrently([
            function (string $dir) use ($owner, $shift, $bank) {
                $held = false;
                DB::listen(function ($query) use ($dir, &$held) {
                    if (! $held && str_contains($query->sql, 'from "gym_cash_shifts"') && str_contains($query->sql, 'for update')) {
                        $held = true;
                        file_put_contents("$dir/moving", '1');
                        usleep(1_000_000);
                    }
                });

                return $this->send($owner, route('club.cash-shifts.move', $shift), [
                    'direction' => 'out', 'amount' => '100.000', 'counter_account_id' => $bank->id, 'reason' => 'إيداع في البنك', 'client_key' => (string) Str::uuid(),
                ]);
            },
            function (string $dir) use ($owner, $drawer) {
                self::waitFor("$dir/moving");

                return $this->send($owner, route('club.expenses.store'), [
                    'description' => 'صيانة', 'amount' => '100.000', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash',
                    'account_id' => $drawer->id, 'client_key' => (string) Str::uuid(),
                ]);
            },
        ]);

        $dump = json_encode($results, JSON_UNESCAPED_UNICODE);
        $this->asTenant($club, function () use ($drawer, $shift, $dump) {
            $balance = app(FinanceReports::class)->balance($drawer->fresh());
            $expected = app(CashShifts::class)->summary(CashShift::findOrFail($shift->id))['expected'];
            $done = 'moved out: '.CashShiftMovement::count().', expenses: '.Expense::count().', drawer in the books: '.$balance.', shift expects: '.$expected.' — '.$dump;
            $this->assertGreaterThanOrEqual(0, Money::cmp($balance, '0'), $done);
            $this->assertGreaterThanOrEqual(0, Money::cmp($expected, '0'), $done);
        });
    }
}
