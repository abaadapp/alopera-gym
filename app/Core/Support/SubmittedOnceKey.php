<?php

namespace App\Core\Support;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** A form's key and what its submission returned (SubmittedOnce). Kept thirty days, long past any retry. */
class SubmittedOnceKey extends Model
{
    use BelongsToTenant, MassPrunable;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['result' => 'json'];
    }

    public function prunable()
    {
        return static::withoutGlobalScopes()->where('created_at', '<', now()->subDays(30));
    }
}
