<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A notification in a staff member's bell (App\Core\Notifications\StaffNotifier). */
class StaffNotification extends Model
{
    use BelongsToTenant;

    public const KINDS = ['document', 'order', 'payment', 'membership', 'integration', 'contact', 'inbox'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /** @param Builder<self> $query */
    public function scopeFor(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /** @param Builder<self> $query */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }
}
