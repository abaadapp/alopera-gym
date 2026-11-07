<?php

namespace App\Modules\Messaging\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use App\Modules\Gym\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A WhatsApp chat with one phone number. New until someone takes it up, open while it is being answered, «قيد
 * المعالجة» while staff work on it, closed when done; a message from the customer opens a closed one again.
 */
class Conversation extends Model
{
    use BelongsToTenant;

    public const NEW = 'new';

    public const OPEN = 'open';

    public const PENDING = 'pending';

    public const CLOSED = 'closed';

    public const STATUSES = [self::NEW => 'جديدة', self::OPEN => 'مفتوحة', self::PENDING => 'قيد المعالجة', self::CLOSED => 'مغلقة'];

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['status' => self::NEW, 'unread_count' => 0, 'is_demo' => false];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'first_inbound_at' => 'datetime',
            'first_response_at' => 'datetime',
            'assigned_at' => 'datetime',
            'closed_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ConversationEvent::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** WhatsApp allows free-form replies only within 24h of the customer's last message. */
    public function serviceWindowOpen(): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subDay());
    }

    /** When the 24-hour window closes, or null when it is closed already. */
    public function windowClosesAt(): ?\DateTimeInterface
    {
        return $this->serviceWindowOpen() ? $this->last_inbound_at->addDay() : null;
    }

    /** The name staff see: the member's, the one saved on the phone, the WhatsApp profile's, else the number. */
    public function displayName(): string
    {
        return $this->member?->full_name ?? $this->contact_name ?? $this->profile_name ?? '+'.$this->phone;
    }
}
