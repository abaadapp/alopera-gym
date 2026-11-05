<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\LedgerProjector;
use App\Modules\Finance\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Two hands on one record at once: what the books keep is what the record holds, whichever request finishes last. */
class ConcurrentEditsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_an_expense_edited_twice_at_once_is_in_the_books_with_the_amount_it_keeps(): void
    {
        $club = $this->club();
        $this->fundAccounts($club);
        $owner = $this->owner($club);
        $form = ['description' => 'ماء', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash'];
        $this->actingAs($owner)->post(route('club.expenses.store'), $form + ['amount' => '3', 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $expense = $this->asTenant($club, fn () => Expense::sole());

        // Two edits at once: the one saving 7 commits first, the one saving 5 last; 7's posting, run after its own
        // commit with the amount it saved, comes in after 5's.
        $sevens = $this->asTenant($club, fn () => tap($expense->fresh(), fn ($e) => $e->amount = '7.000'));
        $this->actingAs($owner)->put(route('club.expenses.update', $expense), $form + ['amount' => '5'])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => app(LedgerProjector::class)->project($sevens));

        $this->asTenant($club, function () use ($expense) {
            $this->assertSame('5.000', $expense->fresh()->amount);
            $live = JournalEntry::where(['source_type' => 'expense', 'source_id' => $expense->id, 'source_event' => 'record'])->whereNull('reversed_at')->pluck('total')->all();
            $this->assertSame(['5.000'], $live, 'one live posting, of the amount the expense holds');
        });
    }
}
