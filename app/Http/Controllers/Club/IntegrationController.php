<?php

namespace App\Http\Controllers\Club;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\TenantIntegration;
use App\Modules\Payments\Paymob\PaymobClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WhatsApp and Paymob credentials for this club. Secrets are write-only:
 * the screen shows whether each one is set, never its value. A blank field
 * keeps the stored secret.
 */
class IntegrationController extends Controller
{
    private const SECRETS = [
        'whatsapp' => ['access_token', 'phone_number_id', 'business_account_id'],
        'paymob' => ['secret_key', 'public_key', 'hmac_secret', 'integration_ids'],
    ];

    public function index(TenantContext $tenants): Response
    {
        $rows = TenantIntegration::all()->keyBy('provider');
        $slug = $tenants->require()->slug;

        $out = [];
        foreach (self::SECRETS as $provider => $keys) {
            $row = $rows->get($provider);
            $out[$provider] = [
                'is_enabled' => (bool) $row?->is_enabled,
                'mode' => $row?->mode ?? 'test',
                'present' => $row ? $row->credentialPresence($keys) : array_fill_keys($keys, false),
                'public' => $provider === 'whatsapp'
                    ? ['phone_number_id' => $row?->credential('phone_number_id'), 'display_phone' => $row?->option('display_phone')]
                    : ['public_key' => $row?->credential('public_key') ? substr((string) $row->credential('public_key'), 0, 12).'…' : null],
                'last_error' => $row?->last_error,
            ];
        }

        return Inertia::render('Club/Settings/Integrations', [
            'integrations' => $out,
            'endpoints' => [
                'paymob_webhook' => route('webhooks.paymob'),
                'paymob_return_example' => route('site.pay.return', ['club' => $slug, 'reference' => 'REFERENCE']),
                'whatsapp_webhook' => route('webhooks.whatsapp'),
            ],
            'paymob_ready' => PaymobClient::ready($rows->get('paymob')),
            'whatsapp_driver' => config('services.whatsapp.driver'),
        ]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        $keys = self::SECRETS[$provider];
        $data = $request->validate([
            'is_enabled' => ['boolean'],
            'mode' => ['required', 'in:test,live'],
            'credentials' => ['array'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
            'display_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $integration = TenantIntegration::firstOrNew(['provider' => $provider]);
        $credentials = $integration->credentials ?? [];
        foreach ($keys as $key) {
            $value = trim((string) ($data['credentials'][$key] ?? ''));
            if ($value !== '') {
                $credentials[$key] = $value;
            }
        }

        $integration->fill([
            'is_enabled' => $data['is_enabled'] ?? false,
            'mode' => $data['mode'],
            'credentials' => $credentials,
            'settings' => array_filter(['display_phone' => $data['display_phone'] ?? null]),
            // WhatsApp webhooks are routed to the club by its phone number id.
            'public_identifier' => $provider === 'whatsapp' ? ($credentials['phone_number_id'] ?? null) : null,
        ]);
        $integration->save();

        return back()->with('success', 'تم حفظ إعدادات الربط.');
    }
}
