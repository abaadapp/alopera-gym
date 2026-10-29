<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A door reader, fingerprint terminal, turnstile or kiosk. Devices authenticate
 * with a bearer token; only its SHA-256 hash is stored.
 */
class AccessDevice extends Model implements BranchOwned
{
    use Auditable, BelongsToTenant, OwnedByBranch;

    public const TYPES = ['fingerprint', 'qr_reader', 'barcode_reader', 'rfid_reader', 'turnstile', 'kiosk'];

    /** Online: it called the API (a heartbeat or a reading) within this many minutes. */
    public const ONLINE_MINUTES = 10;

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

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->greaterThan(now()->subMinutes(self::ONLINE_MINUTES));
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
