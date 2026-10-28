<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\AutoReply;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
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

    /** Automatic sending rules (edited in Settings → Notifications) and keyword auto-replies. */
    public function automation(TenantContext $tenants): Response
    {
        $tenant = $tenants->require();

        return Inertia::render('Messaging/Automation', [
            'autoReplies' => AutoReply::orderBy('id')->get(['id', 'keyword', 'match_type', 'reply', 'is_active', 'hits']),
            'rules' => [
                'expiry_reminder_days' => $tenant->setting('expiry_reminder_days'),
                'expired_followup_days' => $tenant->setting('expired_followup_days'),
                'notify_class_changes' => $tenant->setting('notify_class_changes'),
            ],
        ]);
    }

    /** Custom (marketing) template. */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['variables'] = array_values(array_intersect($data['variables'] ?? [], TemplateCatalog::CAMPAIGN_VARIABLES));
        MessageTemplate::create($data + ['key' => 'custom_'.substr(md5($data['name'].microtime()), 0, 10)]);

        return back()->with('success', 'تمت إضافة القالب. اعتمديه في WhatsApp Manager ثم غيّري حالته إلى «معتمد».');
    }

    public function update(Request $request, MessageTemplate $template): RedirectResponse
    {
        $data = $this->validated($request);
        $allowed = TemplateCatalog::EVENTS[$template->key]['variables'] ?? TemplateCatalog::CAMPAIGN_VARIABLES;
        $data['variables'] = array_values(array_intersect($data['variables'] ?? [], $allowed)) ?: ($template->variables ?? []);
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
