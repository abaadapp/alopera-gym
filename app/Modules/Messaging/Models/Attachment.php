<?php

namespace App\Modules\Messaging\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file sent or received in a WhatsApp chat. It is kept on the private disk and reached only through the panel,
 * by someone who may see its conversation (WhatsAppAttachmentController); it never has a public address.
 */
class Attachment extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const STORED = 'stored';

    public const FAILED = 'failed';

    /** What WhatsApp accepts, by kind (MIME types and the largest size in megabytes). */
    public const KINDS = [
        'image' => ['mimes' => ['image/jpeg', 'image/png'], 'max_mb' => 5],
        'video' => ['mimes' => ['video/mp4', 'video/3gpp'], 'max_mb' => 16],
        'audio' => ['mimes' => ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg'], 'max_mb' => 16],
        'document' => ['mimes' => [
            'application/pdf', 'text/plain', 'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ], 'max_mb' => 16],
    ];

    protected $table = 'whatsapp_attachments';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** The kind WhatsApp knows a file of this type by, or null when it accepts no such file. */
    public static function kindFor(string $mime): ?string
    {
        foreach (self::KINDS as $kind => $rule) {
            if (in_array($mime, $rule['mimes'], true)) {
                return $kind;
            }
        }

        return null;
    }
}
