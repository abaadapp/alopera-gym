<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * A refund made as a credit note is issued (or as a paid website order is cancelled) and a pending bank refund on the
 * same invoice confirmed at the same moment, in separate processes on PostgreSQL. Both take the refund account's lock
 * before the invoice row, so one waits for the other; before, the note took the invoice first and the confirmation the
 * account first, and both waited 15 seconds until the note failed (LockTimeoutException, a server error).
 */
class CreditNoteRefundLockOrderTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** @return array{status: int, errors: array, exception: ?string, seconds: float} */
    private function send(User $user, string $url, array $data = []): array
    {
        $t = microtime(true);
        $response = $this->actingAs($user)->post($url, $data);
        $errors = session('errors');

        return [
            'status' => $response->getStatusCode(),
            'errors' => $errors instanceof ViewErrorBag ? $errors->getBag('default')->toArray() : ($errors['default']['messages'] ?? []),
            'exception' => $response->exception ? $response->exception::class.': '.$response->exception->getMessage() : null,
            'seconds' => round(microtime(true) - $t, 1),
        ];
    }

    public function test_credit_note_refunded_now_while_a_pending_bank_refund_on_the_same_invoice_is_confirmed(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->fundAccounts($club);
        $member = $this->member($club);

        [$invoice, $pending] = $this->asTenant($club, function () use ($member) {
            $invoice = app(InvoiceService::class)->issue($member, [['description' => 'خدمة', 'unit_price' => '50.000']], ['type' => 'other']);
            app(PaymentService::class)->record($invoice, '50.000', 'cash');
            // A first note of 20.500 whose refund by bank transfer waits for finance to confirm it.
            $first = app(CreditNoteService::class)->issue($invoice->fresh(), '20.500', 'خصم', ['method' => 'bank_transfer']);
            $pending = Payment::where('credit_note_id', $first->id)->sole();
            $this->assertSame(Payment::PENDING, $pending->status);

            return [$invoice->fresh(), $pending];
        });

        $results = $this->concurrently([
            // Finance confirms the pending bank refund: takes the bank's advisory lock, then the refund row, held there.
            function (string $dir) use ($owner, $pending) {
                $held = false;
                DB::listen(function ($query) use ($dir, &$held) {
                    if (! $held && str_contains($query->sql, 'from "payments"') && str_contains($query->sql, 'for update')) {
                        $held = true;
                        file_put_contents("$dir/confirming", '1');
                        usleep(1_000_000);
                    }
                });

                return $this->send($owner, route('club.payments.confirm', $pending), ['reference' => 'BANK-RF-1']);
            },
            // Meanwhile a second note on the same invoice, refunded now by bank transfer.
            function (string $dir) use ($owner, $invoice) {
                self::waitFor("$dir/confirming");

                return $this->send($owner, route('club.credit-notes.store', $invoice), [
                    'amount' => '10.250', 'reason' => 'خصم ثانٍ', 'refund_now' => true, 'method' => 'bank_transfer',
                    'client_key' => (string) Str::uuid(),
                ]);
            },
        ]);

        $dump = json_encode($results, JSON_UNESCAPED_UNICODE);
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], $dump);
            $this->assertNull($result['value']['exception'], $dump);
            $this->assertSame(302, $result['value']['status'], $dump);
            $this->assertLessThan(10, $result['value']['seconds'], $dump);
        }
        $this->asTenant($club, function () use ($dump) {
            $this->assertSame(2, CreditNote::count(), $dump);
            $this->assertSame((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit'));
        });
    }

    public function test_a_paid_website_order_cancelled_with_a_refund_while_a_pending_bank_refund_on_its_invoice_is_confirmed(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->fundAccounts($club);

        [$sale, $pending] = $this->asTenant($club, function () {
            $bar = Product::create(['name' => 'Bar', 'price' => 20, 'show_on_website' => true]);
            app(StockService::class)->move($bar, 10, 'adjustment', null, 'opening', 0.8);
            $sale = app(OrderService::class)->place([['product_id' => $bar->id, 'quantity' => 1]], ['name' => 'Online', 'phone' => '96891234567'], 'pickup');
            $invoice = Invoice::findOrFail($sale->invoice_id);
            app(PaymentService::class)->record($invoice, $invoice->total, 'cash');
            // Part of it given back by bank transfer, waiting for finance to confirm the transfer was made.
            $first = app(CreditNoteService::class)->issue($invoice->fresh(), '5.000', 'خصم', ['method' => 'bank_transfer']);
            $pending = Payment::where('credit_note_id', $first->id)->sole();
            $this->assertSame(Payment::PENDING, $pending->status);

            return [$sale->fresh(), $pending];
        });

        $results = $this->concurrently([
            function (string $dir) use ($owner, $pending) {
                $held = false;
                DB::listen(function ($query) use ($dir, &$held) {
                    if (! $held && str_contains($query->sql, 'from "payments"') && str_contains($query->sql, 'for update')) {
                        $held = true;
                        file_put_contents("$dir/confirming", '1');
                        usleep(1_000_000);
                    }
                });

                return $this->send($owner, route('club.payments.confirm', $pending), ['reference' => 'BANK-RF-2']);
            },
            function (string $dir) use ($owner, $sale) {
                self::waitFor("$dir/confirming");

                return $this->send($owner, route('club.sales.orders.cancel', $sale), ['reason' => 'نفد المخزون', 'refund_method' => 'bank_transfer']);
            },
        ]);

        $dump = json_encode($results, JSON_UNESCAPED_UNICODE);
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], $dump);
            $this->assertNull($result['value']['exception'], $dump);
            $this->assertSame(302, $result['value']['status'], $dump);
            $this->assertLessThan(10, $result['value']['seconds'], $dump);
        }
        $this->asTenant($club, function () use ($sale, $dump) {
            $this->assertSame(Sale::CANCELLED, $sale->fresh()->status, $dump);
            $this->assertSame((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit'));
        });
    }
}
