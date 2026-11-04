<?php

namespace App\Models;

use App\Core\Branding\OfficialLogo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;

/**
 * The gym: its name, brand, contact details and business settings. The system runs one gym; its tables keep a
 * tenant_id so every query stays scoped to it (see BelongsToTenant).
 *
 * @property array<string, mixed>|null $settings
 */
class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    /** Club business rules. Stored overrides are merged on top of these. */
    public const DEFAULT_SETTINGS = [
        'booking_window_days' => 14,
        'booking_cancel_cutoff_hours' => 3,
        'checkin_class_window_minutes' => 60,
        'duplicate_scan_minutes' => 5,
        'block_access_with_dues' => false,
        'auto_mark_no_show' => true,
        'expiry_reminder_days' => [7, 3, 1],
        'expired_followup_days' => [7],
        'notify_class_changes' => true,
        'class_reminder_hours' => 0, // WhatsApp reminder this many hours before a booked class; 0 = off
        // Payment reminders for members' open invoices: off until the owner turns them on.
        'dues_reminders_enabled' => false,
        'dues_reminder_before_days' => [3],
        'dues_reminder_overdue_days' => [1, 7, 14],
        'dues_reminder_max' => 3,
        'class_session_weeks_ahead' => 4,
        // null until the owner says: then registered when a rate or a number is set, as the club ran before the switch.
        'vat_registered' => null,
        'vat_rate' => 0,
        'vat_number' => null,
        'cr_number' => null,
        'governorate' => null,
        // Printed on a customer invoice under «where and how to pay» while something is owed.
        'bank_name' => null,
        'bank_account_name' => null,
        'bank_iban' => null,
        'invoice_footer' => null,
        'invoice_due_days' => 0,
        'allow_pay_at_reception' => true,
        'member_signup_enabled' => true,
    ];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active', 'country' => 'OM', 'timezone' => 'Asia/Muscat', 'currency' => 'OMR', 'locale' => 'ar', 'primary_color' => '#9d174d', 'secondary_color' => '#f9a8d4'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->settings ?? [], $key, Arr::get(self::DEFAULT_SETTINGS, $key, $default));
    }

    /** @return array<string, mixed> */
    public function allSettings(): array
    {
        return array_replace(self::DEFAULT_SETTINGS, $this->settings ?? []);
    }

    public function logoUrl(): string
    {
        return OfficialLogo::url($this);
    }

    /** Registered for VAT: only then is a VAT number printed and a rate charged. */
    public function vatRegistered(): bool
    {
        $said = $this->setting('vat_registered');

        return $said === null ? (float) $this->setting('vat_rate', 0) > 0 || filled($this->setting('vat_number')) : (bool) $said;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(TenantIntegration::class);
    }

    public function mainBranch(): ?Branch
    {
        return $this->branches()->orderByDesc('is_main')->orderBy('id')->first();
    }
}
