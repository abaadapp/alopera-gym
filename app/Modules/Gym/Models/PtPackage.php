<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Personal-training package (e.g. 8 sessions in 60 days). */
class PtPackage extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'gym_pt_packages';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['is_active' => true, 'is_public' => true, 'session_minutes' => 60, 'validity_days' => 60];

    protected function casts(): array
    {
        return ['price' => 'decimal:3', 'is_public' => 'boolean', 'is_active' => 'boolean'];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class)->withTrashed();
    }
}
