<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\SubmittedOnce;
use App\Core\Support\SubmittedOnceKey;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * One form, one result, whatever happens to the cache: a payment form sent again after the cache was cleared, two
 * submissions at the same moment in separate processes on PostgreSQL, a refused submission corrected and sent again
 * with the same key, and a worker stopped halfway.
 */
class SubmittedOnceTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    private function invoice(Tenant $club, string $price = '40'): Invoice
    {
        return $this->asTenant($club, fn () => app(InvoiceService::class)->issue(null, [['description' => 'خدمة', 'unit_price' => $price]], ['type' => 'other'], 'عميل'));
    }

    /** @return array{status: int, errors: list<string>} */
    private function pay(User $owner, Invoice $invoice, string $amount, string $key): array
    {
        $response = $this->actingAs($owner)->post(route('club.payments.store', $invoice), ['amount' => $amount, 'method' => 'cash', 'client_key' => $key]);
        $errors = session('errors');

        return ['status' => $response->getStatusCode(), 'errors' => $errors instanceof ViewErrorBag ? $errors->all() : []];
    }

    public function test_a_payment_form_sent_again_after_the_cache_was_cleared_is_recorded_once(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $invoice = $this->invoice($club);
        $key = (string) Str::uuid();

        $this->assertSame([], $this->pay($owner, $invoice, '10', $key)['errors']);
        Cache::flush(); // a deploy, a full cache, or a day gone by
        $this->assertSame([], $this->pay($owner, $invoice, '10', $key)['errors']);

        $this->asTenant($club, function () use ($invoice) {
            $this->assertSame(1, Payment::count(), 'one payment for one form');
            $this->assertSame('10.000', $invoice->fresh()->paid_total);
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }

    public function test_the_same_expense_form_sent_twice_at_once_is_recorded_once(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $cash = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'));
        $this->asTenant($club, fn () => app(PaymentService::class)->record($this->invoice($club, '100'), '100', 'cash'));
        $form = ['description' => 'كهرباء', 'amount' => '15', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash', 'account_id' => $cash->id, 'client_key' => (string) Str::uuid()];
        $send = fn () => $this->actingAs($owner)->post(route('club.expenses.store'), $form)->getStatusCode();

        $results = $this->concurrently([
            function (string $dir) use ($send) {
                self::pauseAfter('insert into "expenses"', $dir, 1.5);

                return $send();
            },
            function (string $dir) use ($send) {
                self::waitFor("$dir/inside");

                return $send();
            },
        ]);

        $this->assertSame([302, 302], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame(1, Expense::count(), 'one expense for one form'));
    }

    public function test_a_refused_submission_can_be_corrected_and_sent_again_with_the_same_key(): void
    {
        $club = $this->club();
        $key = (string) Str::uuid();

        $this->asTenant($club, function () use ($key) {
            try {
                SubmittedOnce::run($key, 'test', fn () => throw new BusinessRuleException('test.refused', 'مرفوض'));
                $this->fail('the refusal reaches the form');
            } catch (BusinessRuleException) {
            }
            $this->assertSame(0, SubmittedOnceKey::count(), 'a refused submission keeps no key');
            $this->assertSame(['R-1', true], SubmittedOnce::run($key, 'test', fn () => ['R-1', true]));
            $this->assertSame(['R-1', true], SubmittedOnce::run($key, 'test', fn () => ['R-2', false]), 'then its result is given back');
        });
    }

    public function test_a_worker_stopped_halfway_leaves_no_key_and_the_retry_records_once(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $invoice = $this->invoice($club);
        $key = (string) Str::uuid();

        $results = $this->concurrently([
            function () use ($owner, $invoice, $key) {
                DB::listen(function ($query) {
                    if (str_starts_with($query->sql, 'insert into "payments"')) {
                        posix_kill(getmypid(), SIGKILL); // the worker dies inside the transaction
                    }
                });

                return $this->pay($owner, $invoice, '10', $key);
            },
        ]);
        $this->assertFalse($results[0]['ok']);
        $this->asTenant($club, fn () => $this->assertSame([0, 0], [Payment::count(), SubmittedOnceKey::count()]));

        $this->assertSame([], $this->pay($owner, $invoice, '10', $key)['errors']);
        $this->assertSame([], $this->pay($owner, $invoice, '10', $key)['errors']);
        $this->asTenant($club, fn () => $this->assertSame(1, Payment::count()));
    }

    public function test_keys_are_kept_per_club_and_pruned_after_thirty_days(): void
    {
        [$a, $b] = [$this->club('A'), $this->club('B')];
        $key = (string) Str::uuid();
        $this->assertSame('a', $this->asTenant($a, fn () => SubmittedOnce::run($key, 'test', fn () => 'a')));
        $this->assertSame('b', $this->asTenant($b, fn () => SubmittedOnce::run($key, 'test', fn () => 'b')), 'the same key in another club is its own');
        $this->assertSame('a', $this->asTenant($a, fn () => SubmittedOnce::run($key, 'test', fn () => 'again')));

        $this->asTenant($a, fn () => SubmittedOnceKey::query()->update(['created_at' => now()->subDays(31)]));
        Artisan::call('model:prune', ['--model' => [SubmittedOnceKey::class]]);
        $this->assertSame(1, DB::table('submitted_once_keys')->count());
    }
}
