<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\TenantIntegration;
use App\Modules\Messaging\Gateways\MetaCloudGateway;
use App\Modules\Messaging\Gateways\WhatsAppGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * WhatsApp settings beyond the keys (whatsapp.manage_settings or the integrations): whether the history and the
 * contacts the WhatsApp Business app shares may be imported, asking for that share once (Coexistence, within 24 hours
 * of connecting), and the secret header 360dialog sends with every webhook.
 */
class WhatsAppSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function imports(Request $request): RedirectResponse
    {
        $data = $request->validate(['import_history' => ['boolean'], 'import_contacts' => ['boolean']]);
        $integration = $this->integration();
        $integration->forceFill(['settings' => array_merge($integration->settings ?? [], [
            'import_history' => (bool) ($data['import_history'] ?? false),
            'import_contacts' => (bool) ($data['import_contacts'] ?? false),
        ])])->save();
        $this->audit->log('whatsapp.imports_changed', $integration, $data);

        return back()->with('success', 'تم حفظ إعدادات الاستيراد.');
    }

    /** Ask WhatsApp, once, for the phone's contacts or past chats; they arrive by webhook over the next hours. */
    public function sync(Request $request, WhatsAppGateway $gateway): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', 'in:history,smb_app_state_sync']]);
        $integration = $this->integration();
        $allowed = $data['type'] === 'history' ? $integration->option('import_history') : $integration->option('import_contacts');
        if (! $allowed) {
            return back()->with('error', 'فعّل الاستيراد أولًا: لا يُطلب من الهاتف ما لن يُحفظ.');
        }

        $result = $gateway->requestSync($integration, $data['type']);
        $key = $data['type'] === 'history' ? 'history' : 'contacts';
        $integration->forceFill(['settings' => array_merge($integration->settings ?? [], [
            $key.'_requested_at' => $result->ok ? now()->toIso8601String() : $integration->option($key.'_requested_at'),
            $key.'_request_id' => $result->ok ? $result->messageId : $integration->option($key.'_request_id'),
        ])])->save();
        $this->audit->log('whatsapp.sync_requested', $integration, ['type' => $data['type'], 'ok' => $result->ok, 'error' => $result->errorCode]);

        return back()->with($result->ok ? 'success' : 'error', $result->ok
            ? 'طُلبت المزامنة. تصل البيانات خلال الساعات القادمة إن وافق صاحب الرقم من تطبيق WhatsApp Business.'
            : 'لم تُقبل المزامنة: '.$result->errorMessage);
    }

    /** A new secret header for 360dialog's webhook: shown once, stored encrypted; the old one stops working. */
    public function token(): RedirectResponse
    {
        $integration = $this->integration();
        if ($integration->option('vendor') !== MetaCloudGateway::VENDOR_360DIALOG) {
            return back()->with('error', 'الرمز لمزود 360dialog فقط.');
        }
        $token = Str::random(48);
        $integration->forceFill(['credentials' => array_merge($integration->credentials ?? [], ['webhook_token' => $token])])->save();
        $this->audit->log('whatsapp.webhook_token_rotated', $integration);

        return back()->with('success', 'أُنشئ رمز جديد. انسخه الآن، فلن يظهر مرة أخرى.')->with('webhook_token', $token);
    }

    private function integration(): TenantIntegration
    {
        $integration = TenantIntegration::where('provider', TenantIntegration::WHATSAPP)->first();
        abort_if($integration === null, 404, 'احفظ مفاتيح واتساب أولًا.');

        return $integration;
    }
}
