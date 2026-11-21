<?php

namespace App\Modules\Messaging\Services;

use App\Core\Support\Money;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Payments\Services\OnlinePaymentService;

/**
 * An invoice sent to its member over WhatsApp (owner's decisions, 2026-11-21), automatically when it is issued and by
 * hand from «إرسال الفاتورة»: the values are the invoice's own (its number, what is left of it, its payment link), never
 * the member's alone, and only for an open invoice of that member. The template is the one approved by Meta and active
 * here in the language asked (the member's preference, or the one chosen by hand): with a membership waiting on the
 * invoice, alopera_invoice_issued_membership (Arabic or English: it starts once paid in full), else invoice_issued
 * (Arabic). Not approved in that language: the approved Arabic one, said so (`fallback`). None approved: not sent.
 * Consent and duplicates are WhatsAppService's (each send's dedupe key).
 */
final class InvoiceMessage
{
    /** A template's values that only an invoice (or its payment) gives: never sent empty. */
    public const INVOICE_VALUES = ['invoice_number', 'amount', 'payment_link', 'invoice_link', 'receipt_number'];

    public const LANGUAGES = ['ar', 'en'];

    public function __construct(private readonly WhatsAppService $whatsapp) {}

    /** Why this invoice cannot be sent to this member by hand, or null. */
    public function refusal(Member $member, Invoice $invoice): ?string
    {
        return match (true) {
            $invoice->billable_type !== 'member' || (int) $invoice->billable_id !== (int) $member->id => 'هذه الفاتورة لا تخص هذا العضو.',
            ! $invoice->isOpen() || Money::isZero($invoice->balance()) => 'لا مبلغ مستحق على هذه الفاتورة.',
            trim((string) $invoice->number) === '' => 'الفاتورة بلا رقم بعد (مسودة): أصدرها أولًا.',
            // Owner's decision (2026-11-20): the message carries the invoice's live payment link; with its links all
            // ended nothing is sent and no link is made here — the staff make a new one first.
            app(OnlinePaymentService::class)->available() && app(MemberNotifier::class)->payPageOrNull($invoice) === null => 'لا يوجد رابط دفع نشط لهذه الفاتورة: انتهى رابطها أو أُلغي أو استُبدل. أنشئ رابط دفع جديدًا من قسم «رابط الدفع» في صفحة الفاتورة، ثم أرسلها.',
            default => null,
        };
    }

    /**
     * The template to send this invoice with in this language: the first approved and active of the right ones, and
     * what was given up to reach it (null: none). Null when none is approved.
     *
     * @return array{key: string, language: string, fallback: string|null}|null
     */
    public function choose(Invoice $invoice, string $language): ?array
    {
        $language = in_array($language, self::LANGUAGES, true) ? $language : 'ar';
        $membership = Membership::where('invoice_id', $invoice->id)->where('status', Membership::PENDING)->exists();
        $candidates = $membership
            ? ($language === 'en' ? ['invoice_issued_membership_en', 'invoice_issued_membership', 'invoice_issued'] : ['invoice_issued_membership', 'invoice_issued'])
            : ['invoice_issued'];
        $templates = MessageTemplate::whereIn('key', $candidates)->get()->keyBy('key');

        foreach ($candidates as $key) {
            $template = $templates->get($key);
            if (! $template || ! $template->isUsable() || ! TemplateCatalog::isCurrent($template)) {
                continue;
            }
            $notes = [];
            if ($template->language !== $language) {
                $notes[] = $membership && $key !== 'invoice_issued_membership_en'
                    ? 'القالب الإنجليزي غير معتمد بعد في Meta أو غير مفعّل هنا: تُرسل بالقالب العربي المعتمد.'
                    : 'لا قالب إنجليزي معتمد لرسالة هذه الفاتورة: تُرسل بالقالب العربي المعتمد.';
            }
            if ($membership && $key === 'invoice_issued') {
                $notes[] = 'قالب «فيها اشتراك» غير معتمد بعد: تُرسل رسالة الفاتورة العامة، دون جملة «يتفعّل بعد السداد الكامل».';
            }

            return ['key' => $key, 'language' => $template->language, 'fallback' => $notes ? implode(' ', $notes) : null];
        }

        return null;
    }

    /** @return array<string, string> the invoice's own values, as the template names them */
    public function values(Member $member, Invoice $invoice): array
    {
        return [
            'member_name' => explode(' ', trim((string) $member->full_name))[0] ?: (string) $member->full_name,
            'invoice_number' => (string) $invoice->number,
            'amount' => number_format((float) $invoice->balance(), 3).' '.$invoice->currency,
            'payment_link' => app(MemberNotifier::class)->paymentLink($invoice),
        ];
    }

    /**
     * The message as it would leave, for the staff to read before sending: the template, its language, the fallback
     * said, and its text with the invoice's values — or why it would not be sent.
     *
     * @return array{ok: bool, error: string|null, template_key: string|null, language: string|null, fallback: string|null, body: string|null}
     */
    public function preview(Member $member, Invoice $invoice, string $language): array
    {
        $none = ['template_key' => null, 'language' => null, 'fallback' => null, 'body' => null];
        if (($refusal = $this->refusal($member, $invoice)) !== null) {
            return ['ok' => false, 'error' => $refusal] + $none;
        }
        $choice = $this->choose($invoice, $language);
        if ($choice === null) {
            return ['ok' => false, 'error' => 'لا قالب معتمد من Meta ومفعّل هنا لرسالة هذه الفاتورة: لا تُرسل حتى يُعتمد.'] + $none;
        }
        $template = MessageTemplate::where('key', $choice['key'])->firstOrFail();
        $values = $this->values($member, $invoice);
        $params = array_map(fn ($name) => (string) ($values[$name] ?? ''), $template->variables ?? []);
        if (in_array('', $params, true)) {
            return ['ok' => false, 'error' => 'قيمة من قيم الفاتورة فارغة، فلا تُرسل.'] + $none;
        }
        $problem = match (true) {
            ! $member->whatsapp_opt_in => 'العضو لم يوافق على رسائل الخدمة عبر واتساب.',
            trim((string) $member->phone) === '' => 'لا رقم هاتف للعضو.',
            default => null,
        };

        return ['ok' => $problem === null, 'error' => $problem, 'template_key' => $choice['key'], 'language' => $choice['language'],
            'fallback' => $choice['fallback'], 'body' => $template->render($params)];
    }

    /**
     * Send by hand, after the same checks as the preview; at most once a minute for the same invoice (a double click).
     *
     * @return array{message: Message|null, error: string|null, fallback: string|null}
     */
    public function sendByHand(Member $member, Invoice $invoice, string $language): array
    {
        $preview = $this->preview($member, $invoice, $language);
        if (! $preview['ok']) {
            return ['message' => null, 'error' => $preview['error'], 'fallback' => null];
        }
        $message = $this->whatsapp->queueTemplate($member, $preview['template_key'], $this->values($member, $invoice), $invoice,
            'invoice:'.$invoice->id.':hand:'.now()->format('YmdHi'));
        $error = match (true) {
            $message === null => 'أُرسلت هذه الفاتورة قبل أقل من دقيقة، أو واتساب غير مفعّل.',
            $message->status === Message::SKIPPED => 'لم تُرسل: '.(Message::SKIP_REASONS[$message->error_code] ?? $message->error_code).'.',
            default => null,
        };

        return ['message' => $message, 'error' => $error, 'fallback' => $preview['fallback']];
    }

    /**
     * When the invoice is issued (MembershipCreated): once per invoice, in the member's language. With no template
     * approved, the plain one is queued and logged as not sent (its reason), as before.
     */
    public function automatic(Member $member, Invoice $invoice): ?Message
    {
        $choice = $this->choose($invoice, (string) ($member->message_language ?: 'ar'));

        return $this->whatsapp->queueTemplate($member, $choice['key'] ?? 'invoice_issued', $this->values($member, $invoice), $invoice, 'invoice:'.$invoice->id);
    }
}
