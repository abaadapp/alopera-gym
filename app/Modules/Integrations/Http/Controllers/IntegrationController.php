<?php

namespace App\Modules\Integrations\Http\Controllers;

use App\Core\Auth\PermissionRegistry;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\TenantIntegration;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Gym\Models\Member;
use App\Modules\Integrations\Services\IntegrationHub;
use App\Modules\Inventory\Services\ShopSettings;
use App\Modules\Messaging\Gateways\MetaCloudGateway;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Paymob\PaymobClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «التطبيقات التكاملية»: three cards, each opening its own settings. Paymob and WhatsApp keys are write-only — the
 * screens learn which fields are filled, never a value, and a blank field keeps what is stored. Changing a key or
 * the mode drops the earlier verification: the club tests the new keys before the card says «connected».
 */
class IntegrationController extends Controller
{
    private const SECRETS = [
        'whatsapp' => ['access_token', 'phone_number_id', 'business_account_id'],
        'paymob' => ['secret_key', 'public_key', 'hmac_secret', 'integration_ids'],
    ];

    /** What each payment channel is called on the Paymob screen. */
    private const CHANNELS = ['portal' => 'بوابة الأعضاء', 'website' => 'الاشتراك من الموقع', 'whatsapp' => 'رابط دفع فاتورة', 'shop' => 'متجر الموقع'];

    private const WEBHOOK_OUTCOMES = [
        'processed' => 'سُجّلت الدفعة', 'duplicate' => 'مكرر — لم يُسجَّل مرة أخرى', 'failed_payment' => 'دفع مرفوض من البنك',
        'amount_mismatch' => 'المبلغ لا يطابق — لم يُسجَّل', 'rejected_signature' => 'توقيع غير صحيح — رُفض', 'unknown_intent' => 'عملية غير معروفة',
    ];

    public function __construct(private readonly IntegrationHub $hub, private readonly TenantContext $tenants) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('integrations.manage') || $user->can('devices.manage'), 403);

        return Inertia::render('Integrations/Index', ['cards' => $this->hub->cards($user)]);
    }

    public function paymob(): Response
    {
        $tenant = $this->tenants->require();
        $row = TenantIntegration::where('provider', TenantIntegration::PAYMOB)->first();
        $ready = PaymobClient::ready($row);
        $shop = PermissionRegistry::moduleEnabled('sales') ? ShopSettings::of($tenant) : null;

        return Inertia::render('Integrations/Paymob', [
            'integration' => $this->form('paymob', $row),
            'status' => $this->hub->paymob($row),
            'warning' => $row ? PaymobClient::modeMismatch($row) : null,
            'checks' => [
                ['label' => 'المفاتيح الأربعة مُدخلة', 'ok' => $row !== null && array_filter(PaymobClient::REQUIRED, fn ($k) => $row->credential($k) === null) === [], 'at' => null],
                ['label' => 'قبلت Paymob المفتاح السري وأرقام الربط', 'ok' => $row?->verified_at !== null && ! $row->failing(), 'at' => $row?->verified_at?->format('Y-m-d H:i')],
                ['label' => 'وصل إشعار دفع موقّع (يثبت HMAC secret)', 'ok' => $row?->last_webhook_at !== null, 'at' => $row?->last_webhook_at?->format('Y-m-d H:i')],
            ],
            'usage' => [
                ['label' => 'تجديد العضويات والباقات من بوابة الأعضاء', 'on' => $ready],
                ['label' => 'الاشتراك الجديد من موقع النادي', 'on' => $ready && (bool) $tenant->setting('member_signup_enabled', true)],
                ['label' => 'دفع فواتير النادي برابط الفاتورة', 'on' => $ready],
                ['label' => 'طلبات متجر الموقع الإلكتروني', 'on' => $ready && $shop !== null && $shop['shop_enabled'] && $shop['online_payment_enabled'], 'route' => $shop !== null ? 'club.settings.sales' : null],
            ],
            'intents' => PaymentIntent::with('invoice:id,number')->latest('id')->limit(15)->get()->map(fn (PaymentIntent $i) => [
                'id' => $i->id,
                'reference' => $i->reference,
                'invoice' => $i->invoice?->number,
                'channel' => self::CHANNELS[$i->channel] ?? $i->channel,
                'amount' => $i->amount,
                'currency' => $i->currency,
                'status' => $i->status,
                'failure' => $i->failure_reason,
                'at' => $i->created_at->format('Y-m-d H:i'),
            ]),
            // Callbacks are logged before the club is known; only this club's are listed, and never their payload.
            'events' => PaymentWebhookEvent::where('tenant_id', $tenant->id)->where('provider', 'paymob')->latest('id')->limit(15)
                ->get(['id', 'reference', 'signature_valid', 'outcome', 'created_at'])
                ->map(fn (PaymentWebhookEvent $e) => [
                    'id' => $e->id, 'reference' => $e->reference, 'valid' => $e->signature_valid,
                    'outcome' => self::WEBHOOK_OUTCOMES[$e->outcome] ?? $e->outcome, 'ok' => in_array($e->outcome, ['processed', 'duplicate'], true),
                    'at' => $e->created_at->format('Y-m-d H:i'),
                ]),
            'endpoints' => [
                'webhook' => route('webhooks.paymob'),
                'return' => route('site.pay.return', ['club' => $tenant->slug, 'reference' => 'REFERENCE']),
            ],
            'currency' => $tenant->currency,
        ]);
    }

    public function whatsapp(): Response
    {
        $row = TenantIntegration::where('provider', TenantIntegration::WHATSAPP)->first();
        TemplateCatalog::seedDefaults();
        $templates = MessageTemplate::get(['key', 'status', 'is_active'])->keyBy('key');
        $tpl = fn (array $keys) => array_map(fn ($key) => [
            'key' => $key,
            'label' => TemplateCatalog::EVENTS[$key]['label'] ?? $key,
            'state' => ! $templates->has($key) ? 'missing' : ($templates[$key]->isUsable() ? 'approved' : ($templates[$key]->is_active ? $templates[$key]->status : 'inactive')),
        ], $keys);
        $tenant = $this->tenants->require();
        $since = now()->subDays(30);

        return Inertia::render('Integrations/WhatsApp', [
            'integration' => $this->form('whatsapp', $row),
            'status' => $this->hub->whatsapp($row),
            'number' => $row ? array_filter(['verified_name' => $row->option('verified_name'), 'display_phone' => $row->option('display_phone'), 'quality_rating' => $row->option('quality_rating')]) : [],
            'platform' => [
                'driver' => config('services.whatsapp.driver'),
                'app_secret' => (bool) config('services.whatsapp.app_secret'),
                'verify_token' => (bool) config('services.whatsapp.verify_token'),
                'last_webhook_at' => $row?->last_webhook_at?->format('Y-m-d H:i'),
            ],
            'endpoints' => ['webhook' => route('webhooks.whatsapp')],
            'features' => [
                ['key' => 'inbox', 'label' => 'محادثات الأعضاء', 'detail' => 'رسائل الأعضاء تصل صندوق المحادثات، والرد الحر مسموح خلال 24 ساعة من آخر رسالة للعضو فقط.', 'route' => 'club.inbox.index', 'templates' => []],
                ['key' => 'renewals', 'label' => 'تجديد العضويات', 'detail' => 'تذكير قبل انتهاء العضوية بـ '.implode(' و', (array) $tenant->setting('expiry_reminder_days', [])).' أيام، ومتابعة بعد الانتهاء، وتأكيد التجديد.', 'route' => 'club.settings.notifications', 'templates' => $tpl(['membership_expiring', 'membership_expired', 'membership_followup', 'membership_renewed'])],
                ['key' => 'classes', 'label' => 'تذكير الحصص', 'detail' => ((int) $tenant->setting('class_reminder_hours', 0)) > 0 ? 'تذكير قبل الحصة المحجوزة بـ '.(int) $tenant->setting('class_reminder_hours').' ساعات، وتأكيد الحجز، وإشعار تعديل الحصة.' : 'تذكير الحصص متوقف. فعّله من إعدادات الإشعارات.', 'route' => 'club.settings.notifications', 'templates' => $tpl(['class_reminder', 'booking_confirmed', 'class_changed'])],
                ['key' => 'documents', 'label' => 'تنبيهات المستندات', 'detail' => DocumentService::settings($tenant)['whatsapp'] ? 'تصل الموظف المسؤول عن المستند إن كان مخوّلًا بالاطلاع على المستندات.' : 'إرسال تنبيهات المستندات بواتساب متوقف. فعّله من إعدادات المستندات.', 'route' => 'club.settings.documents', 'templates' => $tpl(['document_expiry'])],
            ],
            'consent' => [
                'members' => Member::count(),
                'whatsapp' => Member::where('whatsapp_opt_in', true)->count(),
                'marketing' => Member::where('marketing_opt_in', true)->whereNull('marketing_opt_out_at')->count(),
            ],
            'stats' => Message::where('direction', 'outbound')->where('created_at', '>=', $since)
                ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')
                // What the development log driver «sent» never reached WhatsApp.
                ->put('logged', Message::where('direction', 'outbound')->where('created_at', '>=', $since)->where('provider_message_id', 'like', 'log.%')->count()),
            'log' => Message::with('member:id,full_name')->where('direction', 'outbound')->latest('id')->limit(20)->get()->map(fn (Message $m) => [
                'id' => $m->id,
                'to' => $m->member?->full_name ?? ($m->to_phone ? '…'.substr((string) $m->to_phone, -4) : '—'),
                'what' => $m->type === 'template' ? (TemplateCatalog::EVENTS[$m->template_key]['label'] ?? $m->template_name ?? $m->template_key) : 'رد من المحادثات',
                'status' => $m->status,
                'logged' => str_starts_with((string) $m->provider_message_id, 'log.'),
                'error' => $m->error_code,
                'error_message' => $m->error_message,
                'at' => $m->created_at->format('Y-m-d H:i'),
            ]),
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
            'credentials.integration_ids' => ['nullable', 'string', 'max:200', 'regex:/^[\d\s,]*$/'],
            'display_phone' => ['nullable', 'string', 'max:32'],
        ], ['credentials.integration_ids.regex' => 'أرقام الربط أرقام فقط، مفصولة بفاصلة.']);

        $integration = TenantIntegration::firstOrNew(['provider' => $provider]);
        $credentials = $integration->credentials ?? [];
        $changed = $integration->mode !== $data['mode'] && $provider === 'paymob';
        foreach ($keys as $key) {
            $value = trim((string) ($data['credentials'][$key] ?? ''));
            if ($value !== '' && ($credentials[$key] ?? null) !== $value) {
                $credentials[$key] = $value;
                $changed = true;
            }
        }

        $settings = array_merge($integration->settings ?? [], ['display_phone' => $data['display_phone'] ?? null]);
        if ($changed) {
            // New keys are unproven: the club tests them before the card says «connected».
            unset($settings['verified_name'], $settings['quality_rating']);
            $integration->forceFill(['verified_at' => null, 'last_error' => null, 'last_error_at' => null]);
        }

        $integration->fill([
            'is_enabled' => $data['is_enabled'] ?? false,
            'mode' => $data['mode'],
            'credentials' => $credentials,
            'settings' => array_filter($settings, fn ($v) => $v !== null && $v !== ''),
            // WhatsApp webhooks are routed to the club by its phone number id.
            'public_identifier' => $provider === 'whatsapp' ? ($credentials['phone_number_id'] ?? null) : null,
        ]);
        $integration->save();

        return back()->with('success', $changed ? 'تم حفظ الإعدادات. اضغط «اختبار الاتصال» للتحقق من المفاتيح.' : 'تم حفظ الإعدادات.');
    }

    /** Test the stored keys with the provider itself. Nothing is paid and nothing is sent to anyone. */
    public function verify(string $provider): RedirectResponse
    {
        $row = TenantIntegration::where('provider', $provider)->first();
        if (! $row) {
            return back()->with('error', 'احفظ المفاتيح أولًا.');
        }

        if ($provider === 'paymob') {
            $result = app(PaymobClient::class)->probe($row, $this->tenants->require()->currency);
        } else {
            $result = app(MetaCloudGateway::class)->describeNumber($row);
            if ($result['ok']) {
                $number = $result['number'];
                $row->markVerified(array_filter([
                    'verified_name' => $number['verified_name'] ?? null,
                    'quality_rating' => $number['quality_rating'] ?? null,
                    'display_phone' => $row->option('display_phone') ?: ($number['display_phone'] ?? null),
                ]));
            } elseif ($row->credential('phone_number_id') && $row->credential('access_token')) {
                $row->markFailed($result['message']);
            }
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** @return array<string, mixed> */
    private function form(string $provider, ?TenantIntegration $row): array
    {
        $keys = self::SECRETS[$provider];

        return [
            'is_enabled' => (bool) $row?->is_enabled,
            'mode' => $row?->mode ?? 'test',
            'present' => $row ? $row->credentialPresence($keys) : array_fill_keys($keys, false),
            'public' => $provider === 'whatsapp'
                ? ['phone_number_id' => $row?->credential('phone_number_id'), 'display_phone' => $row?->option('display_phone')]
                : ['public_key' => $row?->credential('public_key') ? substr((string) $row->credential('public_key'), 0, 12).'…' : null],
            'last_error' => $row?->last_error,
            'last_error_at' => $row?->last_error_at?->format('Y-m-d H:i'),
            'last_checked_at' => $row?->last_checked_at?->format('Y-m-d H:i'),
        ];
    }
}
