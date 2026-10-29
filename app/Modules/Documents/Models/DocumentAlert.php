<?php

namespace App\Modules\Documents\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An expiry alert: one per document, expiry date and threshold (0 = expired), never repeated. */
class DocumentAlert extends Model
{
    use BelongsToTenant;

    protected $table = 'gym_document_alerts';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['expiry_date' => 'immutable_date', 'acknowledged_at' => 'datetime', 'threshold_days' => 'integer', 'days_left' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BusinessDocument::class, 'document_id');
    }
}
