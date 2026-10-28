<?php

namespace App\Modules\Accounting\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Finance\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ledger account. Money accounts (kind = cash|bank|card|gateway) are where the club's money sits: the cash box,
 * bank accounts, and the clearing accounts card and online payments wait in until they reach the bank.
 */
class Account extends Model
{
    use BelongsToTenant;

    protected $table = 'gym_accounts';

    protected $fillable = ['parent_id', 'code', 'name', 'type', 'normal_side', 'is_group', 'system_key', 'kind', 'bank_name', 'account_number', 'iban', 'expense_category_id', 'is_active'];

    public const TYPES = ['asset' => 'الأصول', 'liability' => 'الخصوم', 'equity' => 'حقوق الملكية', 'revenue' => 'الإيرادات', 'expense' => 'المصروفات'];

    public const KINDS = ['cash' => 'صندوق نقدي', 'bank' => 'حساب بنكي', 'card' => 'مدفوعات البطاقات', 'gateway' => 'بوابة دفع إلكتروني'];

    /** Salaries and expenses are paid from these; card and gateway clearing accounts only receive. */
    public const PAYING_KINDS = ['cash', 'bank'];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'is_active' => 'boolean',
            'account_number' => 'encrypted',
            'iban' => 'encrypted',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** Leaf accounts that are open take postings; headings and closed accounts do not. */
    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_group', false)->where('is_active', true);
    }

    public function scopeMoney(Builder $query): Builder
    {
        return $query->whereNotNull('kind');
    }

    public function isPostable(): bool
    {
        return ! $this->is_group && $this->is_active;
    }

    public function isMoney(): bool
    {
        return $this->kind !== null;
    }

    /** The masked account number for screens (the full one is decrypted only where it is needed). */
    public function maskedNumber(): ?string
    {
        $value = $this->iban ?: $this->account_number;

        return $value ? '•••• '.mb_substr(preg_replace('/\s+/', '', $value), -4) : null;
    }
}
