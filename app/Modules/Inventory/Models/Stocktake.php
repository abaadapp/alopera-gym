<?php

namespace App\Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A physical stock count of one branch: counted while a draft, its differences posted once on approval. */
class Stocktake extends Model implements BranchOwned
{
    use Auditable, BelongsToTenant, OwnedByBranch;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id', 'tenant_id', 'status', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class)->orderBy('id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
