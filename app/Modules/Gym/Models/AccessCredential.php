<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** What a device reads (card UID, fingerprint user id, PIN) mapped to a member or staff user. */
class AccessCredential extends Model
{
    use Auditable, BelongsToTenant;

    public const TYPES = ['card', 'fingerprint', 'pin', 'barcode'];

    /**
     * A fingerprint is enrolled and matched on the device; the system keeps only the user number the device gives
     * it, never the print, its image or its template. A short id is all that fits here.
     */
    public const FINGERPRINT_ID = '/^[A-Za-z0-9_-]{1,32}$/';

    /** @return array<string, list<string>> */
    public static function identifierRules(string $type): array
    {
        return ['identifier' => array_merge(['required', 'string', 'max:128'], $type === 'fingerprint' ? ['regex:'.self::FINGERPRINT_ID] : [])];
    }

    public const IDENTIFIER_MESSAGES = [
        'identifier.regex' => 'للبصمة أدخل رقم المستخدم المسجّل في جهاز البصمة فقط (حروف وأرقام حتى 32 خانة). البصمة نفسها لا تُحفظ في النظام.',
    ];

    protected $table = 'gym_access_credentials';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function holder(): MorphTo
    {
        return $this->morphTo();
    }
}
