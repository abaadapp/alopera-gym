<?php

namespace App\Modules\Messaging\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happened to a conversation, in its thread: an internal note (never sent to the customer), an assignment or
 * a transfer, a change of status, a link to a member or its removal. Who did it is kept.
 */
class ConversationEvent extends Model
{
    use BelongsToTenant;

    public const NOTE = 'note';

    public const ASSIGNED = 'assigned';

    public const STATUS = 'status';

    public const LINKED = 'linked';

    public const UNLINKED = 'unlinked';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
