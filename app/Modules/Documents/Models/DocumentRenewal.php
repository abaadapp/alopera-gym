<?php

namespace App\Modules\Documents\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One renewal: who renewed and when, the new dates, and the version it replaced (kept in the archive). */
class DocumentRenewal extends Model implements BranchOwned
{
    use BelongsToTenant;

    /** The branch of its document. */
    public function owningBranchId(): ?int
    {
        return $this->document?->owningBranchId();
    }

    protected $table = 'gym_document_renewals';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'previous_issue_date' => 'immutable_date',
            'previous_expiry_date' => 'immutable_date',
            'new_issue_date' => 'immutable_date',
            'new_expiry_date' => 'immutable_date',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BusinessDocument::class, 'document_id')->withTrashed();
    }

    public function renewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'renewed_by');
    }
}
