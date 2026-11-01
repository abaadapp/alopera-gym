<?php

namespace App\Modules\Accounting\Services;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Accounting\Models\Account;
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
 *   payment completed    Dr the money account it went into          Cr receivable (Cr customer credits for what was not applied)
 *   payment cancelled    the receipt entry is reversed (in an open period)
 *   refund paid          Dr receivable (customer credits if from credit) Cr the money account it left from
 *   credit note          Dr returns & allowances, Dr VAT payable    Cr receivable
 *   expense              Dr the category's account                  Cr the cash or bank account that paid it (edits and deletes reverse)
 *
 * The money account is the one kept with the payment or expense; older records without one take the account the
 * finance settings give their method.
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
        if (Money::isZero($payment->amount)) {
            return;
        }
        $refund = $payment->kind === Payment::KIND_REFUND;
        $label = $payment->receipt_number ?? '#'.$payment->id;

        if ($payment->status === Payment::CANCELLED) {
            $receipt = $this->entry('payment', $payment->id, 'receive');
            if ($receipt && $receipt->reversed_at === null) {
                $this->ledger->reverse($receipt, 'إلغاء الدفعة '.$label.': '.$payment->cancel_reason, $this->key('payment', $payment->id, 'cancel'), $payment->cancelled_at ?? now());
            }

            return;
        }
        if ($payment->status !== Payment::COMPLETED) {
            return;
        }

        $money = ($payment->account_id ? Account::find($payment->account_id) : null) ?? $this->chart->moneyFor($payment->method, 'receipt');
        $receivable = $this->chart->account('receivable');
        $credit = Money::normalize($payment->credit_amount ?? 0);
        $receipt = $this->entry('payment', $payment->id, 'receive');

        // Recorded before customer credit had its own account (the whole amount against the receivable): moved once.
        if (! $refund && $receipt && ! Money::isZero($credit) && ! $receipt->lines()->where('account_id', $this->chart->account('customer_credits')->id)->exists()) {
            $this->ledger->post([
                'date' => app(AccountingPeriods::class)->postingDate($payment->paid_at),
                'description' => 'رصيد دائن للعميل من الدفعة '.$label,
                'branch_id' => $payment->branch_id,
                ...$this->key('payment', $payment->id, 'credit'),
            ], [['account' => $receivable, 'debit' => $credit], ['account' => $this->chart->account('customer_credits'), 'credit' => $credit]]);
        }

        $this->ledger->post([
            'date' => $payment->confirmed_at ?? $payment->paid_at ?? $payment->created_at,
            'description' => ($refund ? 'رد مبلغ ' : 'تحصيل ').$label.' — '.(Payment::METHOD_LABELS[$payment->method] ?? $payment->method),
            'branch_id' => $payment->branch_id,
            ...$this->key('payment', $payment->id, $refund ? 'refund' : 'receive'),
        ], $refund
            ? [['account' => $payment->from_credit ? $this->chart->account('customer_credits') : $receivable, 'debit' => $payment->amount], ['account' => $money, 'credit' => $payment->amount]]
            : [
                ['account' => $money, 'debit' => $payment->amount],
                ['account' => $receivable, 'credit' => Money::sub($payment->amount, $credit)],
                ['account' => $this->chart->account('customer_credits'), 'credit' => $credit],
            ], allowClosedAccounts: true);
    }

    public function creditNote(CreditNote $note): void
    {
        if (Money::isZero($note->amount)) {
            return;
        }

        // The note gives back revenue and, with it, its share of the tax: the VAT owed falls as the revenue does.
        $tax = Money::normalize($note->tax_amount ?? 0);
        $vat = $this->chart->account('vat_payable');
        $issued = $this->entry('credit_note', $note->id, 'issue');
        if (! $issued) {
            $this->ledger->post([
                'date' => $note->created_at ?? now(),
                'description' => "إشعار دائن {$note->number} — {$note->reason}",
                'branch_id' => $note->branch_id,
                ...$this->key('credit_note', $note->id, 'issue'),
            ], array_values(array_filter([
                ['account' => $this->chart->account('sales_returns'), 'debit' => Money::sub($note->amount, $tax)],
                Money::isZero($tax) ? null : ['account' => $vat, 'debit' => $tax],
                ['account' => $this->chart->account('receivable'), 'credit' => $note->amount],
            ])));

            return;
        }

        // Posted before the tax was split out (the whole amount to returns): the tax is moved to the VAT payable once.
        if (! Money::isZero($tax) && ! $issued->lines()->where('account_id', $vat->id)->exists()) {
            $this->ledger->post([
                'date' => app(AccountingPeriods::class)->postingDate($note->created_at),
                'description' => "ضريبة الإشعار الدائن {$note->number} — تصحيح",
                'branch_id' => $note->branch_id,
                ...$this->key('credit_note', $note->id, 'tax'),
            ], [
                ['account' => $vat, 'debit' => $tax],
                ['account' => $this->chart->account('sales_returns'), 'credit' => $tax],
            ]);
        }
    }

    public function expense(Expense $expense, bool $deleted = false): void
    {
        $current = JournalEntry::with('lines')->where(['source_type' => 'expense', 'source_id' => $expense->id, 'source_event' => 'record'])
            ->whereNull('reversed_at')->latest('revision')->first();
        $lastRevision = (int) JournalEntry::where(['source_type' => 'expense', 'source_id' => $expense->id, 'source_event' => 'record'])->max('revision');

        if ($deleted) {
            if ($current) {
                $this->ledger->reverse($current, "حذف المصروف {$expense->number}", $this->key('expense', $expense->id, 'delete', $current->revision), $current->entry_date);
            }

            return;
        }

        $account = $this->chart->forCategory($expense->category);
        $money = ($expense->account_id ? Account::find($expense->account_id) : null) ?? $this->chart->moneyFor($expense->payment_method, 'spending');
        if ($current) {
            $debit = $current->lines->firstWhere('debit', '>', 0);
            $credit = $current->lines->firstWhere('credit', '>', 0);
            $same = $debit?->account_id === $account->id && $credit?->account_id === $money->id
                && Money::cmp($current->total, $expense->amount) === 0 && $current->entry_date->isSameDay($expense->expense_date);
            if ($same) {
                return;
            }
            // Edited: the old posting is reversed on its own date (today if that month is closed) and the record posted
            // again as it now stands, so each month carries the expense once.
            $this->ledger->reverse($current, "تعديل المصروف {$expense->number}", $this->key('expense', $expense->id, 'adjust', $current->revision), $current->entry_date);
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

    /**
     * Post everything of the current club that is not in the books yet (records from before the books existed). A
     * record dated in a closed period is skipped and counted (the period's checks keep that from happening).
     */
    public function syncClub(): array
    {
        $this->chart->ensure();
        $counts = ['invoices' => 0, 'payments' => 0, 'credit_notes' => 0, 'expenses' => 0, 'skipped_closed' => 0];
        $before = JournalEntry::count();
        $each = function (callable $post) use (&$counts) {
            try {
                $post();
            } catch (BusinessRuleException $e) {
                if ($e->rule !== 'period.closed') {
                    throw $e;
                }
                $counts['skipped_closed']++;
            }
        };

        Invoice::query()->orderBy('id')->each(function (Invoice $i) use (&$counts, $each) {
            $each(fn () => $this->invoice($i));
            $counts['invoices']++;
        });
        CreditNote::query()->orderBy('id')->each(function (CreditNote $n) use (&$counts, $each) {
            $each(fn () => $this->creditNote($n));
            $counts['credit_notes']++;
        });
        Payment::query()->orderBy('id')->each(function (Payment $p) use (&$counts, $each) {
            $each(fn () => $this->payment($p));
            $counts['payments']++;
        });
        Expense::withTrashed()->with('category')->orderBy('id')->each(function (Expense $e) use (&$counts, $each) {
            // A record deleted before the books existed never reached them: nothing to post or reverse.
            if ($e->trashed() && ! $this->entry('expense', $e->id, 'record')) {
                return;
            }
            $each(fn () => $this->expense($e, $e->trashed()));
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
