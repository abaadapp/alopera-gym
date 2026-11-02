<?php

namespace App\Modules\Accounting\Models;

use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A balanced journal entry. Posted entries are never edited or deleted — a mistake is reversed. */
class JournalEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'gym_journal_entries';

    protected $fillable = ['branch_id', 'number', 'entry_date', 'description', 'source_type', 'source_id', 'source_event', 'revision', 'reverses_id', 'total', 'created_by'];

    /** What posted it, for the journal's source badge. */
    public const SOURCES = [
        'invoice' => 'فاتورة',
        'payment' => 'دفعة',
        'credit_note' => 'إشعار دائن',
        'expense' => 'مصروف',
        'payroll_run' => 'مسيرة رواتب',
        'payroll_payout' => 'صرف رواتب',
        'account' => 'رصيد افتتاحي',
        'transfer' => 'تحويل بين الحسابات',
        'supplier_bill' => 'سند مورد',
        'supplier_payment' => 'سداد مورد',
        'payment_allocation' => 'تخصيص رصيد عميل',
        'cash_shift' => 'فرق إقفال وردية',
        'cash_shift_movement' => 'نقل نقد من الدرج أو إليه',
    ];

    protected function casts(): array
    {
        return ['entry_date' => 'immutable_date', 'total' => 'decimal:3', 'reversed_at' => 'datetime', 'revision' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The lines as this viewer may read them. A payroll entry (or its reversal) carries a line per employee with their
     * pay; without payroll.salaries those come as one line per account, with no names.
     *
     * @return list<array{account: string, debit: string, credit: string, memo: string|null}>
     */
    public function shownLines(User $viewer): array
    {
        $label = fn (JournalLine $l) => $l->account->code.' · '.$l->account->name;
        $payroll = in_array($this->source_type, ['payroll_run', 'payroll_payout'], true)
            || ($this->reverses_id && in_array($this->reverses?->source_type, ['payroll_run', 'payroll_payout'], true));

        if (! $payroll || $viewer->hasPermission('payroll.salaries')) {
            return $this->lines->map(fn (JournalLine $l) => ['account' => $label($l), 'debit' => $l->debit, 'credit' => $l->credit, 'memo' => $l->memo])->values()->all();
        }

        return $this->lines->groupBy('account_id')->map(fn ($lines) => [
            'account' => $label($lines->first()),
            'debit' => Money::add('0', ...$lines->pluck('debit')->all()),
            'credit' => Money::add('0', ...$lines->pluck('credit')->all()),
            'memo' => $lines->count() > 1 ? 'مجموع سطور الموظفين' : null,
        ])->values()->all();
    }

    public function isManual(): bool
    {
        return $this->source_type === null && $this->reverses_id === null;
    }

    public function sourceLabel(): string
    {
        if ($this->reverses_id) {
            return 'قيد عكسي';
        }

        return self::SOURCES[$this->source_type] ?? 'قيد يدوي';
    }
}
