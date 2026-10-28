<?php

namespace App\Modules\Accounting\Services;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\NumberSequence;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Posts balanced journal entries. Every rule is checked here, whoever posts: at least two lines, each line a
 * debit or a credit (3 decimals), open leaf accounts of this club, one account on one side, and debits equal to
 * credits. An entry with a source key — (invoice 12, issue) — is posted once: a retry returns the first.
 */
final class Ledger
{
    /**
     * @param  array{date: DateTimeInterface|string, description: string, source_type?: string|null, source_id?: int|null, event?: string|null, revision?: int, branch_id?: int|null}  $header
     * @param  list<array{account: Account|int, debit?: string|int|float|null, credit?: string|int|float|null, memo?: string|null}>  $lines
     */
    public function post(array $header, array $lines, bool $allowClosedAccounts = false): JournalEntry
    {
        $key = isset($header['source_type'], $header['source_id'], $header['event'])
            ? ['source_type' => $header['source_type'], 'source_id' => $header['source_id'], 'source_event' => $header['event'], 'revision' => $header['revision'] ?? 1]
            : null;

        if ($key && ($existing = JournalEntry::where($key)->first())) {
            return $existing;
        }

        $prepared = $this->prepare($lines, $allowClosedAccounts);

        try {
            // A savepoint: a duplicate key leaves an outer transaction usable.
            return DB::transaction(function () use ($header, $key, $prepared) {
                $entry = JournalEntry::create([
                    'number' => NumberSequence::next('journal'),
                    'entry_date' => $header['date'],
                    'description' => mb_substr($header['description'], 0, 255),
                    'branch_id' => $header['branch_id'] ?? null,
                    'total' => $prepared['total'],
                    'created_by' => Auth::guard('web')->id(),
                    ...($key ?? []),
                ]);
                foreach ($prepared['lines'] as $line) {
                    $entry->lines()->create($line);
                }

                return $entry;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($key && ($existing = JournalEntry::where($key)->first())) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * Post the mirror image of an entry and mark it reversed — once. Closed accounts may be reversed into, so an
     * old mistake can always be undone.
     */
    public function reverse(JournalEntry $entry, string $description, array $source = [], DateTimeInterface|string|null $date = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $description, $source, $date) {
            $original = JournalEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($original->reversed_at !== null || $original->reverses_id !== null && $source === []) {
                throw new BusinessRuleException('ledger.already_reversed', $original->reversed_at !== null ? 'هذا القيد معكوس مسبقًا.' : 'لا يُعكس القيد العكسي؛ أنشئي قيدًا جديدًا عند الحاجة.');
            }

            $mirror = $this->post([
                'date' => $date ?? now(),
                'description' => $description,
                'branch_id' => $original->branch_id,
                ...$source,
            ], $original->lines->map(fn ($line) => ['account' => $line->account_id, 'debit' => $line->credit, 'credit' => $line->debit, 'memo' => $line->memo])->all(), allowClosedAccounts: true);

            $mirror->forceFill(['reverses_id' => $original->id])->save();
            $original->forceFill(['reversed_at' => now()])->save();

            return $mirror;
        });
    }

    /** @return array{lines: list<array{account_id: int, debit: string, credit: string, memo: string|null}>, total: string} */
    private function prepare(array $lines, bool $allowClosedAccounts): array
    {
        $out = [];
        $debits = '0';
        $credits = '0';
        $sides = [];

        foreach ($lines as $line) {
            $debit = Money::normalize($line['debit'] ?? 0);
            $credit = Money::normalize($line['credit'] ?? 0);
            if (Money::isZero($debit) && Money::isZero($credit)) {
                continue;
            }
            if (Money::cmp($debit, 0) < 0 || Money::cmp($credit, 0) < 0 || (! Money::isZero($debit) && ! Money::isZero($credit))) {
                throw new BusinessRuleException('ledger.line_side', 'كل سطر في القيد مدين أو دائن بمبلغ موجب، لا الاثنين معًا.');
            }

            $account = $line['account'] instanceof Account ? $line['account'] : Account::find($line['account']);
            if (! $account || $account->is_group || (! $allowClosedAccounts && ! $account->is_active)) {
                throw new BusinessRuleException('ledger.account', 'الحساب غير صالح للقيد: اختاري حسابًا فرعيًا مفتوحًا.');
            }

            $side = Money::isZero($debit) ? 'credit' : 'debit';
            if (($sides[$account->id] ?? $side) !== $side) {
                throw new BusinessRuleException('ledger.same_account', "الحساب «{$account->name}» لا يكون مدينًا ودائنًا في القيد نفسه.");
            }
            $sides[$account->id] = $side;

            $out[] = ['account_id' => $account->id, 'debit' => $debit, 'credit' => $credit, 'memo' => isset($line['memo']) ? mb_substr((string) $line['memo'], 0, 255) : null];
            $debits = Money::add($debits, $debit);
            $credits = Money::add($credits, $credit);
        }

        if (count($out) < 2) {
            throw new BusinessRuleException('ledger.lines', 'القيد يحتاج سطرين على الأقل.');
        }
        if (Money::cmp($debits, $credits) !== 0) {
            throw new BusinessRuleException('ledger.unbalanced', 'القيد غير متوازن: مجموع المدين '.$debits.' ومجموع الدائن '.$credits.'.');
        }

        return ['lines' => $out, 'total' => $debits];
    }
}
