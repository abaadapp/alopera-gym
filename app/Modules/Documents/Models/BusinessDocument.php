<?php

namespace App\Modules\Documents\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document of the business itself (not a member's). Its file lives on the private disk and is only served
 * through the download route, after the permission and confidentiality checks.
 */
class BusinessDocument extends Model implements BranchOwned
{
    use Auditable, BelongsToTenant, OwnedByBranch, SoftDeletes;

    public const STATUSES = ['active' => 'ساري', 'cancelled' => 'ملغى'];

    public const STATE_LABELS = ['valid' => 'ساري', 'expiring' => 'قريب الانتهاء', 'expired' => 'منتهٍ', 'cancelled' => 'ملغى'];

    protected $table = 'gym_documents';

    protected $guarded = ['id', 'tenant_id', 'file_path', 'file_name', 'file_mime', 'file_size', 'created_by', 'updated_by'];

    protected $attributes = ['status' => 'active', 'is_confidential' => false];

    protected function casts(): array
    {
        return [
            'issue_date' => 'immutable_date',
            'expiry_date' => 'immutable_date',
            'is_confidential' => 'boolean',
            'renewal_months' => 'integer',
            'file_size' => 'integer',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(DocumentRenewal::class, 'document_id')->latest('id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(DocumentAlert::class, 'document_id');
    }

    /**
     * The documents a user may see: everything with documents.confidential; otherwise the non-confidential ones
     * plus confidential ones they are responsible for. A user assigned to a branch sees that branch's and the
     * club-wide ones.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query
            ->when(! $user->can('documents.confidential'), fn ($q) => $q->where(fn ($w) => $w->where('is_confidential', false)->orWhere('responsible_user_id', $user->id)))
            ->when($user->branch_id, fn ($q, $branch) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branch)));
    }

    public function isVisibleTo(User $user): bool
    {
        return self::query()->whereKey($this->id)->visibleTo($user)->exists();
    }

    public function daysLeft(?CarbonImmutable $today = null): ?int
    {
        return $this->expiry_date ? (int) ($today ?? CarbonImmutable::today())->diffInDays($this->expiry_date, false) : null;
    }

    /** valid | expiring | expired | cancelled, against the club's earliest alert (in days). */
    public function state(int $soon, ?CarbonImmutable $today = null): string
    {
        if ($this->status === 'cancelled') {
            return 'cancelled';
        }
        $left = $this->daysLeft($today);

        return match (true) {
            $left === null => 'valid',
            $left < 0 => 'expired',
            $left <= $soon => 'expiring',
            default => 'valid',
        };
    }
}
