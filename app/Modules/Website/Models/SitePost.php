<?php

namespace App\Modules\Website\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** News article or time-limited offer shown on the club website. */
class SitePost extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['type' => 'news', 'is_published' => false];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', today()));
    }
}
