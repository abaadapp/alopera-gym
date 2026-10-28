<?php

namespace App\Http\Controllers\Club;

use App\Core\Support\ImageStore;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/** The settings hub, the club profile and business rules, and the notification rules. */
class SettingsController extends Controller
{
    /** Opening the hub needs at least one of the screens it links to. */
    public const HUB_PERMISSIONS = [
        'settings.manage', 'branches.manage', 'users.manage', 'roles.manage', 'integrations.manage',
        'audit.view', 'backups.manage', 'billing.view', 'website.manage',
    ];

    private const NOTIFICATION_RULES = ['expiry_reminder_days', 'expired_followup_days', 'notify_class_changes'];

    public function index(Request $request): Response
    {
        abort_unless(collect(self::HUB_PERMISSIONS)->contains(fn (string $p) => $request->user()->can($p)), 403);

        return Inertia::render('Club/Settings/Index');
    }

    public function edit(TenantContext $tenants): Response
    {
        $tenant = $tenants->require();

        return Inertia::render('Club/Settings/General', [
            'club' => $tenant->only('name', 'legal_name', 'email', 'phone', 'address', 'city', 'primary_color', 'secondary_color', 'latitude', 'longitude', 'domain') + [
                'logo_url' => $tenant->logoUrl(),
            ],
            'rules' => $tenant->allSettings(),
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
            'logo' => ['nullable', 'image', 'max:'.config('gym.max_image_kb')],
            'rules' => ['array'],
            'rules.booking_window_days' => ['integer', 'between:1,90'],
            'rules.booking_cancel_cutoff_hours' => ['integer', 'between:0,72'],
            'rules.checkin_class_window_minutes' => ['integer', 'between:0,240'],
            'rules.duplicate_scan_minutes' => ['integer', 'between:0,120'],
            'rules.block_access_with_dues' => ['boolean'],
            'rules.auto_mark_no_show' => ['boolean'],
            'rules.class_session_weeks_ahead' => ['integer', 'between:1,12'],
            'rules.vat_rate' => ['numeric', 'between:0,30'],
            'rules.vat_number' => ['nullable', 'string', 'max:40'],
            'rules.invoice_footer' => ['nullable', 'string', 'max:500'],
            'rules.invoice_due_days' => ['integer', 'between:0,90'],
            'rules.allow_pay_at_reception' => ['boolean'],
            'rules.member_signup_enabled' => ['boolean'],
        ]);

        if ($request->hasFile('logo')) {
            ImageStore::delete($tenant->logo_path);
            $tenant->logo_path = ImageStore::store($request->file('logo'), 'branding', 600);
        }

        // Notification rules have their own screen; this form never touches them.
        $rules = Arr::except(array_intersect_key($data['rules'] ?? [], $tenant::DEFAULT_SETTINGS), self::NOTIFICATION_RULES);
        unset($data['logo'], $data['rules']);

        $tenant->fill($data);
        $tenant->settings = array_replace($tenant->settings ?? [], $rules);
        $tenant->save();

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
        $data = $request->validate([
            'expiry_reminder_days' => ['array', 'max:5'],
            'expiry_reminder_days.*' => ['integer', 'between:0,60'],
            'expired_followup_days' => ['array', 'max:5'],
            'expired_followup_days.*' => ['integer', 'between:1,180'],
            'notify_class_changes' => ['boolean'],
        ]);

        $tenant->settings = array_replace($tenant->settings ?? [], [
            'expiry_reminder_days' => array_values(array_unique(array_map('intval', $data['expiry_reminder_days'] ?? []))),
            'expired_followup_days' => array_values(array_unique(array_map('intval', $data['expired_followup_days'] ?? []))),
            'notify_class_changes' => (bool) ($data['notify_class_changes'] ?? false),
        ]);
        $tenant->save();

        return back()->with('success', 'تم حفظ قواعد الإشعارات.');
    }
}
