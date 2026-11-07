<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\ChartOfAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Before the system runs for real, each cash box and bank account has its opening balance set by someone, a zero set
 * on purpose included: the release check and the bank accounts page name the ones nobody has set. Checking writes
 * nothing; no balance is ever made up for an account.
 */
class OpeningBalancesReviewTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_the_boxes_and_banks_whose_opening_nobody_set_are_named_until_each_is_set(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$cash, $bank] = $this->asTenant($club, fn () => [app(ChartOfAccounts::class)->account('cash'), app(ChartOfAccounts::class)->account('bank')]);
        $written = fn () => [JournalEntry::withoutGlobalScopes()->count(), DB::table('gym_journal_lines')->count()];
        $before = $written();

        $this->artisan('platform:preflight')->expectsOutputToContain('2 not set: '.$cash->name.'، '.$bank->name);
        $page = fn () => collect($this->actingAs($owner)->get(route('club.finance.banks'))->assertOk()->viewData('page')['props']['accounts'])->pluck('opening_reviewed', 'id');
        $this->assertSame([false, false], [$page()[$cash->id], $page()[$bank->id]]);
        $this->assertSame($before, $written(), 'checking writes nothing, and makes no balance up');

        // A bank with money in it, a box that is empty — both set by hand.
        $this->actingAs($owner)->post(route('club.finance.banks.opening', $bank), ['amount' => '250', 'date' => today()->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.finance.banks.opening', $cash), ['amount' => '0', 'date' => today()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame([true, true], [$page()[$cash->id], $page()[$bank->id]]);
        $this->artisan('platform:preflight')->doesntExpectOutputToContain($cash->name)->expectsOutputToContain('opening balances are reviewed | each set by hand');

        // A box added later with nothing entered for its opening is named again.
        $this->actingAs($owner)->post(route('club.finance.banks.store'), ['name' => 'صندوق الاستقبال', 'kind' => 'cash'])->assertSessionHasNoErrors();
        $this->artisan('platform:preflight')->expectsOutputToContain('1 not set: صندوق الاستقبال');
        $this->asTenant($club, fn () => $this->assertSame(0, Account::where('name', 'صندوق الاستقبال')->firstOrFail()->lines()->count(), 'nothing is posted for it'));
    }
}
