<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** تنبيهات المستندات: how many days before expiry to alert, and whether the alert also goes over WhatsApp. */
class DocumentSettingsController extends Controller
{
    public function edit(TenantContext $tenants, WhatsAppService $whatsapp): Response
    {
        $template = MessageTemplate::where('key', 'document_expiry')->first();

        return Inertia::render('Club/Settings/Documents', [
            'settings' => DocumentService::settings($tenants->require()),
            'whatsapp' => ['enabled' => $whatsapp->enabled(), 'template' => $template?->isUsable() ?? false],
        ]);
    }

    public function update(Request $request, TenantContext $tenants): RedirectResponse
    {
        $data = $request->validate([
            'alert_days' => ['required', 'array', 'min:1', 'max:8'],
            'alert_days.*' => ['integer', 'min:1', 'max:365'],
            'whatsapp' => ['boolean'],
        ]);
        $tenant = $tenants->require();
        $settings = $tenant->settings ?? [];
        $settings['documents'] = [
            'alert_days' => collect($data['alert_days'])->map(fn ($d) => (int) $d)->unique()->sortDesc()->values()->all(),
            'whatsapp' => (bool) ($data['whatsapp'] ?? false),
        ];
        $tenant->forceFill(['settings' => $settings])->save();

        return back()->with('success', 'حُفظت إعدادات تنبيهات المستندات.');
    }
}
