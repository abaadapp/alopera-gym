<?php

namespace App\Modules\Gym\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Communication log entry: a call, a visit, a WhatsApp conversation summary, an internal note. */
class MemberNote extends Model
{
    use BelongsToTenant;

    public const CHANNELS = ['call', 'whatsapp', 'sms', 'email', 'visit', 'note'];

    protected $table = 'gym_member_notes';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['follow_up_on' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
