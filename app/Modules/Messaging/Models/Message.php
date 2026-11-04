<?php

namespace App\Modules\Messaging\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use App\Modules\Gym\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Message extends Model
{
    use BelongsToTenant;

    public const SCHEDULED = 'scheduled';

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public const FAILED = 'failed';

    public const RECEIVED = 'received';

    public const SKIPPED = 'skipped';

    /** Why a message was skipped, as staff are told. */
    public const SKIP_REASONS = [
        'template_missing' => 'القالب غير موجود', 'template_not_approved' => 'القالب لم يُعتمد من Meta بعد', 'opted_out' => 'العضو لم يوافق على رسائل الخدمة',
        'no_phone' => 'لا رقم هاتف', 'no_marketing_consent' => 'بلا موافقة تسويقية',
    ];

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['attempts' => 0, 'channel' => 'whatsapp'];

    protected function casts(): array
    {
        return [
            'template_params' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
