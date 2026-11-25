<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\MessageGuide;
use App\Modules\Messaging\Services\MetaTemplates;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Messaging\Services\WhatsAppAutomation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «الإعدادات ← رسائل الواتساب» (owner's decision, 2026-11-25): every message the system sends, what it is for, when and
 * how it goes out, and its text — edited here and sent to Meta for review on saving, «قيد المراجعة» until Meta approves
 * it. A message is never deleted from here.
 */
class WhatsAppMessagesController extends Controller
{
    /** How often opening the page reads the states from Meta again. */
    private const FRESH_MINUTES = 3;

    public function index(TenantContext $tenants, MetaTemplates $meta): Response
    {
        $tenant = $tenants->require();
        TemplateCatalog::seedDefaults();
        $unavailable = $meta->unavailable();
        $syncError = null;
        $stale = MessageTemplate::query()->where(fn ($q) => $q->whereNull('meta_synced_at')->orWhere('meta_synced_at', '<', now()->subMinutes(self::FRESH_MINUTES)))->exists();
        if ($unavailable === null && $stale) {
            try {
                $meta->sync();
            } catch (BusinessRuleException $e) {
                $syncError = $e->getMessage();
            }
        }

        $events = collect(WhatsAppAutomation::EVENTS);
        $order = array_flip(array_keys(MessageGuide::MESSAGES));
        $messages = MessageTemplate::query()->get()
            ->sortBy(fn (MessageTemplate $t) => $order[$t->key] ?? 1000 + $t->id)->values()
            ->map(function (MessageTemplate $t) use ($events, $tenant) {
                $guide = MessageGuide::MESSAGES[$t->key] ?? ['group' => 'campaigns', 'purpose' => 'رسالة تختارينها عند إرسال حملة لعضوات.', 'when' => 'عندما ترسلين حملة تستخدمها.', 'how' => 'يدويًا من «الحملات»', 'to' => 'العضوات اللواتي تختارينهن'];
                $event = $events->search(fn ($e) => in_array($t->key, $e['templates'], true));

                return [
                    'id' => $t->id,
                    'key' => $t->key,
                    'title' => TemplateCatalog::EVENTS[$t->key]['label'] ?? $t->name,
                    'meta_name' => $t->name,
                    'language' => $t->language,
                    'category' => $t->category,
                    'body' => $t->body,
                    'status' => $t->status,
                    'rejection_reason' => $t->rejection_reason,
                    'submitted_at' => $t->submitted_at?->format('Y-m-d H:i'),
                    // Switched off in «الرسائل التلقائية»: the message is not sent even when approved.
                    'automatic_off' => $event !== false && ! WhatsAppAutomation::on($tenant, $event),
                    'values' => collect((array) $t->variables)->values()->map(fn ($v, $i) => ['n' => $i + 1, 'name' => $v, 'label' => MessageGuide::value($v)[0], 'example' => MessageGuide::value($v)[1]])->all(),
                ] + $guide;
            });

        return Inertia::render('Club/Settings/WhatsAppMessages', [
            'groups' => MessageGuide::GROUPS + ['campaigns' => 'رسائل الحملات'],
            'messages' => $messages,
            'unavailable' => $unavailable,
            'syncError' => $syncError,
        ]);
    }

    /** Saves the new text and sends it to Meta for review. */
    public function update(Request $request, MessageTemplate $template, MetaTemplates $meta): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $meta->submit($template, $data['body']);

        return back()->with('success', 'حُفظت الرسالة وأُرسلت إلى Meta للمراجعة. تبقى «قيد المراجعة» حتى توافق عليها Meta، وعادةً خلال دقائق إلى 24 ساعة.');
    }

    /** Reads every message's state from Meta now. */
    public function refresh(MetaTemplates $meta): RedirectResponse
    {
        if (($why = $meta->unavailable()) !== null) {
            return back()->with('error', $why);
        }
        $changed = $meta->sync();

        return back()->with('success', $changed > 0 ? 'تم تحديث حالة الرسائل من Meta.' : 'الحالات محدّثة، لا جديد من Meta.');
    }
}
