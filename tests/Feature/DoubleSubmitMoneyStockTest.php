<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A form sent twice (a double click, a retried request) with the key it made when it opened does its work once, as
 * payments and transfers already do: a bank account with its opening balance, a stock write-off, an employee's file.
 * Without the key (an older page) each send still does its work, as before.
 */
class DoubleSubmitMoneyStockTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_a_bank_account_sent_twice_is_one_account_with_one_opening_balance(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $form = ['name' => 'Bank Muscat', 'kind' => 'bank', 'opening_balance' => 1000, 'opening_date' => today()->toDateString(), 'client_key' => 'acc-0f3c9a7e'];

        $this->actingAs($owner)->post(route('club.finance.banks.store'), $form)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.finance.banks.store'), $form)->assertSessionHasNoErrors();

        $this->asTenant($club, function () {
            $accounts = Account::where('name', 'Bank Muscat')->get();
            $this->assertCount(1, $accounts, 'one account');
            $this->assertSame(1, JournalEntry::where('source_type', 'account')->count(), 'one opening balance');
            $this->assertSame((string) $accounts->first()->id, (string) JournalEntry::where('source_type', 'account')->value('source_id'));
        });
    }

    public function test_a_stock_write_off_sent_twice_takes_the_stock_once(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$main, $water] = $this->asTenant($club, function () {
            $main = Branch::where('is_main', true)->firstOrFail();
            $water = Product::create(['name' => 'Water', 'price' => 0.5, 'track_stock' => true]);
            app(StockService::class)->move($water, 10, 'adjustment', null, 'opening', 0.2, branchId: $main->id);

            return [$main, $water];
        });
        $form = ['product_id' => $water->id, 'branch_id' => $main->id, 'direction' => 'out', 'quantity' => 3, 'reason' => 'damaged', 'client_key' => 'adj-5b1e22d4'];

        $this->actingAs($owner)->post(route('club.inventory.adjust'), $form)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.inventory.adjust'), $form)->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($water, $main) {
            $this->assertSame('7.000', app(StockService::class)->quantity($water, $main->id), '3 written off, not 6');
            $this->assertSame(1, StockMovement::where('product_id', $water->id)->where('quantity', '<', 0)->count());
        });

        // A new write-off (a new key) is its own.
        $this->actingAs($owner)->post(route('club.inventory.adjust'), ['client_key' => 'adj-77aa01'] + $form)->assertSessionHasNoErrors();
        $this->assertSame('4.000', $this->asTenant($club, fn () => app(StockService::class)->quantity($water, $main->id)));
    }

    public function test_an_employee_sent_twice_is_one_file_so_one_salary_in_the_next_payroll(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $form = ['name' => 'منى', 'status' => 'active', 'basic_salary' => '300', 'payment_method' => 'cash', 'client_key' => 'emp-9c2d11'];

        $this->actingAs($owner)->post(route('club.employees.store'), $form)->assertSessionHasNoErrors();
        $second = $this->actingAs($owner)->post(route('club.employees.store'), $form)->assertSessionHasNoErrors();

        $employees = $this->asTenant($club, fn () => Employee::where('name', 'منى')->get());
        $this->assertCount(1, $employees);
        $second->assertRedirect(route('club.employees.show', $employees->first()));
    }

    public function test_without_a_key_each_send_still_does_its_work(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        foreach ([1, 2] as $i) {
            $this->actingAs($owner)->post(route('club.employees.store'), ['name' => 'سالم', 'status' => 'active', 'basic_salary' => '200', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        }
        $this->assertSame(2, $this->asTenant($club, fn () => Employee::where('name', 'سالم')->count()));
    }
}
