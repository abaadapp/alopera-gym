<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A door reader, fingerprint terminal, turnstile or kiosk. Devices authenticate
 * with a bearer token; only its SHA-256 hash is stored.
 */
class AccessDevice extends Model
{
    use Auditable, BelongsToTenant;

    public const TYPES = ['fingerprint', 'qr_reader', 'barcode_reader', 'rfid_reader', 'turnstile', 'kiosk'];

    protected $table = 'gym_access_devices';

    protected $guarded = ['id', 'tenant_id', 'token_hash', 'token_last4'];

    protected $attributes = ['is_active' => true, 'driver' => 'generic_json', 'direction' => 'in', 'purpose' => 'members'];

    protected $hidden = ['token_hash'];

    /** @var list<string> */
    protected array $auditExclude = ['token_hash', 'last_seen_at', 'last_ip'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Issue a new token. The plain value is returned once and never stored. */
    public function issueToken(): string
    {
        $token = 'dev_'.Str::random(48);
        $this->forceFill([
            'token_hash' => hash('sha256', $token),
            'token_last4' => substr($token, -4),
        ]);

        return $token;
    }

    public function acceptsMembers(): bool
    {
        return in_array($this->purpose, ['members', 'both'], true);
    }

    public function acceptsStaff(): bool
    {
        return in_array($this->purpose, ['staff', 'both'], true);
    }
}
