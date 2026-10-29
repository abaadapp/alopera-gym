<?php

namespace App\Models;

use App\Core\Audit\Auditable;
use App\Core\Notifications\StaffAlerts;
use App\Core\Notifications\StaffNotifier;
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
    protected array $auditExclude = ['credentials', 'verified_at', 'last_checked_at', 'last_error', 'last_error_at', 'last_webhook_at'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'is_enabled' => 'boolean',
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_error_at' => 'datetime',
            'last_webhook_at' => 'datetime',
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

    /** A real call to the provider succeeded (a connection test, a payment page opened, a message sent). */
    public function markVerified(array $settings = []): void
    {
        $this->forceFill([
            'verified_at' => now(),
            'last_checked_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
            'settings' => array_merge($this->settings ?? [], $settings),
        ])->saveQuietly();
        app(StaffNotifier::class)->resolve('integration-error:'.$this->provider.':*');
    }

    /** The provider refused or could not be reached. The message is shown to the club owner; it never holds a secret. */
    public function markFailed(string $message): void
    {
        $this->forceFill(['last_error' => mb_substr($message, 0, 250), 'last_error_at' => now(), 'last_checked_at' => now()])->saveQuietly();
        app(StaffAlerts::class)->integrationError($this);
    }

    /** The provider called back with a valid signature. */
    public function markWebhook(): void
    {
        $this->forceFill(['last_webhook_at' => now()])->saveQuietly();
    }

    /** The last thing that happened was an error (a later success clears it). */
    public function failing(): bool
    {
        return $this->last_error !== null && $this->last_error_at !== null
            && ($this->verified_at === null || $this->last_error_at->greaterThanOrEqualTo($this->verified_at));
    }
}
