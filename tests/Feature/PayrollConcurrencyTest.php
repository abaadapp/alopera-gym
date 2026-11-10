<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollPayout;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * Two people opening, approving or paying the same month's payroll at the same moment, in separate processes on
 * PostgreSQL: one run per month, one salaries entry, one payout — and whoever comes second is told so in Arabic,
 * never a server error.
 */
class PayrollConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** Inside a task: hold right after the first statement that starts with $sql (still inside its transaction), and say so. */
    private static function holdAfter(string $sql, string $dir, string $signal): void
    {
        $held = false;
        DB::listen(function ($query) use ($sql, $dir, $signal, &$held) {
            if (! $held && str_starts_with($query->sql, $sql)) {
                $held = true;
                file_put_contents("$dir/$signal", '1');
                usleep(800_000);
            }
        });
    }

    /** @return array{status: int, errors: array<string, list<string>>, exception: ?string} */
    private function send(User $user, string $method, string $url, array $data = []): array
    {
        $response = $this->actingAs($user)->{$method}($url, $data);
        $errors = session('errors');

        return [
            'status' => $response->getStatusCode(),
            // The bag, or the array it is kept as once the session has been through the database store.
            'errors' => $errors instanceof ViewErrorBag ? $errors->getBag('default')->toArray() : ($errors['default']['messages'] ?? []),
            'exception' => $response->exception ? $response->exception::class.': '.$response->exception->getMessage() : null,
        ];
    }

    private function staffOnPayroll(Tenant $club): void
    {
        $this->asTenant($club, function () {
            Employee::create(['name' => 'سارة', 'basic_salary' => '400.000', 'allowances' => [['name' => 'مواصلات', 'amount' => '50.000']], 'status' => 'active']);
            Employee::create(['name' => 'منى', 'basic_salary' => '300.000', 'allowances' => [], 'status' => 'active']);
        });
    }

    private function assertBooksBalance(Tenant $club): void
    {
        $this->asTenant($club, fn () => $this->assertSame((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit')));
    }

    public function test_two_people_opening_the_same_month_at_once_get_one_run_and_a_clear_refusal(): void
    {
        $club = $this->club();
        $this->staffOnPayroll($club);
        $owner = $this->owner($club);
        $month = now()->format('Y-m');

        $results = $this->concurrently([
            function (string $dir) use ($owner, $month) {
                // Holds its new run uncommitted, so the other one finds no run for the month yet.
                self::holdAfter('insert into "gym_payroll_runs"', $dir, 'first');

                return $this->send($owner, 'post', route('club.payroll.store'), ['month' => $month]);
            },
            function (string $dir) use ($owner, $month) {
                self::waitFor("$dir/first");

                return $this->send($owner, 'post', route('club.payroll.store'), ['month' => $month]);
            },
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
            $this->assertSame(302, $result['value']['status'], json_encode($results, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame([], $results[0]['value']['errors'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['month' => ['توجد مسيرة لهذا الشهر بالفعل.']], $results[1]['value']['errors'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->asTenant($club, function () use ($month) {
            $this->assertSame(1, PayrollRun::where('period', $month.'-01')->count());
            $this->assertSame(2, PayrollItem::count());
        });
    }

    public function test_two_people_approving_the_same_run_at_once_post_its_salaries_once(): void
    {
        $club = $this->club();
        $this->staffOnPayroll($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $run = $this->asTenant($club, fn () => PayrollRun::firstOrFail());

        $results = $this->concurrently([
            function (string $dir) use ($owner, $run) {
                self::holdAfter('insert into "journal_entries"', $dir, 'first');

                return $this->send($owner, 'post', route('club.payroll.approve', $run));
            },
            function (string $dir) use ($owner, $run) {
                self::waitFor("$dir/first");

                return $this->send($owner, 'post', route('club.payroll.approve', $run));
            },
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
            $this->assertSame(302, $result['value']['status'], json_encode($results, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame([], $results[0]['value']['errors'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['error' => ['هذه المسيرة معتمدة مسبقًا.']], $results[1]['value']['errors'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(PayrollRun::APPROVED, $run->fresh()->status);
        $this->asTenant($club, fn () => $this->assertSame(1, JournalEntry::where('source_type', 'payroll_run')->count()));
        $this->assertBooksBalance($club);
    }

    public function test_two_people_paying_the_same_lines_at_once_pay_them_once(): void
    {
        $club = $this->club();
        $this->fundAccounts($club);
        $this->staffOnPayroll($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $run = $this->asTenant($club, fn () => PayrollRun::firstOrFail());
        $this->actingAs($owner)->post(route('club.payroll.approve', $run))->assertSessionHasNoErrors();
        [$items, $bank, $cash] = $this->asTenant($club, fn () => [
            PayrollItem::pluck('id')->all(), app(ChartOfAccounts::class)->account('bank'), app(ChartOfAccounts::class)->account('cash'),
        ]);
        $pay = fn ($account) => ['items' => $items, 'account_id' => $account->id, 'paid_on' => today()->toDateString()];

        // From two different accounts, so the account's own lock does not put one behind the other: the run's lock must.
        $results = $this->concurrently([
            function (string $dir) use ($owner, $run, $pay, $bank) {
                self::holdAfter('insert into "gym_payroll_payouts"', $dir, 'first');

                return $this->send($owner, 'post', route('club.payroll.pay', $run), $pay($bank));
            },
            function (string $dir) use ($owner, $run, $pay, $cash) {
                self::waitFor("$dir/first");

                return $this->send($owner, 'post', route('club.payroll.pay', $run), $pay($cash));
            },
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
            $this->assertSame(302, $result['value']['status'], json_encode($results, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame([], $results[0]['value']['errors'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertNotSame([], $results[1]['value']['errors'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(PayrollRun::PAID, $run->fresh()->status);
        $this->asTenant($club, function () {
            $this->assertSame(1, PayrollPayout::count());
            $this->assertSame(1, JournalEntry::where('source_type', 'payroll_payout')->count());
            $this->assertSame('750.000', PayrollPayout::firstOrFail()->total);
        });
        $this->assertBooksBalance($club);
    }
}
