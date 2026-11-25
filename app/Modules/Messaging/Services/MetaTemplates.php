<?php

namespace App\Modules\Messaging\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Models\TenantIntegration;
use App\Modules\Messaging\Models\MessageTemplate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The club's WhatsApp message templates at Meta (WhatsApp Business Management API, the club's own system-user token and
 * WhatsApp Business account id):
 *
 *   submit  a message's new text goes to Meta for review — the template edited where Meta has it (its header, footer
 *           and buttons kept), created where it does not — and is «قيد المراجعة» here until Meta answers. Until then the
 *           system does not send it (only an approved text is sent: MessageTemplate::isUsable).
 *   sync    every template's state (and approved text) as Meta has it now: on opening the page, every few minutes, and
 *           at once when Meta's webhook says a template was approved or refused (applyUpdate).
 *
 * Nothing is ever deleted at Meta or here.
 */
final class MetaTemplates
{
    /** Meta's states, as the system keeps them. Paused or disabled: not sent, like a refused one. */
    public const STATES = ['APPROVED' => 'approved', 'PENDING' => 'pending', 'IN_APPEAL' => 'pending', 'REJECTED' => 'rejected', 'PAUSED' => 'paused', 'DISABLED' => 'disabled'];

    /** Meta's reasons for a refusal, said plainly. */
    public const REASONS = [
        'INVALID_FORMAT' => 'صيغة النص غير مقبولة (مثلًا: يبدأ أو ينتهي ببيان متغيّر، أو بيانات متتالية دون نص بينها).',
        'TAG_CONTENT_MISMATCH' => 'نوع الرسالة لا يطابق محتواها (مثلًا: رسالة خدمة فيها عرض تسويقي).',
        'ABUSIVE_CONTENT' => 'رأت Meta أن المحتوى مخالف لسياساتها.',
        'SCAM' => 'رأت Meta أن المحتوى قد يكون مضللًا.',
        'INCORRECT_CATEGORY' => 'تصنيف الرسالة غير صحيح.',
        'PROMOTIONAL' => 'الرسالة تبدو ترويجية بينما تصنيفها خدمة.',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function integration(): ?TenantIntegration
    {
        return TenantIntegration::where('provider', TenantIntegration::WHATSAPP)->first();
    }

    /** Why templates cannot be sent to Meta from here now, or null when they can. */
    public function unavailable(?TenantIntegration $integration = null): ?string
    {
        $integration ??= $this->integration();

        return match (true) {
            $integration === null || ! $integration->is_enabled => 'واتساب غير مربوط بعد. اربطه من «التطبيقات التكاملية ← واتساب».',
            $integration->option('vendor') === '360dialog' => 'إرسال الرسائل للمراجعة من هنا متاح مع ربط Meta المباشر فقط.',
            ! $integration->credential('access_token') => 'رمز الوصول (Access token) غير محفوظ في ربط واتساب.',
            ! $integration->option('waba_id') => 'رقم حساب WhatsApp Business (WABA ID) غير محفوظ في ربط واتساب.',
            default => null,
        };
    }

    /**
     * Send a message's new text to Meta for review. The text uses {{1}}, {{2}}… in the order of the template's values;
     * every value must stay in it (the system always sends them all). Nothing changes here if Meta refuses the request.
     *
     * @throws BusinessRuleException with what to fix, or what Meta answered
     */
    public function submit(MessageTemplate $template, string $body): MessageTemplate
    {
        $body = self::clean($body);
        self::check($template, $body);
        if ($template->status === 'pending') {
            throw new BusinessRuleException('templates.pending', 'هذه الرسالة قيد المراجعة لدى Meta الآن، ولا تقبل Meta تعديلها حتى ترد. حاولي بعد الموافقة أو الرفض.', 'body');
        }
        $integration = $this->integration();
        if (($why = $this->unavailable($integration)) !== null) {
            throw new BusinessRuleException('templates.unavailable', $why, 'body');
        }

        $bodyComponent = ['type' => 'BODY', 'text' => $body];
        $examples = array_map(fn ($v) => MessageGuide::value($v)[1], array_values((array) $template->variables));
        if ($examples !== []) {
            $bodyComponent['example'] = ['body_text' => [$examples]];
        }

        $existing = $this->find($integration, $template);
        if ($existing) {
            // Edited where it is: its other parts (a header, a footer, buttons) stay as Meta has them.
            $components = collect((array) ($existing['components'] ?? []))->reject(fn ($c) => strtoupper((string) ($c['type'] ?? '')) === 'BODY')
                ->map(fn ($c) => self::resubmittable($c))->filter()->values()->all();
            $response = $this->request(fn () => $this->client($integration)->post($this->root().'/'.$existing['id'], ['components' => [...$this->ordered($components, $bodyComponent)]]));
            $metaId = (string) $existing['id'];
        } else {
            $response = $this->request(fn () => $this->client($integration)->post($this->root().'/'.$integration->option('waba_id').'/message_templates', [
                'name' => $template->name,
                'language' => $template->language,
                'category' => strtoupper($template->category === 'marketing' ? 'MARKETING' : 'UTILITY'),
                'components' => [$bodyComponent],
            ]));
            $metaId = (string) ($response['id'] ?? '');
        }

        $before = $template->only('body', 'status');
        $template->forceFill([
            'body' => $body,
            'status' => 'pending',
            'meta_id' => $metaId !== '' ? $metaId : $template->meta_id,
            'rejection_reason' => null,
            'submitted_at' => now(),
        ])->save();
        $this->audit->log('whatsapp.template_submitted', $template, ['before' => $before, 'meta_id' => $template->meta_id]);

        return $template;
    }

    /** Every template's state and approved text as Meta has them. Returns how many changed here. */
    public function sync(): int
    {
        $integration = $this->integration();
        if ($this->unavailable($integration) !== null) {
            return 0;
        }

        $remote = [];
        $url = $this->root().'/'.$integration->option('waba_id').'/message_templates';
        $query = ['fields' => 'id,name,language,status,category,rejected_reason,components', 'limit' => 200];
        for ($page = 0; $url && $page < 10; $page++) {
            $data = $this->request(fn () => $this->client($integration)->get($url, $query));
            foreach ((array) ($data['data'] ?? []) as $row) {
                $remote[strtolower((string) ($row['name'] ?? '')).'|'.strtolower((string) ($row['language'] ?? ''))] = $row;
            }
            $url = Arr::get($data, 'paging.next');
            $query = [];
        }

        $changed = 0;
        foreach (MessageTemplate::all() as $template) {
            $row = $remote[strtolower($template->name).'|'.strtolower($template->language)] ?? null;
            if ($row === null) {
                $template->forceFill(['meta_synced_at' => now()])->save();

                continue;
            }
            $changed += (int) $this->apply($template, (string) ($row['status'] ?? ''), (string) ($row['rejected_reason'] ?? ''), (string) ($row['id'] ?? ''), self::bodyOf($row));
        }

        return $changed;
    }

    /**
     * Meta's webhook (message_template_status_update): one template approved, refused, paused… now.
     *
     * @param  array<string, mixed>  $value
     */
    public function applyUpdate(array $value): int
    {
        $id = (string) ($value['message_template_id'] ?? '');
        $name = (string) ($value['message_template_name'] ?? '');
        $language = (string) ($value['message_template_language'] ?? '');
        $template = ($id !== '' ? MessageTemplate::where('meta_id', $id)->first() : null)
            ?? ($name !== '' ? MessageTemplate::where('name', $name)->when($language !== '', fn ($q) => $q->where('language', $language))->first() : null);

        return $template ? (int) $this->apply($template, (string) ($value['event'] ?? ''), (string) ($value['reason'] ?? ''), $id, null) : 0;
    }

    private function apply(MessageTemplate $template, string $metaState, string $reason, string $metaId, ?string $body): bool
    {
        $state = self::STATES[strtoupper($metaState)] ?? null;
        $fill = ['meta_synced_at' => now()];
        if ($metaId !== '') {
            $fill['meta_id'] = $metaId;
        }
        if ($state !== null) {
            $fill['status'] = $state;
            $fill['rejection_reason'] = $state === 'rejected' ? (self::REASONS[strtoupper($reason)] ?? ($reason !== '' && strtoupper($reason) !== 'NONE' ? $reason : 'لم تذكر Meta السبب.')) : null;
        }
        // The text Meta approved is the one sent: one edited in WhatsApp Manager comes here too.
        $count = count((array) $template->variables);
        $fits = $body !== null && self::placeholders($body) === ($count > 0 ? range(1, $count) : []);
        if ($state === 'approved' && $fits && $body !== '' && $body !== $template->body) {
            $fill['body'] = $body;
        }
        $before = $template->only('status', 'body');
        $template->forceFill($fill)->save();
        $changed = $before !== $template->only('status', 'body');
        if ($changed) {
            $this->audit->as('gateway', null, 'meta', fn () => $this->audit->log('whatsapp.template_reviewed', $template, ['before' => $before, 'status' => $template->status]));
        }

        return $changed;
    }

    /**
     * What is wrong with a message's text, as the owner can fix it: every value of the message in it, nothing else in
     * double braces, not starting or ending with a value (Meta refuses that), within Meta's length.
     *
     * @throws BusinessRuleException
     */
    public static function check(MessageTemplate $template, string $body): void
    {
        $fail = fn (string $message) => throw new BusinessRuleException('templates.body', $message, 'body');
        if (trim($body) === '') {
            $fail('اكتبي نص الرسالة.');
        }
        if (mb_strlen($body) > 1024) {
            $fail('النص أطول من 1024 حرفًا، وهو أقصى ما تقبله Meta.');
        }
        $variables = array_values((array) $template->variables);
        $used = self::placeholders($body);
        $missing = $variables === [] ? [] : array_diff(range(1, count($variables)), $used);
        if ($missing !== []) {
            $fail('يجب أن تبقى كل البيانات في الرسالة، فالنظام يرسلها كلها. الناقص: '.implode('، ', array_map(fn ($n) => '['.MessageGuide::value($variables[$n - 1] ?? '')[0].']', $missing)).'.');
        }
        if (array_diff($used, $variables === [] ? [] : range(1, count($variables))) !== []) {
            $fail('في النص بيان غير معروف لهذه الرسالة. استخدمي أزرار البيانات تحت النص فقط.');
        }
        if (preg_match('/^\s*\{\{\d+\}\}|\{\{\d+\}\}[\s.!،؟?]*$/u', $body)) {
            $fail('لا تقبل Meta أن تبدأ الرسالة أو تنتهي ببيان مثل [اسم العضوة]: أضيفي كلمة قبله أو بعده.');
        }
        if (preg_match('/\{\{\d+\}\}\s*\{\{\d+\}\}/', $body)) {
            $fail('لا تقبل Meta بيانين متتاليين دون كلام بينهما.');
        }
    }

    /** @return list<int> the {{n}} a text uses, sorted, each once */
    public static function placeholders(string $body): array
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m);
        $used = array_values(array_unique(array_map('intval', $m[1])));
        sort($used);

        return $used;
    }

    private static function clean(string $body): string
    {
        $body = str_replace("\r\n", "\n", $body);
        $body = (string) preg_replace('/\{\{\s*(\d+)\s*\}\}/', '{{$1}}', $body);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $body));
    }

    /** @param array<string, mixed> $row */
    private static function bodyOf(array $row): ?string
    {
        foreach ((array) ($row['components'] ?? []) as $c) {
            if (strtoupper((string) ($c['type'] ?? '')) === 'BODY' && is_string($c['text'] ?? null)) {
                return $c['text'];
            }
        }

        return null;
    }

    /** A component read from Meta, as it may be sent back unchanged (examples kept, read-only fields left out). */
    private static function resubmittable(mixed $component): ?array
    {
        return is_array($component) ? Arr::only($component, ['type', 'format', 'text', 'example', 'buttons']) : null;
    }

    /** Meta's order: header, body, footer, buttons. @param list<array<string, mixed>> $others @return list<array<string, mixed>> */
    private function ordered(array $others, array $body): array
    {
        $rank = ['HEADER' => 0, 'BODY' => 1, 'FOOTER' => 2, 'BUTTONS' => 3];
        $all = [...$others, $body];
        usort($all, fn ($a, $b) => ($rank[strtoupper((string) $a['type'])] ?? 9) <=> ($rank[strtoupper((string) $b['type'])] ?? 9));

        return $all;
    }

    /** @return array<string, mixed>|null the template as Meta has it (name and language), or null */
    private function find(TenantIntegration $integration, MessageTemplate $template): ?array
    {
        $data = $this->request(fn () => $this->client($integration)->get($this->root().'/'.$integration->option('waba_id').'/message_templates', [
            'name' => $template->name, 'fields' => 'id,name,language,status,components', 'limit' => 50,
        ]));

        return collect((array) ($data['data'] ?? []))->first(fn ($row) => ($row['name'] ?? null) === $template->name && strtolower((string) ($row['language'] ?? '')) === strtolower($template->language));
    }

    /** @return array<string, mixed> Meta's answer, or a refusal said plainly */
    private function request(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw new BusinessRuleException('templates.connection', 'تعذّر الاتصال بـ Meta الآن. حاولي مرة أخرى بعد قليل.', 'body');
        }
        if (! $response->successful()) {
            $code = (int) ($response->json('error.code') ?? 0);
            $detail = (string) ($response->json('error.error_user_msg') ?? $response->json('error.error_data.details') ?? $response->json('error.message') ?? '');
            $message = match (true) {
                in_array($code, [190, 102], true) => 'رفضت Meta رمز الوصول. جدّدي الرمز في «التطبيقات التكاملية ← واتساب».',
                $code === 200 || $code === 10 => 'رمز الوصول لا يملك صلاحية إدارة الرسائل (whatsapp_business_management).',
                str_contains(strtolower($detail), 'edit') && str_contains(strtolower($detail), 'limit') => 'وصلتِ إلى حد التعديلات الذي تسمح به Meta لهذه الرسالة (مرة كل 24 ساعة، و10 مرات في الشهر). حاولي لاحقًا.',
                default => 'رفضت Meta الطلب'.($detail !== '' ? ': '.Str::limit($detail, 220) : '.'),
            };
            throw new BusinessRuleException('templates.meta', $message, 'body');
        }

        return (array) $response->json();
    }

    private function client(TenantIntegration $integration): PendingRequest
    {
        return Http::withToken((string) $integration->credential('access_token'))->acceptJson()->timeout(20);
    }

    private function root(): string
    {
        return rtrim((string) config('services.whatsapp.graph_url'), '/').'/'.config('services.whatsapp.graph_version');
    }
}
