<?php

namespace App\Http\Controllers\Club;

use App\Core\Support\ImageStore;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Club profile, branding and business rules. */
class SettingsController extends Controller
{
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
            'rules.block_access_with_dues' => ['boolean'],
            'rules.auto_mark_no_show' => ['boolean'],
            'rules.expiry_reminder_days' => ['array', 'max:5'],
            'rules.expiry_reminder_days.*' => ['integer', 'between:0,60'],
            'rules.expired_followup_days' => ['array', 'max:5'],
            'rules.expired_followup_days.*' => ['integer', 'between:1,180'],
            'rules.notify_class_changes' => ['boolean'],
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

        $rules = array_intersect_key($data['rules'] ?? [], $tenant::DEFAULT_SETTINGS);
        // Multipart requests (with a logo) drop empty arrays: missing list means "none".
        if ($request->has('rules')) {
            foreach (['expiry_reminder_days', 'expired_followup_days'] as $list) {
                $rules[$list] ??= [];
            }
        }
        unset($data['logo'], $data['rules']);

        $tenant->fill($data);
        $tenant->settings = array_replace($tenant->settings ?? [], $rules);
        $tenant->save();

        return back()->with('success', 'تم حفظ إعدادات النادي.');
    }
}
