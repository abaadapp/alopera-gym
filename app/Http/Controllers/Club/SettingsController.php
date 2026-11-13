<?php

namespace App\Http\Controllers\Club;

use App\Core\Audit\AuditLogger;
use App\Core\Branding\OfficialLogo;
use App\Core\Support\Images\ImageRule;
use App\Core\Support\Images\ImageUpload;
use App\Core\Support\ImageStore;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The settings hub, the club profile and business rules, and the notification rules. */
class SettingsController extends Controller
{
    /** Opening the hub needs at least one of the screens it links to. */
    public const HUB_PERMISSIONS = [
        'settings.manage', 'branches.manage', 'users.manage', 'roles.manage',
        'audit.view', 'backups.manage', 'accounting.manage', 'documents.manage', 'pos_devices.manage',
    ];

    private const NOTIFICATION_RULES = ['expiry_reminder_days', 'expired_followup_days', 'notify_class_changes', 'class_reminder_hours', 'dues_reminders_enabled', 'dues_reminder_before_days', 'dues_reminder_overdue_days', 'dues_reminder_max'];

    /** Oman's governorates, for the address printed on papers. */
    public const GOVERNORATES = ['مسقط', 'ظفار', 'مسندم', 'البريمي', 'الداخلية', 'شمال الباطنة', 'جنوب الباطنة', 'جنوب الشرقية', 'شمال الشرقية', 'الظاهرة', 'الوسطى'];

    public const CURRENCIES = ['OMR' => 'ر.ع — ريال عُماني', 'SAR' => 'ر.س — ريال سعودي', 'AED' => 'د.إ — درهم إماراتي', 'KWD' => 'د.ك — دينار كويتي', 'QAR' => 'ر.ق — ريال قطري', 'BHD' => 'د.ب — دينار بحريني'];

    /** Any invoice or payment recorded: amounts are in the club's currency from then on. */
    private function hasMoney(): bool
    {
        return Invoice::query()->exists() || Payment::query()->exists();
    }

    public function index(Request $request): Response
    {
        abort_unless(collect(self::HUB_PERMISSIONS)->contains(fn (string $p) => $request->user()->can($p)), 403);

        return Inertia::render('Club/Settings/Index');
    }

    public function edit(TenantContext $tenants): Response
    {
        $tenant = $tenants->require();

        return Inertia::render('Club/Settings/General', [
            'club' => $tenant->only('name', 'legal_name', 'email', 'phone', 'address', 'city', 'primary_color', 'secondary_color', 'latitude', 'longitude', 'domain', 'currency') + [
                'logo_url' => $tenant->logoUrl(),
                'logo_custom' => OfficialLogo::uploaded($tenant) !== null,
                'currency_locked' => $this->hasMoney(),
            ],
            'rules' => ['vat_registered' => $tenant->vatRegistered()] + $tenant->allSettings(),
            'governorates' => self::GOVERNORATES,
            'currencies' => self::CURRENCIES,
        ]);
    }

    public function update(Request $request, TenantContext $tenants): RedirectResponse
    {
        $tenant = $tenants->require();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'currency' => ['sometimes', 'string', 'in:'.implode(',', array_keys(self::CURRENCIES))],
            'logo' => ImageRule::rules(),
            'logo_preview' => ImageRule::previewRules(),
            'remove_logo' => ['boolean'],
            'rules' => ['array'],
            'rules.booking_window_days' => ['integer', 'between:1,90'],
            'rules.booking_cancel_cutoff_hours' => ['integer', 'between:0,72'],
            'rules.checkin_class_window_minutes' => ['integer', 'between:0,240'],
            'rules.duplicate_scan_minutes' => ['integer', 'between:0,120'],
            'rules.block_access_with_dues' => ['boolean'],
            'rules.auto_mark_no_show' => ['boolean'],
            'rules.class_session_weeks_ahead' => ['integer', 'between:1,12'],
            'rules.vat_registered' => ['boolean'],
            'rules.vat_rate' => ['numeric', 'between:0,30'],
            'rules.governorate' => ['nullable', 'string', 'in:'.implode(',', self::GOVERNORATES)],
            'rules.bank_name' => ['nullable', 'string', 'max:80'],
            'rules.bank_account_name' => ['nullable', 'string', 'max:120'],
            'rules.bank_iban' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9 ]+$/'],
            'rules.vat_number' => ['nullable', 'string', 'max:40'],
            'rules.cr_number' => ['nullable', 'string', 'max:40'],
            'rules.invoice_footer' => ['nullable', 'string', 'max:500'],
            'rules.invoice_due_days' => ['integer', 'between:0,90'],
            'rules.allow_pay_at_reception' => ['boolean'],
            'rules.member_signup_enabled' => ['boolean'],
        ]);

        // Notification rules have their own screen; this form never touches them.
        $rules = Arr::except(array_intersect_key($data['rules'] ?? [], $tenant::DEFAULT_SETTINGS), self::NOTIFICATION_RULES);
        // A form with a file comes as multipart, where a switch reads «0» or «1»: kept as true or false either way.
        foreach ($rules as $key => $value) {
            if ($key === 'vat_registered' || is_bool($tenant::DEFAULT_SETTINGS[$key])) {
                $rules[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }
        // Not registered for VAT: no rate is charged (the number typed in stays, unprinted, for the day it registers).
        if ((array_key_exists('vat_registered', $rules) ? $rules['vat_registered'] : $tenant->setting('vat_registered')) === false) {
            $rules['vat_rate'] = 0;
        }
        // The currency of every amount already recorded: it changes only before the first invoice or payment.
        if (isset($data['currency']) && $data['currency'] !== $tenant->currency && $this->hasMoney()) {
            throw ValidationException::withMessages(['currency' => 'لا يمكن تغيير العملة بعد تسجيل فواتير أو مدفوعات.']);
        }
        $logo = ImageUpload::from($request, 'logo');
        $removeLogo = (bool) ($data['remove_logo'] ?? false);
        unset($data['rules'], $data['logo'], $data['logo_preview'], $data['remove_logo']);

        $snapshot = fn () => $tenant->only(array_keys($data)) + Arr::only($tenant->settings ?? [], array_keys($rules));
        $before = $snapshot();
        $tenant->fill($data);
        $settings = array_replace($tenant->settings ?? [], $rules);
        // A new logo is stored first and the old file goes only after the settings are saved, so a failed save
        // keeps the logo the club had.
        $oldLogo = $tenant->setting('logo_file');
        if ($logo) {
            $settings['logo_file'] = OfficialLogo::store($logo, $tenant);
        } elseif ($removeLogo) {
            unset($settings['logo_file']);
        }
        $tenant->settings = $settings;
        ImageStore::commit(is_string($oldLogo) && ($logo || $removeLogo) ? $oldLogo : null, $logo ? $settings['logo_file'] : '', fn () => $tenant->save());
        app(AuditLogger::class)->logChanges('settings.club_updated', $tenant, $before, $snapshot(), $logo ? ['logo' => 'uploaded'] : ($removeLogo ? ['logo' => 'official'] : []));

        return back()->with('success', 'تم حفظ إعدادات النادي.');
    }

    public function notifications(TenantContext $tenants): Response
    {
        return Inertia::render('Club/Settings/Notifications', [
            'rules' => Arr::only($tenants->require()->allSettings(), self::NOTIFICATION_RULES),
        ]);
    }

    public function updateNotifications(Request $request, TenantContext $tenants): RedirectResponse
    {
        $tenant = $tenants->require();
        $before = Arr::only($tenant->settings ?? [], self::NOTIFICATION_RULES);
        $data = $request->validate([
            'expiry_reminder_days' => ['array', 'max:5'],
            'expiry_reminder_days.*' => ['integer', 'between:0,60'],
            'expired_followup_days' => ['array', 'max:5'],
            'expired_followup_days.*' => ['integer', 'between:1,180'],
            'notify_class_changes' => ['boolean'],
            'class_reminder_hours' => ['nullable', 'integer', 'between:0,48'],
            'dues_reminders_enabled' => ['boolean'],
            'dues_reminder_before_days' => ['array', 'max:3'],
            'dues_reminder_before_days.*' => ['integer', 'between:1,30'],
            'dues_reminder_overdue_days' => ['array', 'max:5'],
            'dues_reminder_overdue_days.*' => ['integer', 'between:1,90'],
            'dues_reminder_max' => ['nullable', 'integer', 'between:1,6'],
        ]);

        $tenant->settings = array_replace($tenant->settings ?? [], [
            'expiry_reminder_days' => array_values(array_unique(array_map('intval', $data['expiry_reminder_days'] ?? []))),
            'expired_followup_days' => array_values(array_unique(array_map('intval', $data['expired_followup_days'] ?? []))),
            'notify_class_changes' => (bool) ($data['notify_class_changes'] ?? false),
            'class_reminder_hours' => (int) ($data['class_reminder_hours'] ?? 0),
            'dues_reminders_enabled' => (bool) ($data['dues_reminders_enabled'] ?? false),
            'dues_reminder_before_days' => array_values(array_unique(array_map('intval', $data['dues_reminder_before_days'] ?? []))),
            'dues_reminder_overdue_days' => array_values(array_unique(array_map('intval', $data['dues_reminder_overdue_days'] ?? []))),
            'dues_reminder_max' => (int) ($data['dues_reminder_max'] ?? 3),
        ]);
        $tenant->save();
        app(AuditLogger::class)->logChanges('settings.notifications_updated', $tenant, $before, Arr::only($tenant->settings, self::NOTIFICATION_RULES));

        return back()->with('success', 'تم حفظ قواعد الإشعارات.');
    }
}
