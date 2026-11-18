<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\Models\Expense;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The expenses list marks each expense of a closed period as locked (not editable or deletable there) with one read of
 * the last closed day for the whole page, not one per row: the same number of queries for 1, 30 or 100 expenses.
 * The marks, who may open the list and which club's expenses it shows are as before.
 */
class ExpensesListQueriesTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** A club with $count expenses, half in October (closed) and half in November (open); 5 Nov 2026. */
    private function clubWith(int $count): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00'));
        $club = $this->club('Club '.$count);
        $owner = $this->owner($club);
        $this->asTenant($club, function () use ($count) {
            for ($i = 1; $i <= $count; $i++) {
                $day = $i % 2 ? '2026-10-'.str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT) : '2026-11-0'.(1 + $i % 4);
                Expense::create(['number' => 'EX-'.$i, 'description' => 'مصروف '.$i, 'amount' => 2, 'expense_date' => $day, 'payment_method' => 'cash']);
            }
        });
        $this->actingAs($owner)->post(route('club.finance.periods.close'), ['month' => '2026-10', 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return [$club, $owner];
    }

    /** @return array{0: int, 1: list<array>} the queries of one request of the list, and its rows */
    private function list(User $user): array
    {
        // Once first, so what a process loads only once (on its first request) is not counted for one club only.
        $this->actingAs($user)->get(route('club.expenses.index', ['per_page' => 100]))->assertOk();
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $response = $this->actingAs($user)->get(route('club.expenses.index', ['per_page' => 100]))->assertOk();
        app('events')->forget('Illuminate\Database\Events\QueryExecuted');

        return [$queries, $response->viewData('page')['props']['expenses']['data']];
    }

    public function test_the_list_takes_the_same_queries_for_1_30_and_100_expenses_and_marks_the_closed_ones(): void
    {
        // All three clubs first, so each list is measured with the same clubs in the database.
        $owners = collect([1, 30, 100])->mapWithKeys(fn (int $count) => [$count => $this->clubWith($count)[1]]);
        $counts = [];
        foreach ($owners as $count => $owner) {
            [$queries, $rows] = $this->list($owner);
            $counts[$count] = $queries;

            $this->assertCount($count, $rows);
            foreach ($rows as $row) {
                $this->assertSame($row['expense_date'] <= '2026-10-31', $row['locked'], json_encode($row, JSON_UNESCAPED_UNICODE));
            }
            $this->assertSame(intdiv($count + 1, 2), collect($rows)->where('locked', true)->count(), 'the October expenses');
        }

        $this->assertSame($counts[1], $counts[30], json_encode($counts));
        $this->assertSame($counts[1], $counts[100], json_encode($counts));
    }

    public function test_one_clubs_closed_month_does_not_lock_another_clubs_expenses_and_the_list_keeps_its_permission(): void
    {
        [$closed] = $this->clubWith(4);
        $open = $this->club('Open');
        $owner = $this->owner($open);
        $this->asTenant($open, fn () => Expense::create(['number' => 'EX-1', 'description' => 'كهرباء', 'amount' => 3, 'expense_date' => '2026-10-10', 'payment_method' => 'cash']));

        [, $rows] = $this->list($owner);
        $this->assertSame([['كهرباء', false]], collect($rows)->map(fn ($r) => [$r['description'], $r['locked']])->all(), 'its own expense only, and open');

        $this->actingAs($this->staff($closed, 'trainer'))->get(route('club.expenses.index'))->assertForbidden();
    }
}
