<?php

namespace App\Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A point-of-sale device (a till or a tablet at the desk) bound to one branch. It is recognised by a secret kept in
 * an encrypted cookie on that browser; only the secret's SHA-256 hash is stored, as is a one-time pairing code's.
 * Revoking replaces the hash, so the old cookie never works again.
 */
class PosDevice extends Model implements BranchOwned
{
    use Auditable, BelongsToTenant, OwnedByBranch, SoftDeletes;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    protected $guarded = ['id', 'tenant_id', 'status', 'pairing_hash', 'pairing_expires_at', 'token_hash', 'activated_by', 'activated_at', 'last_seen_at', 'last_ip', 'user_agent', 'revoked_by', 'revoked_at'];

    protected $hidden = ['pairing_hash', 'token_hash'];

    protected array $auditExclude = ['pairing_hash', 'token_hash', 'last_seen_at', 'last_ip', 'user_agent'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'pairing_expires_at' => 'datetime', 'activated_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function peripherals(): HasMany
    {
        return $this->hasMany(PosPeripheral::class)->orderBy('type')->orderBy('id');
    }

    public function settlementAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'settlement_account_id');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** The printer receipts go to: the default active one, else the first active one. */
    public function printer(): ?PosPeripheral
    {
        $printers = $this->peripherals->where('type', 'printer')->where('is_active', true);

        return $printers->firstWhere('is_default', true) ?? $printers->first();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
