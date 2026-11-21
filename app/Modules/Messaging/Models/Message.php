<?php

namespace App\Modules\Messaging\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use App\Modules\Gym\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Message extends Model
{
    use BelongsToTenant;

    public const SCHEDULED = 'scheduled';

    public const QUEUED = 'queued';

    /** Claimed by a worker, its request to WhatsApp on the way (#203): nothing sends a claimed message again. */
    public const SENDING = 'sending';

    /**
     * «نتيجة الإرسال غير مؤكدة» (#203): the request may have reached WhatsApp but its outcome was never recorded (an
     * error after WhatsApp answered, a worker that died mid-request, a connection broken after the request left). It
     * is not sent again on its own, and it is not «فشلت»: staff check with the customer before sending anything again.
     */
    public const UNCERTAIN = 'uncertain';

    public const UNCERTAIN_TEXT = 'قد تكون وصلت: لم يصل من واتساب ما يؤكد إرسالها أو ينفيه، فلم تُرسل مرة أخرى تلقائيًا. تأكد من العميل قبل أن ترسلها مجددًا.';

    /** A claim older than this has no worker behind it (a request lasts 15 seconds, a lost job is handed back in 90). */
    public const SENDING_STALE_MINUTES = 10;

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public const FAILED = 'failed';

    public const RECEIVED = 'received';

    public const SKIPPED = 'skipped';

    /** Where a message came from: staff in the panel, an automatic notice, the WhatsApp Business app on the phone
     *  (an echo), the history imported when the number was connected, or the customer. */
    public const SOURCE_PLATFORM = 'platform';

    public const SOURCE_AUTOMATION = 'automation';

    public const SOURCE_BUSINESS_APP = 'business_app';

    public const SOURCE_HISTORY = 'history';

    public const SOURCE_CUSTOMER = 'customer';

    /** How far a sent message has got: a later status never takes it back. */
    public const PROGRESS = [self::QUEUED => 0, self::SENDING => 0, self::UNCERTAIN => 0, self::SENT => 1, self::DELIVERED => 2, self::READ => 3];

    /** Why a message was skipped, as staff are told. */
    public const SKIP_REASONS = [
        'template_missing' => 'القالب غير موجود', 'template_not_approved' => 'القالب لم يُعتمد من Meta بعد', 'opted_out' => 'العضو لم يوافق على رسائل الخدمة',
        'no_phone' => 'لا رقم هاتف', 'no_marketing_consent' => 'بلا موافقة تسويقية',
        'missing_values' => 'قيم الفاتورة في القالب فارغة: يُرسل من «إرسال الفاتورة» باختيار الفاتورة',
    ];

    protected $guarded = ['id', 'tenant_id'];

    /** What a template was sent with stays on the server: a gift's message carries its secret link there (#192). */
    protected $hidden = ['template_params'];

    protected $attributes = ['attempts' => 0, 'channel' => 'whatsapp'];

    protected function casts(): array
    {
        return [
            'template_params' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'wa_timestamp' => 'datetime',
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

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** When it happened for the customer: WhatsApp's own time when known (late or imported messages), else ours. */
    public function happenedAt(): \DateTimeInterface
    {
        return $this->wa_timestamp ?? $this->created_at;
    }
}
