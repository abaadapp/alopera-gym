<?php

namespace Tests\Feature;

use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentAllocation;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * A customer's credit (a transfer that paid more than its invoice still owed) is being paid back by bank transfer, and
 * finance confirms that refund at the moment someone else uses the same credit — applies it to the invoice, cancels the
 * payment, pays the rest back in cash — or refunds a credit note on that invoice. Separate processes on PostgreSQL, the
 * confirmation held right after it locked the invoice: each waits for the other instead of the two deadlocking
 * (SQLSTATE 40P01), the credit is never spent twice, and the books balance.
 */
class CreditLockOrderTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** Inside a task: hold right after the first row lock taken on an invoice, and say so. */
    private static function holdAfterLockingTheInvoice(string $dir, string $signal): void
    {
        $held = false;
        DB::listen(function ($query) use ($dir, $signal, &$held) {
            if (! $held && str_contains($query->sql, 'from "invoices"') && str_contains($query->sql, 'for update')) {
                $held = true;
                file_put_contents("$dir/$signal", '1');
                usleep(1_000_000);
            }
        });
    }

    /** @return array{status: int, errors: array<string, list<string>>, exception: ?string} */
    private function send(User $user, string $url, array $data = []): array
    {
        $response = $this->actingAs($user)->post($url, $data);
        $errors = session('errors');

        return [
            'status' => $response->getStatusCode(),
            // The bag, or the array it is kept as once the session has been through the database store.
            'errors' => $errors instanceof ViewErrorBag ? $errors->getBag('default')->toArray() : ($errors['default']['messages'] ?? []),
            'exception' => $response->exception ? $response->exception::class.': '.$response->exception->getMessage() : null,
        ];
    }

    /**
     * Invoice of 50: a transfer of 50 waits for confirmation, 30 is paid in cash meanwhile, the transfer is confirmed —
     * 20 settles the invoice, 30 is the customer's credit — and 20 of that credit is to be paid back by bank transfer.
     * With $cashWasAMistake, the cash is cancelled before that (entered by mistake): the invoice owes 30 again, and the
     * transfer is its only payment.
     *
     * @return array{invoice: Invoice, credit: Payment, refund: Payment}
     */
    private function creditBeingPaidBack(Tenant $club, bool $cashWasAMistake = false): array
    {
        $this->fundAccounts($club);
        $member = $this->member($club);

        return $this->asTenant($club, function () use ($member, $cashWasAMistake) {
            $payments = app(PaymentService::class);
            $invoice = app(InvoiceService::class)->issue($member, [['description' => 'خدمة', 'unit_price' => '50']], ['type' => 'other']);
            $credit = $payments->record($invoice, '50', 'bank_transfer');
            $cash = $payments->record($invoice->fresh(), '30', 'cash');
            $payments->confirm($credit);
            if ($cashWasAMistake) {
                $payments->cancel($cash->fresh(), 'دفعة نقدية سُجّلت خطأً');
            }
            $refund = $payments->refundCredit($credit->fresh(), '20', 'bank_transfer');
            $this->assertSame(['30.000', '10.000', Payment::PENDING], [$credit->fresh()->credit_amount, $credit->fresh()->creditLeft(), $refund->status]);

            return ['invoice' => $invoice->fresh(), 'credit' => $credit->fresh(), 'refund' => $refund];
        });
    }

    /** The confirmation, held on the invoice, and the other request arriving meanwhile. */
    private function confirmWhile(User $owner, Payment $refund, string $url, array $data): array
    {
        return $this->concurrently([
            function (string $dir) use ($owner, $refund) {
                self::holdAfterLockingTheInvoice($dir, 'confirming');

                return $this->send($owner, route('club.payments.confirm', $refund), ['reference' => 'BANK-RF-1']);
            },
            function (string $dir) use ($owner, $url, $data) {
                self::waitFor("$dir/confirming");

                return $this->send($owner, $url, $data);
            },
        ]);
    }

    private function assertNoServerError(array $results): void
    {
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
            $this->assertSame(302, $result['value']['status'], json_encode($results, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame([], $results[0]['value']['errors'], json_encode($results, JSON_UNESCAPED_UNICODE));
    }

    private function assertCreditAndBooks(Tenant $club, Payment $credit, string $used): void
    {
        $this->asTenant($club, function () use ($credit, $used) {
            $credit = $credit->fresh();
            $this->assertSame($used, $credit->credit_used);
            $this->assertLessThanOrEqual(0, Money::cmp($credit->credit_used, $credit->credit_amount), 'credit spent twice');
            $this->assertSame((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit'));
        });
    }

    public function test_applying_the_credit_to_its_invoice_while_its_refund_is_confirmed(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        // The invoice owes 30 again, and the credit may go to it.
        ['invoice' => $invoice, 'credit' => $credit, 'refund' => $refund] = $this->creditBeingPaidBack($club, cashWasAMistake: true);
        $this->assertSame([Invoice::PARTIALLY_PAID, '30.000'], [$invoice->fresh()->status, $invoice->fresh()->balance()]);

        $results = $this->confirmWhile($owner, $refund, route('club.payments.allocate', $credit), ['invoice_id' => $invoice->id, 'amount' => '10', 'client_key' => (string) Str::uuid()]);

        $this->assertNoServerError($results);
        $this->assertSame([], $results[1]['value']['errors'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(Payment::COMPLETED, $refund->fresh()->status);
        $this->asTenant($club, fn () => $this->assertSame(['10.000', '30.000'], [PaymentAllocation::sole()->amount, $invoice->fresh()->paid_total]));
        $this->assertCreditAndBooks($club, $credit, '30.000');
    }

    public function test_cancelling_the_payment_while_the_refund_of_its_credit_is_confirmed(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        ['credit' => $credit, 'refund' => $refund] = $this->creditBeingPaidBack($club);

        $results = $this->confirmWhile($owner, $refund, route('club.payments.cancel', $credit), ['reason' => 'تحويل سُجّل خطأً']);

        $this->assertNoServerError($results);
        $this->assertSame(['error' => ['استُخدم رصيد هذه الدفعة؛ لا تُلغى.']], $results[1]['value']['errors'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame([Payment::COMPLETED, Payment::COMPLETED], [$refund->fresh()->status, $credit->fresh()->status]);
        $this->assertCreditAndBooks($club, $credit, '20.000');
    }

    public function test_paying_the_rest_of_the_credit_back_in_cash_while_its_bank_refund_is_confirmed(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        ['credit' => $credit, 'refund' => $refund] = $this->creditBeingPaidBack($club);

        $results = $this->confirmWhile($owner, $refund, route('club.payments.refund-credit', $credit), ['amount' => '10', 'method' => 'cash', 'client_key' => (string) Str::uuid()]);

        $this->assertNoServerError($results);
        $this->assertSame([], $results[1]['value']['errors'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->asTenant($club, fn () => $this->assertSame(2, Payment::where('from_credit', true)->where('status', Payment::COMPLETED)->count()));
        $this->assertCreditAndBooks($club, $credit, '30.000');
    }

    public function test_refunding_a_credit_note_on_the_invoice_while_the_refund_of_its_credit_is_confirmed(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        // The transfer is the invoice's only payment, so the note's refund is made against it.
        ['invoice' => $invoice, 'credit' => $credit, 'refund' => $refund] = $this->creditBeingPaidBack($club, cashWasAMistake: true);
        $note = $this->asTenant($club, fn () => app(CreditNoteService::class)->issue($invoice->fresh(), '40', 'خصم'));
        $this->assertSame('10.000', $invoice->fresh()->refundDue());

        // Here the note's refund holds the invoice first, and the confirmation comes while it does.
        $results = $this->concurrently([
            function (string $dir) use ($owner, $note) {
                self::holdAfterLockingTheInvoice($dir, 'refunding');

                return $this->send($owner, route('club.credit-notes.refund', $note), ['amount' => '10', 'method' => 'cash', 'client_key' => (string) Str::uuid()]);
            },
            function (string $dir) use ($owner, $refund) {
                self::waitFor("$dir/refunding");

                return $this->send($owner, route('club.payments.confirm', $refund), ['reference' => 'BANK-RF-1']);
            },
        ]);

        $this->assertNoServerError($results);
        $this->assertSame([], $results[1]['value']['errors'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->asTenant($club, function () use ($note, $credit) {
            $this->assertSame('10.000', CreditNote::findOrFail($note->id)->refunded_total);
            $this->assertSame($credit->id, Payment::where('credit_note_id', $note->id)->sole()->refund_of_id);
        });
        $this->assertSame([Payment::COMPLETED, '0.000'], [$refund->fresh()->status, $invoice->fresh()->refundDue()]);
        $this->assertCreditAndBooks($club, $credit, '20.000');
    }
}
