<?php

namespace App\Modules\Messaging\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use Auditable, BelongsToTenant;

    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const SENDING = 'sending';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['status' => 'draft', 'recipients_count' => 0, 'sent_count' => 0, 'delivered_count' => 0, 'read_count' => 0, 'failed_count' => 0];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
