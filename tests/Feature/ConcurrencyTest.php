<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSession;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Gym\Models\TrainerAvailability;
use App\Modules\Gym\Services\PtService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PurchaseService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * Two requests at once, in separate processes on PostgreSQL (RunsConcurrently): the same basket sent twice, one trainer
 * or one member booked twice for the same hour, two payments out of the same cash box. Requests are slowed down inside
 * their critical section to make the overlap certain; a lock outliving its time is simulated by marking it expired,
 * which is what thirty seconds would do.
 */
class ConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    // ── 1. One basket, one sale ─────────────────────────────────────────────────────────────────────────────

    /** @return array{0: User, 1: array<string, mixed>, 2: Product} a cashier, a basket of everything, the product (10 in stock) */
    private function basket(Tenant $club, string $key): array
    {
        $plan = $this->plan($club, ['price' => 30]);
        $member = $this->member($club);
        [$bar, $package] = $this->asTenant($club, function () {
            $bar = Product::create(['name' => 'Bar', 'price' => 1]);
            $purchases = app(PurchaseService::class);
            $order = $purchases->approve($purchases->create(Supplier::create(['name' => 'S']), [['product_id' => $bar->id, 'quantity' => 10, 'unit_cost' => 0.5]]));
            $purchases->receive($order, [$order->items()->value('id') => ['quantity' => 10]], [], approveNow: true);

            return [$bar, PtPackage::create(['name' => 'PT', 'sessions_count' => 4, 'session_minutes' => 60, 'price' => 40, 'validity_days' => 60])];
        });

        return [$this->staff($club, 'cashier'), [
            'items' => [['product_id' => $bar->id, 'quantity' => 2]],
            'memberships' => [['plan_id' => $plan->id]],
            'packages' => [['package_id' => $package->id]],
            'member_id' => $member->id, 'payment_method' => 'cash', 'client_key' => $key,
        ], $bar];
    }

    /** @return array{status: int, errors: list<string>, documents: mixed} */
    private function checkout(User $cashier, array $basket): array
    {
        $response = $this->actingAs($cashier)->post(route('club.pos.checkout'), $basket);

        return ['status' => $response->getStatusCode(), 'errors' => self::errors(), 'documents' => session('pos_result')['documents'] ?? null];
    }

    /** @return list<string> the messages the request was refused with */
    private static function errors(): array
    {
        $errors = session('errors');

        return $errors instanceof ViewErrorBag ? $errors->all() : array_values(array_merge(...array_values(array_map('array_values', (array) $errors))) ?: []);
    }

    private function assertSoldOnce(Tenant $club, Product $bar): void
    {
        $this->asTenant($club, function () use ($bar) {
            $this->assertSame([1, 1, 1], [Sale::count(), Membership::count(), PtSubscription::count()], 'sales, memberships, packages');
            $this->assertSame(3, Invoice::count(), 'invoices: the sale, the membership, the package');
            $this->assertSame(3, Payment::count(), 'payments');
            $this->assertSame('8.000', $bar->fresh()->stock_quantity, 'stock taken once');
            $this->assertSame(Membership::ACTIVE, Membership::sole()->status);
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'), 'the books balance');
        });
    }

    public function test_the_same_basket_sent_twice_at_once_is_sold_once(): void
    {
        $club = $this->club();
        [$cashier, $basket, $bar] = $this->basket($club, 'basket-at-once');

        $results = $this->concurrently([
            function (string $dir) use ($cashier, $basket) {
                self::pauseAfter('insert into "gym_memberships"', $dir, 1.5);

                return $this->checkout($cashier, $basket);
            },
            fn () => $this->checkout($cashier, $basket),
        ]);

        $this->assertSame([302, 302], array_column(array_column($results, 'value'), 'status'), json_encode($results));
        $this->assertSoldOnce($club, $bar);
        $this->assertSame($results[0]['value']['documents'], $results[1]['value']['documents'], 'the second request gets the first one\'s documents');
    }

    public function test_a_checkout_outlasting_its_lock_does_not_sell_the_basket_twice(): void
    {
        $club = $this->club();
        [$cashier, $basket, $bar] = $this->basket($club, 'basket-slow');

        $results = $this->concurrently([
            function (string $dir) use ($cashier, $basket) {
                self::pauseAfter('insert into "gym_memberships"', $dir, 2.5);

                return $this->checkout($cashier, $basket);
            },
            function (string $dir) use ($cashier, $basket) {
                self::waitFor("$dir/inside");
                DB::table('cache_locks')->update(['expiration' => time() - 1]); // the first request has run past 30 seconds

                return $this->checkout($cashier, $basket);
            },
        ]);

        $this->assertSame([302, 302], array_column(array_column($results, 'value'), 'status'), json_encode($results));
        $this->assertSoldOnce($club, $bar);
    }

    // ── 2. One trainer, one member, one hour ────────────────────────────────────────────────────────────────

    /** @return list<int> active packages of the given members with the trainer, ready to be booked */
    private function packages(Tenant $club, Trainer $trainer, array $members): array
    {
        return $this->asTenant($club, function () use ($trainer, $members) {
            $package = PtPackage::create(['name' => 'PT', 'sessions_count' => 4, 'session_minutes' => 60, 'price' => 40, 'validity_days' => 60]);

            return array_map(function (Member $member) use ($trainer, $package) {
                $subscription = app(PtService::class)->sell($member, $package, $trainer);
                app(PaymentService::class)->record($subscription->invoice()->first(), $subscription->price, 'cash');

                return $subscription->id;
            }, $members);
        });
    }

    private function trainer(Tenant $club, string $name = 'Coach'): Trainer
    {
        return $this->asTenant($club, function () use ($name) {
            $trainer = Trainer::create(['name' => $name]);
            foreach (range(0, 6) as $day) {
                TrainerAvailability::create(['trainer_id' => $trainer->id, 'weekday' => $day, 'starts_at' => '06:00', 'ends_at' => '23:00']);
            }

            return $trainer;
        });
    }

    /** Book each package for the same hour at once; every request stops right after its clash check. */
    private function bookAtOnce(Tenant $club, array $subscriptionIds, CarbonImmutable $at): array
    {
        return $this->concurrently(array_map(fn (int $id) => function () use ($club, $id, $at) {
            DB::listen(function ($q) {
                if (str_starts_with($q->sql, 'select exists(select * from "gym_pt_sessions"')) {
                    usleep(400_000);
                }
            });

            return $this->asTenant($club, fn () => app(PtService::class)->schedule(PtSubscription::findOrFail($id), $at)->id);
        }, $subscriptionIds));
    }

    public function test_one_trainer_is_not_booked_twice_for_the_same_hour_by_members_booking_at_once(): void
    {
        $club = $this->club();
        $trainer = $this->trainer($club);
        $ids = $this->packages($club, $trainer, [$this->member($club), $this->member($club), $this->member($club)]);
        $at = CarbonImmutable::tomorrow()->setTime(10, 0);

        $results = $this->bookAtOnce($club, $ids, $at);

        $this->assertSame(1, $this->asTenant($club, fn () => PtSession::where('trainer_id', $trainer->id)->count()), json_encode($results));
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['ok'])));
        foreach (array_filter($results, fn ($r) => ! $r['ok']) as $refused) {
            $this->assertStringContainsString('المدرب لديه موعد آخر', (string) $refused['error']);
        }
    }

    public function test_one_member_is_not_booked_twice_for_the_same_hour_with_two_trainers_at_once(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        [$first] = $this->packages($club, $this->trainer($club, 'A'), [$member]);
        [$second] = $this->packages($club, $this->trainer($club, 'B'), [$member]);

        $results = $this->bookAtOnce($club, [$first, $second], CarbonImmutable::tomorrow()->setTime(10, 0));

        $this->assertSame(1, $this->asTenant($club, fn () => PtSession::where('member_id', $member->id)->count()), json_encode($results));
        $this->assertStringContainsString('لدى العضو جلسة أخرى', (string) collect($results)->firstWhere('ok', false)['error']);
    }

    public function test_one_package_is_not_booked_beyond_its_sessions_at_once(): void
    {
        $club = $this->club();
        $trainer = $this->trainer($club);
        [$id] = $this->packages($club, $trainer, [$this->member($club)]);
        $this->asTenant($club, fn () => PtSubscription::whereKey($id)->update(['sessions_total' => 1]));

        $results = $this->concurrently([
            fn () => $this->asTenant($club, fn () => app(PtService::class)->schedule(PtSubscription::findOrFail($id), CarbonImmutable::tomorrow()->setTime(9, 0))->id),
            fn () => $this->asTenant($club, fn () => app(PtService::class)->schedule(PtSubscription::findOrFail($id), CarbonImmutable::tomorrow()->setTime(15, 0))->id),
        ]);

        $this->assertSame(1, $this->asTenant($club, fn () => PtSession::where('pt_subscription_id', $id)->count()), json_encode($results));
    }

    // ── 3. Money out of an account ──────────────────────────────────────────────────────────────────────────

    private function cashBox(Tenant $club, string $amount): Account
    {
        return $this->asTenant($club, function () use ($amount) {
            $chart = app(ChartOfAccounts::class);
            $cash = $chart->account('cash');
            app(Ledger::class)->post(['date' => today()->toDateString(), 'description' => 'رصيد'], [
                ['account' => $cash, 'debit' => $amount], ['account' => $chart->account('opening_equity'), 'credit' => $amount],
            ]);

            return $cash;
        });
    }

    /** @return array{status: int, errors: list<string>} */
    private function pay(User $owner, Account $cash, string $amount): array
    {
        $response = $this->actingAs($owner)->post(route('club.expenses.store'), [
            'description' => 'كهرباء', 'amount' => $amount, 'expense_date' => today()->toDateString(), 'payment_method' => 'cash', 'account_id' => $cash->id,
        ]);

        return ['status' => $response->getStatusCode(), 'errors' => self::errors()];
    }

    /** @return array{0: int, 1: string} expenses on record and the box's balance */
    private function box(Tenant $club, Account $cash): array
    {
        return $this->asTenant($club, fn () => [Expense::count(), app(FinanceReports::class)->balance($cash)]);
    }

    public function test_two_payments_at_once_cannot_both_spend_the_same_cash(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $cash = $this->cashBox($club, '100');

        $results = $this->concurrently([
            function (string $dir) use ($owner, $cash) {
                self::pauseAfter('insert into "expenses"', $dir, 1.0);

                return $this->pay($owner, $cash, '60');
            },
            fn () => $this->pay($owner, $cash, '60'),
        ]);

        $this->assertSame([1, '40.000'], $this->box($club, $cash), json_encode($results));
        $this->assertCount(1, array_filter(array_column($results, 'value'), fn ($r) => $r['errors'] !== []), 'one of the two is refused');
    }

    public function test_a_payment_outlasting_the_lock_still_holds_the_cash_box(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $cash = $this->cashBox($club, '100');

        $results = $this->concurrently([
            function (string $dir) use ($owner, $cash) {
                self::pauseAfter('insert into "expenses"', $dir, 2.5);

                return $this->pay($owner, $cash, '60');
            },
            function (string $dir) use ($owner, $cash) {
                self::waitFor("$dir/inside");
                DB::table('cache_locks')->update(['expiration' => time() - 1]); // the first payment has run past 30 seconds

                return $this->pay($owner, $cash, '60');
            },
        ]);

        $this->assertSame([1, '40.000'], $this->box($club, $cash), 'never below zero: '.json_encode($results));
    }

    public function test_a_payment_that_fails_halfway_leaves_nothing_and_frees_the_cash_box(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $cash = $this->cashBox($club, '100');

        $results = $this->concurrently([
            function (string $dir) use ($owner, $cash) {
                DB::listen(function ($q) use ($dir) {
                    if (str_starts_with($q->sql, 'insert into "expenses"') && ! is_file("$dir/inside")) {
                        file_put_contents("$dir/inside", '1');
                        usleep(500_000);
                        throw new RuntimeException('the request broke halfway');
                    }
                });

                return $this->pay($owner, $cash, '60');
            },
            function (string $dir) use ($owner, $cash) {
                self::waitFor("$dir/inside");

                return $this->pay($owner, $cash, '60');
            },
        ]);

        $this->assertSame(500, $results[0]['value']['status'] ?? null, json_encode($results));
        $this->assertSame([1, '40.000'], $this->box($club, $cash), 'only the second payment, whole: '.json_encode($results));
        $this->assertSame([], $results[1]['value']['errors']);
    }

    public function test_a_worker_killed_while_paying_leaves_nothing_and_frees_the_cash_box(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $cash = $this->cashBox($club, '100');

        $results = $this->concurrently([
            function (string $dir) use ($owner, $cash) {
                self::pauseAfter('insert into "expenses"', $dir, 20);

                return $this->pay($owner, $cash, '60');
            },
            function (string $dir) use ($owner, $cash) {
                self::waitFor("$dir/inside");
                posix_kill((int) file_get_contents("$dir/inside"), SIGKILL); // the worker dies inside the payment
                usleep(300_000);

                return $this->pay($owner, $cash, '60');
            },
        ]);

        $this->assertSame('killed before it finished', $results[0]['error']);
        $this->assertSame([], $results[1]['value']['errors'] ?? ['no answer'], json_encode($results));
        $this->assertSame([1, '40.000'], $this->box($club, $cash));
    }

    // ── 4. More at once, and failures in the middle ─────────────────────────────────────────────────────────

    public function test_ten_requests_with_the_same_basket_at_once_sell_it_once(): void
    {
        $club = $this->club();
        [$cashier, $basket, $bar] = $this->basket($club, 'basket-ten');

        $results = $this->concurrently(array_fill(0, 10, fn () => $this->checkout($cashier, $basket)));

        $this->assertSame(array_fill(0, 10, 302), array_column(array_column($results, 'value'), 'status'), json_encode($results));
        $this->assertSame(array_fill(0, 10, []), array_column(array_column($results, 'value'), 'errors'));
        $this->assertSoldOnce($club, $bar);
        $this->assertCount(1, array_unique(array_map('json_encode', array_column(array_column($results, 'value'), 'documents'))), 'every request gets the one sale');
    }

    public function test_a_checkout_killed_halfway_sells_nothing_and_its_retry_sells_once(): void
    {
        $club = $this->club();
        [$cashier, $basket, $bar] = $this->basket($club, 'basket-killed');

        $results = $this->concurrently([
            function (string $dir) use ($cashier, $basket) {
                self::pauseAfter('insert into "gym_memberships"', $dir, 20); // the sale and its key are written, not committed

                return $this->checkout($cashier, $basket);
            },
            function (string $dir) use ($cashier, $basket) {
                self::waitFor("$dir/inside");
                posix_kill((int) file_get_contents("$dir/inside"), SIGKILL); // the worker and its connection die mid-sale
                usleep(300_000);

                return $this->checkout($cashier, $basket); // the till sends the basket again
            },
        ]);

        $this->assertSame('killed before it finished', $results[0]['error']);
        $this->assertSame([302, []], [$results[1]['value']['status'] ?? null, $results[1]['value']['errors'] ?? ['no answer']], json_encode($results));
        $this->assertSoldOnce($club, $bar);
    }

    public function test_bookings_that_overlap_by_half_an_hour_at_once_are_not_both_made(): void
    {
        $club = $this->club();
        $trainer = $this->trainer($club);
        [$a, $b] = $this->packages($club, $trainer, [$this->member($club), $this->member($club)]);
        $ten = CarbonImmutable::tomorrow()->setTime(10, 0);

        $results = $this->concurrently([
            fn () => $this->asTenant($club, fn () => app(PtService::class)->schedule(PtSubscription::findOrFail($a), $ten)->id),
            fn () => $this->asTenant($club, fn () => app(PtService::class)->schedule(PtSubscription::findOrFail($b), $ten->addMinutes(30))->id),
        ]);

        $this->assertSame(1, $this->asTenant($club, fn () => PtSession::where('trainer_id', $trainer->id)->count()), json_encode($results));
        $this->assertStringContainsString('المدرب لديه موعد آخر', (string) collect($results)->firstWhere('ok', false)['error']);
    }

    public function test_a_booking_killed_halfway_leaves_no_session_and_frees_the_trainer(): void
    {
        $club = $this->club();
        $trainer = $this->trainer($club);
        [$a, $b] = $this->packages($club, $trainer, [$this->member($club), $this->member($club)]);
        $ten = CarbonImmutable::tomorrow()->setTime(10, 0);

        $results = $this->concurrently([
            function (string $dir) use ($club, $a, $ten) {
                self::pauseAfter('insert into "gym_pt_sessions"', $dir, 20);

                return $this->asTenant($club, fn () => app(PtService::class)->schedule(PtSubscription::findOrFail($a), $ten)->id);
            },
            function (string $dir) use ($club, $b, $ten) {
                self::waitFor("$dir/inside");
                posix_kill((int) file_get_contents("$dir/inside"), SIGKILL);
                usleep(300_000);

                return $this->asTenant($club, fn () => app(PtService::class)->schedule(PtSubscription::findOrFail($b), $ten)->id);
            },
        ]);

        $this->assertSame('killed before it finished', $results[0]['error']);
        $this->assertTrue($results[1]['ok'], json_encode($results));
        $this->asTenant($club, function () use ($a, $b) {
            $this->assertSame([0, 1], [PtSession::where('pt_subscription_id', $a)->count(), PtSession::where('pt_subscription_id', $b)->count()]);
        });
    }

    public function test_ten_payments_at_once_spend_no_more_than_the_box_holds(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $cash = $this->cashBox($club, '100');

        $results = $this->concurrently(array_fill(0, 10, fn () => $this->pay($owner, $cash, '15')));

        $this->assertSame(array_fill(0, 10, 302), array_column(array_column($results, 'value'), 'status'), 'refused, never a server error: '.json_encode($results));
        $this->assertCount(4, array_filter(array_column($results, 'value'), fn ($r) => $r['errors'] !== []), 'six fit in 100, four are refused');
        $this->assertSame([6, '10.000'], $this->box($club, $cash));
        $this->asTenant($club, fn () => $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'), 'the books balance'));
    }

    public function test_two_clubs_paying_at_once_do_not_hold_each_other(): void
    {
        [$one, $two] = [$this->club(), $this->club()];
        [$ownerOne, $ownerTwo] = [$this->owner($one), $this->owner($two)];
        [$cashOne, $cashTwo] = [$this->cashBox($one, '100'), $this->cashBox($two, '100')];

        $started = microtime(true);
        $results = $this->concurrently([
            function (string $dir) use ($ownerOne, $cashOne) {
                self::pauseAfter('insert into "expenses"', $dir, 2.0);

                return $this->pay($ownerOne, $cashOne, '60');
            },
            function (string $dir) use ($ownerTwo, $cashTwo) {
                self::waitFor("$dir/inside");
                $t = microtime(true);
                $answer = $this->pay($ownerTwo, $cashTwo, '60');

                return $answer + ['seconds' => microtime(true) - $t];
            },
        ]);

        $this->assertSame([[], []], [$results[0]['value']['errors'] ?? ['?'], $results[1]['value']['errors'] ?? ['?']], json_encode($results));
        $this->assertLessThan(1.5, $results[1]['value']['seconds'], 'the second club did not wait for the first club\'s box');
        $this->assertSame([[1, '40.000'], [1, '40.000']], [$this->box($one, $cashOne), $this->box($two, $cashTwo)]);
        $this->assertLessThan(10, microtime(true) - $started);
    }
}
