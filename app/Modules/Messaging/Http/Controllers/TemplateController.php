<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\AutoReply;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Messaging\Services\WhatsAppAutomation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Template mapping, auto replies and automation rules ("scenarios") in one place.
 * A template is sent only when it is approved in WhatsApp Manager and active here.
 */
class TemplateController extends Controller
{
    public function index(): Response
    {
        TemplateCatalog::seedDefaults();

        return Inertia::render('Messaging/Templates', [
            'templates' => MessageTemplate::orderBy('id')->get()->map(fn ($t) => $t->only('id', 'key', 'name', 'language', 'category', 'body', 'variables', 'status', 'is_active') + [
                'label' => TemplateCatalog::EVENTS[$t->key]['label'] ?? 'قالب مخصص',
                'is_system' => isset(TemplateCatalog::EVENTS[$t->key]),
                'allowed_variables' => TemplateCatalog::EVENTS[$t->key]['variables'] ?? TemplateCatalog::CAMPAIGN_VARIABLES,
            ]),
        ]);
    }

    /** The automatic notices (each on or off, and when), their templates' state, and keyword auto-replies. */
    public function automation(TenantContext $tenants): Response
    {
        $tenant = $tenants->require();
        TemplateCatalog::seedDefaults();
        $templates = MessageTemplate::get(['key', 'status', 'is_active'])->keyBy('key');

        return Inertia::render('Messaging/Automation', [
            'autoReplies' => AutoReply::orderBy('id')->get(['id', 'keyword', 'match_type', 'reply', 'is_active', 'hits']),
            'events' => collect(WhatsAppAutomation::EVENTS)->map(fn ($e, $key) => [
                'key' => $key,
                'label' => $e['label'],
                'detail' => $e['detail'],
                'on' => WhatsAppAutomation::on($tenant, $key),
                'timing' => $e['timing'] ?? null,
                'templates' => array_map(fn ($t) => [
                    'key' => $t,
                    'label' => TemplateCatalog::EVENTS[$t]['label'] ?? $t,
                    'category' => TemplateCatalog::EVENTS[$t]['category'] ?? 'utility',
                    'approved' => (bool) $templates->get($t)?->isUsable(),
                ], $e['templates']),
            ])->values(),
            'rules' => [
                'expiry_reminder_days' => array_values(array_filter((array) $tenant->setting('expiry_reminder_days'), fn ($d) => (int) $d > 0)),
                'expired_followup_days' => $tenant->setting('expired_followup_days'),
                'hour' => WhatsAppAutomation::hour($tenant),
                'notify_class_changes' => $tenant->setting('notify_class_changes'),
            ],
        ]);
    }

    /** Switch notices on or off and set when the reminders go out. */
    public function saveAutomation(Request $request, TenantContext $tenants, AuditLogger $audit): RedirectResponse
    {
        $tenant = $tenants->require();
        $data = $request->validate([
            'on' => ['array'],
            'on.*' => ['boolean'],
            'expiry_reminder_days' => ['array', 'max:5'],
            'expiry_reminder_days.*' => ['integer', 'between:1,60'],
            'expired_followup_days' => ['array', 'max:5'],
            'expired_followup_days.*' => ['integer', 'between:1,180'],
            'hour' => ['required', 'integer', 'between:0,23'],
        ]);

        $before = ['off' => $tenant->setting('whatsapp_automation.off', []), 'hour' => WhatsAppAutomation::hour($tenant),
            'expiry_reminder_days' => $tenant->setting('expiry_reminder_days'), 'expired_followup_days' => $tenant->setting('expired_followup_days')];
        $off = array_values(array_filter(array_keys(WhatsAppAutomation::EVENTS), fn ($key) => array_key_exists($key, $data['on'] ?? []) && ! $data['on'][$key]));
        $after = [
            'off' => $off,
            'hour' => (int) $data['hour'],
            'expiry_reminder_days' => array_values(array_unique(array_map('intval', $data['expiry_reminder_days'] ?? []))),
            'expired_followup_days' => array_values(array_unique(array_map('intval', $data['expired_followup_days'] ?? []))),
        ];

        $tenant->settings = array_replace($tenant->settings ?? [], [
            'whatsapp_automation' => ['off' => $after['off'], 'hour' => $after['hour']],
            'expiry_reminder_days' => $after['expiry_reminder_days'],
            'expired_followup_days' => $after['expired_followup_days'],
        ]);
        $tenant->save();
        $audit->logChanges('whatsapp.automation_changed', $tenant, $before, $after);

        return back()->with('success', 'تم حفظ إعدادات الرسائل التلقائية.');
    }

    /** Custom (marketing) template. */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['variables'] = array_values(array_intersect($data['variables'] ?? [], TemplateCatalog::CAMPAIGN_VARIABLES));
        MessageTemplate::create($data + ['key' => 'custom_'.substr(md5($data['name'].microtime()), 0, 10)]);

        return back()->with('success', 'تمت إضافة القالب. اعتمده في WhatsApp Manager ثم غيّر حالته إلى «معتمد».');
    }

    public function update(Request $request, MessageTemplate $template): RedirectResponse
    {
        $data = $this->validated($request);
        $allowed = TemplateCatalog::EVENTS[$template->key]['variables'] ?? TemplateCatalog::CAMPAIGN_VARIABLES;
        // Sent (even empty: every variable removed) it is kept; not sent, the stored list stays.
        $data['variables'] = $request->has('variables') ? array_values(array_intersect($data['variables'] ?? [], $allowed)) : ($template->variables ?? []);
        $template->update($data);

        return back()->with('success', 'تم حفظ القالب.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'string', 'max:10'],
            'category' => ['required', 'in:utility,marketing'],
            'body' => ['required', 'string', 'max:1024'],
            'variables' => ['nullable', 'array', 'max:10'],
            'variables.*' => ['string', 'max:40'],
            'status' => ['required', 'in:draft,pending,approved,rejected'],
            'is_active' => ['boolean'],
        ], ['name.regex' => 'اسم القالب كما في WhatsApp Manager: أحرف إنجليزية صغيرة وأرقام و _ فقط.']);
    }
}
