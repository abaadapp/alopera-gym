<?php

namespace App\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * A club's credentials for an external provider (WhatsApp, Paymob).
 *
 * `credentials` is encrypted with the application key and is never sent to the
 * browser; the settings screen only receives which fields are filled.
 */
class TenantIntegration extends Model
{
    use Auditable, BelongsToTenant;

    public const WHATSAPP = 'whatsapp';

    public const PAYMOB = 'paymob';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['is_enabled' => false, 'mode' => 'test'];

    protected $hidden = ['credentials'];

    /** @var list<string> */
    protected array $auditExclude = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'is_enabled' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function credential(string $key, ?string $default = null): ?string
    {
        $value = Arr::get($this->credentials ?? [], $key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->settings ?? [], $key, $default);
    }

    /**
     * Which secret fields are present, without revealing them.
     *
     * @return array<string, bool>
     */
    public function credentialPresence(array $keys): array
    {
        return collect($keys)->mapWithKeys(fn ($key) => [$key => $this->credential($key) !== null])->all();
    }
}
