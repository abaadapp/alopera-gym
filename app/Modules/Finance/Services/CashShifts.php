<?php

namespace App\Modules\Finance\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\NumberSequence;
use App\Core\Tenancy\BranchContext;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Finance\Models\CashShift;
use App\Modules\Finance\Models\CashShiftMovement;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Payment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The cash drawer's shift. It opens with the cash counted in the drawer and closes with the cash counted again. In
 * between, every payment, refund and expense taken at the desk carries the shift (stamped when it is recorded): cash
 * into or out of this drawer, and the other methods taken in its branch for the totals by method. Money moved between
 * the drawer and the bank or the safe is a transfer between accounts, never revenue.
 *
 * Expected cash = opening float + cash received − cash refunded − cash expenses + cash moved in − cash moved out.
 * Closing records the count and the difference (a reason is required when there is one) and posts the difference
 * to «فروقات الصندوق»; a shift closes once.
 */
final class CashShifts
{
    public function __construct(private readonly AuditLogger $audit, private readonly Ledger $ledger) {}

    /**
     * The open shift what is being recorded now belongs to: the shift of the drawer the cash goes into or out of; for
     * other methods, the open shift of the branch (the recording user's own first). Nothing taken outside the desk
     * (an online payment, a scheduled job) belongs to a shift.
     */
    public function current(?Account $account, ?int $branchId): ?int
    {
        $user = Auth::guard('web')->id();
        if ($user === null) {
            return null;
        }
        // Read under a share lock: a shift being closed is waited for (and then no longer open), and a shift's closing
        // waits for the money being stamped to it, so its count includes it.
        if ($account?->kind === 'cash') {
            return CashShift::where('status', CashShift::OPEN)->where('account_id', $account->id)->sharedLock()->value('id');
        }
        $open = CashShift::where('status', CashShift::OPEN)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->sharedLock();

        return (clone $open)->where('opened_by', $user)->latest('id')->value('id') ?? $open->latest('id')->value('id');
    }

    public function open(Account $drawer, string|float $float, User $user): CashShift
    {
        if ($drawer->kind !== 'cash' || ! $drawer->isPostable()) {
            throw new BusinessRuleException('shift.drawer', 'تُفتح الوردية على صندوق نقدي مفعّل.', 'account_id');
        }
        if (Money::cmp($float, 0) < 0) {
            throw new BusinessRuleException('shift.float', 'النقد الافتتاحي لا يكون سالبًا.', 'opening_float');
        }

        try {
            return DB::transaction(function () use ($drawer, $float, $user) {
                $shift = CashShift::create([
                    'branch_id' => app(BranchContext::class)->assign(null, $user),
                    'account_id' => $drawer->id,
                    'number' => NumberSequence::next('cash_shift'),
                    'status' => CashShift::OPEN,
                    'opening_float' => Money::normalize($float),
                    'opened_by' => $user->id,
                    'opened_at' => now(),
                ]);
                $this->audit->log('cash_shift.opened', $shift, ['drawer' => $drawer->name, 'opening_float' => $shift->opening_float]);

                return $shift;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException('shift.already_open', 'لهذا الصندوق وردية مفتوحة؛ أقفلها أولًا.', 'account_id');
        }
    }

    /** Cash into the drawer (change from the safe) or out of it (taken to the bank): a transfer between the two accounts. */
    public function move(CashShift $shift, string $direction, string|float $amount, Account $counter, string $reason): CashShiftMovement
    {
        return DB::transaction(function () use ($shift, $direction, $amount, $counter, $reason) {
            $shift = CashShift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if (! $shift->isOpen()) {
                throw new BusinessRuleException('shift.closed', 'الوردية مقفلة.');
            }
            if (! in_array($direction, ['in', 'out'], true) || Money::toMinor($amount) <= 0) {
                throw new BusinessRuleException('shift.amount', 'المبلغ يجب أن يكون أكبر من صفر.', 'amount');
            }
            if ($counter->id === $shift->account_id || ! in_array($counter->kind, Account::PAYING_KINDS, true) || ! $counter->isPostable()) {
                throw new BusinessRuleException('shift.counter', 'اختر حسابًا بنكيًا أو صندوقًا آخر مفعّلًا.', 'counter_account_id');
            }
            if ($direction === 'out' && Money::cmp($amount, $this->summary($shift)['expected']) > 0) {
                throw new BusinessRuleException('shift.amount', 'المبلغ أكبر من النقد المتوقع في الدرج ('.$this->summary($shift)['expected'].').', 'amount');
            }

            $movement = CashShiftMovement::create([
                'cash_shift_id' => $shift->id, 'direction' => $direction, 'amount' => Money::normalize($amount),
                'counter_account_id' => $counter->id, 'reason' => $reason, 'created_by' => Auth::guard('web')->id(),
            ]);
            $drawer = Account::findOrFail($shift->account_id);
            [$from, $to] = $direction === 'out' ? [$drawer, $counter] : [$counter, $drawer];
            $this->ledger->post([
                'date' => now(), 'description' => ($direction === 'out' ? 'نقل من الدرج إلى ' : 'نقل إلى الدرج من ').$counter->name.' — '.$shift->number.': '.$reason,
                'branch_id' => $shift->branch_id, 'source_type' => 'cash_shift_movement', 'source_id' => $movement->id, 'event' => 'move',
            ], [['account' => $to, 'debit' => $movement->amount], ['account' => $from, 'credit' => $movement->amount]]);
            $this->audit->log('cash_shift.moved', $movement, ['shift' => $shift->number, 'direction' => $direction, 'amount' => $movement->amount, 'account' => $counter->name, 'reason' => $reason]);

            return $movement;
        });
    }

    /**
     * What passed through the shift so far, by method, and the cash the drawer should hold.
     *
     * @return array{methods: array<string, array{received: string, refunded: string, count: int}>, cash_received: string, cash_refunded: string, cash_expenses: string, moved_in: string, moved_out: string, other_in: string, other_out: string, expected: string, pending: int, payments: int}
     */
    public function summary(CashShift $shift): array
    {
        $methods = [];
        $cashIn = $cashOut = '0';
        $count = 0;
        Payment::where('cash_shift_id', $shift->id)->where('status', Payment::COMPLETED)->get(['kind', 'method', 'amount', 'account_id'])
            ->each(function (Payment $p) use (&$methods, &$cashIn, &$cashOut, &$count, $shift) {
                $m = $methods[$p->method] ?? ['received' => '0.000', 'refunded' => '0.000', 'count' => 0];
                $refund = $p->kind === Payment::KIND_REFUND;
                $m[$refund ? 'refunded' : 'received'] = Money::add($m[$refund ? 'refunded' : 'received'], $p->amount);
                $m['count']++;
                $count++;
                $methods[$p->method] = $m;
                if ((int) $p->account_id === (int) $shift->account_id) {
                    $refund ? $cashOut = Money::add($cashOut, $p->amount) : $cashIn = Money::add($cashIn, $p->amount);
                }
            });
        $expenses = Money::normalize(Expense::where('cash_shift_id', $shift->id)->where('account_id', $shift->account_id)->sum('amount'));
        $moved = fn (string $direction) => Money::normalize(CashShiftMovement::where('cash_shift_id', $shift->id)->where('direction', $direction)->sum('amount'));
        $in = $moved('in');
        $out = $moved('out');
        [$otherIn, $otherOut] = $this->otherMovements($shift);

        return [
            'methods' => $methods,
            'cash_received' => Money::normalize($cashIn),
            'cash_refunded' => Money::normalize($cashOut),
            'cash_expenses' => $expenses,
            'moved_in' => $in,
            'moved_out' => $out,
            'other_in' => $otherIn,
            'other_out' => $otherOut,
            'expected' => Money::sub(Money::add($shift->opening_float, $cashIn, $in, $otherIn), Money::add($cashOut, $expenses, $out, $otherOut)),
            'pending' => Payment::where('cash_shift_id', $shift->id)->where('status', Payment::PENDING)->count(),
            'payments' => $count,
        ];
    }

    /**
     * Money that reached or left the drawer by another door while the shift was open — salaries paid from it, a
     * supplier paid, a transfer to the bank, a journal entry — moves the cash it should hold just the same. Payments,
     * expenses and the shift's own moves are counted above; an opening balance is the cash already there.
     *
     * @return array{0: string, 1: string} in, out
     */
    private function otherMovements(CashShift $shift): array
    {
        $sums = JournalLine::where('account_id', $shift->account_id)
            ->whereHas('entry', fn ($q) => $q->where('created_at', '>=', $shift->opened_at)
                ->when($shift->closed_at, fn ($q, $closed) => $q->where('created_at', '<=', $closed))
                ->where(fn ($q) => $q->whereNull('source_type')->orWhereNotIn('source_type', ['payment', 'expense', 'cash_shift', 'cash_shift_movement', 'account'])))
            ->selectRaw('coalesce(sum(debit), 0) as debit, coalesce(sum(credit), 0) as credit')->first();

        return [Money::normalize($sums->debit ?? 0), Money::normalize($sums->credit ?? 0)];
    }

    public function close(CashShift $shift, string|float $counted, ?string $note, User $user): CashShift
    {
        return DB::transaction(function () use ($shift, $counted, $note, $user) {
            $shift = CashShift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if (! $shift->isOpen()) {
                throw new BusinessRuleException('shift.closed', 'هذه الوردية مقفلة مسبقًا.');
            }
            if (Money::cmp($counted, 0) < 0) {
                throw new BusinessRuleException('shift.counted', 'النقد المعدود لا يكون سالبًا.', 'counted_cash');
            }
            $summary = $this->summary($shift);
            $difference = Money::sub($counted, $summary['expected']);
            if (! Money::isZero($difference) && trim((string) $note) === '') {
                throw new BusinessRuleException('shift.note', 'في الدرج فرق '.$difference.'؛ اذكر سببه.', 'close_note');
            }

            $shift->forceFill([
                'status' => CashShift::CLOSED, 'expected_cash' => $summary['expected'], 'counted_cash' => Money::normalize($counted),
                'difference' => $difference, 'totals' => $summary, 'close_note' => $note ?: null, 'closed_by' => $user->id, 'closed_at' => now(),
            ])->save();

            if (! Money::isZero($difference)) {
                $drawer = Account::findOrFail($shift->account_id);
                $over = app(ChartOfAccounts::class)->account('cash_over_short');
                $amount = Money::max($difference, Money::sub('0', $difference));
                $short = Money::cmp($difference, 0) < 0;
                $this->ledger->post([
                    'date' => now(), 'description' => ($short ? 'عجز' : 'زيادة').' الصندوق عند إقفال الوردية '.$shift->number.': '.$note,
                    'branch_id' => $shift->branch_id, 'source_type' => 'cash_shift', 'source_id' => $shift->id, 'event' => 'difference',
                ], $short
                    ? [['account' => $over, 'debit' => $amount], ['account' => $drawer, 'credit' => $amount]]
                    : [['account' => $drawer, 'debit' => $amount], ['account' => $over, 'credit' => $amount]]);
            }
            $this->audit->log('cash_shift.closed', $shift, ['expected' => $summary['expected'], 'counted' => $shift->counted_cash, 'difference' => $difference, 'note' => $note]);

            return $shift;
        });
    }
}
