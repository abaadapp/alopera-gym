<?php

namespace App\Modules\Accounting\Models;

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
