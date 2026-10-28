<?php

namespace App\Modules\Accounting\Services;

use App\Core\Support\Money;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns the club's finance records into journal entries, without touching the services that issue invoices or
 * take payments: it runs after they commit, and posting the same record again changes nothing (source keys).
 * So the books can also be built for records from before the books existed (accounting:sync).
 *
 *   invoice issued       Dr receivable (total)          Cr revenue of its type (net of tax), Cr VAT payable
 *   invoice voided       the issue entry is reversed
 *   payment completed    Dr money account of the method Cr receivable
 *   refund paid          Dr receivable                  Cr money account of the method
 *   credit note          Dr returns & allowances        Cr receivable
 *   expense              Dr the category's account      Cr cash or bank by method (edits and deletes reverse)
 */
final class LedgerProjector
{
    public function __construct(private readonly Ledger $ledger, private readonly ChartOfAccounts $chart, private readonly TenantContext $tenants) {}

    public function project(Model $record, bool $deleted = false): void
    {
        $this->within($record, function () use ($record, $deleted) {
            match (true) {
                $record instanceof Invoice => $this->invoice($record),
                $record instanceof Payment => $this->payment($record),
                $record instanceof CreditNote => $this->creditNote($record),
                $record instanceof Expense => $this->expense($record, $deleted || $record->trashed()),
                default => null,
            };
        });
    }

    public function invoice(Invoice $invoice): void
    {
        if ($invoice->status === Invoice::DRAFT) {
            return;
        }

        $issue = $this->entry('invoice', $invoice->id, 'issue');
        if ($invoice->status === Invoice::VOID) {
            if ($issue && $issue->reversed_at === null) {
                $this->ledger->reverse($issue, 'إلغاء الفاتورة '.$invoice->number, $this->key('invoice', $invoice->id, 'void'), $invoice->voided_at ?? now());
            }

            return;
        }
        if ($issue || Money::isZero($invoice->total)) {
            return;
        }

        $tax = Money::normalize($invoice->tax_total);
        $this->ledger->post([
            'date' => $invoice->issue_date,
            'description' => "فاتورة {$invoice->number} — {$invoice->customer_name}",
            'branch_id' => $invoice->branch_id,
            ...$this->key('invoice', $invoice->id, 'issue'),
        ], [
            ['account' => $this->chart->account('receivable'), 'debit' => $invoice->total],
            ['account' => $this->chart->forInvoiceType($invoice->type), 'credit' => Money::sub($invoice->total, $tax)],
            ['account' => $this->chart->account('vat_payable'), 'credit' => $tax],
        ]);
    }

    public function payment(Payment $payment): void
    {
        if ($payment->status !== Payment::COMPLETED || Money::isZero($payment->amount)) {
            return;
        }

        $money = $this->chart->moneyFor($payment->method, 'receipt');
        $receivable = $this->chart->account('receivable');
        $refund = $payment->kind === Payment::KIND_REFUND;
        $label = $payment->receipt_number ?? '#'.$payment->id;

        $this->ledger->post([
            'date' => $payment->confirmed_at ?? $payment->paid_at ?? $payment->created_at,
            'description' => ($refund ? 'رد مبلغ ' : 'تحصيل ').$label.' — '.(Payment::METHOD_LABELS[$payment->method] ?? $payment->method),
            'branch_id' => $payment->branch_id,
            ...$this->key('payment', $payment->id, $refund ? 'refund' : 'receive'),
        ], $refund
            ? [['account' => $receivable, 'debit' => $payment->amount], ['account' => $money, 'credit' => $payment->amount]]
            : [['account' => $money, 'debit' => $payment->amount], ['account' => $receivable, 'credit' => $payment->amount]]);
    }

    public function creditNote(CreditNote $note): void
    {
        if (Money::isZero($note->amount)) {
            return;
        }

        $this->ledger->post([
            'date' => $note->created_at ?? now(),
            'description' => "إشعار دائن {$note->number} — {$note->reason}",
            'branch_id' => $note->branch_id,
            ...$this->key('credit_note', $note->id, 'issue'),
        ], [
            ['account' => $this->chart->account('sales_returns'), 'debit' => $note->amount],
            ['account' => $this->chart->account('receivable'), 'credit' => $note->amount],
        ]);
    }

    public function expense(Expense $expense, bool $deleted = false): void
    {
        $current = JournalEntry::with('lines')->where(['source_type' => 'expense', 'source_id' => $expense->id, 'source_event' => 'record'])
            ->whereNull('reversed_at')->latest('revision')->first();
        $lastRevision = (int) JournalEntry::where(['source_type' => 'expense', 'source_id' => $expense->id, 'source_event' => 'record'])->max('revision');

        if ($deleted) {
            if ($current) {
                $this->ledger->reverse($current, "حذف المصروف {$expense->number}", $this->key('expense', $expense->id, 'delete', $current->revision));
            }

            return;
        }

        $account = $this->chart->forCategory($expense->category);
        $money = $this->chart->moneyFor($expense->payment_method, 'spending');
        if ($current) {
            $debit = $current->lines->firstWhere('debit', '>', 0);
            $credit = $current->lines->firstWhere('credit', '>', 0);
            $same = $debit?->account_id === $account->id && $credit?->account_id === $money->id
                && Money::cmp($current->total, $expense->amount) === 0 && $current->entry_date->isSameDay($expense->expense_date);
            if ($same) {
                return;
            }
            // Edited: the old posting is reversed and the record posted again as it now stands.
            $this->ledger->reverse($current, "تعديل المصروف {$expense->number}", $this->key('expense', $expense->id, 'adjust', $current->revision));
        }

        $this->ledger->post([
            'date' => $expense->expense_date,
            'description' => "مصروف {$expense->number} — {$expense->description}",
            'branch_id' => $expense->branch_id,
            ...$this->key('expense', $expense->id, 'record', $lastRevision + 1),
        ], [
            ['account' => $account, 'debit' => $expense->amount],
            ['account' => $money, 'credit' => $expense->amount],
        ]);
    }

    /** Post everything of the current club that is not in the books yet (records from before the books existed). */
    public function syncClub(): array
    {
        $this->chart->ensure();
        $counts = ['invoices' => 0, 'payments' => 0, 'credit_notes' => 0, 'expenses' => 0];
        $before = JournalEntry::count();

        Invoice::query()->orderBy('id')->each(function (Invoice $i) use (&$counts) {
            $this->invoice($i);
            $counts['invoices']++;
        });
        CreditNote::query()->orderBy('id')->each(function (CreditNote $n) use (&$counts) {
            $this->creditNote($n);
            $counts['credit_notes']++;
        });
        Payment::query()->orderBy('id')->each(function (Payment $p) use (&$counts) {
            $this->payment($p);
            $counts['payments']++;
        });
        Expense::withTrashed()->with('category')->orderBy('id')->each(function (Expense $e) use (&$counts) {
            // A record deleted before the books existed never reached them: nothing to post or reverse.
            if ($e->trashed() && ! $this->entry('expense', $e->id, 'record')) {
                return;
            }
            $this->expense($e, $e->trashed());
            $counts['expenses']++;
        });

        return $counts + ['entries_added' => JournalEntry::count() - $before];
    }

    private function entry(string $type, int $id, string $event): ?JournalEntry
    {
        return JournalEntry::where(['source_type' => $type, 'source_id' => $id, 'source_event' => $event])->latest('revision')->first();
    }

    /** @return array{source_type: string, source_id: int, event: string, revision: int} */
    private function key(string $type, int $id, string $event, int $revision = 1): array
    {
        return ['source_type' => $type, 'source_id' => $id, 'event' => $event, 'revision' => $revision];
    }

    private function within(Model $record, callable $callback): void
    {
        if ($this->tenants->id() === (int) $record->tenant_id) {
            $callback();

            return;
        }

        $this->tenants->run(Tenant::findOrFail($record->tenant_id), $callback);
    }
}
