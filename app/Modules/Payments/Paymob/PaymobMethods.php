<?php

namespace App\Modules\Payments\Paymob;

use App\Models\TenantIntegration;
use App\Modules\Payments\Models\PaymentWebhookEvent;

/**
 * How far each payment method of the club's Paymob account is proven. Each method has an integration id of its own
 * (PaymobClient::METHOD_KEYS), sent to every checkout — and a saved number is never «available»:
 *
 *   not added → saved (a number is kept) → checked (Paymob accepted it: «اختبار الاتصال» opened a checkout with it,
 *   under the keys and mode saved since) → enabled at Paymob (a signed callback came through it: Paymob ran a payment
 *   on it, paid or declined) → available (a signed, recorded payment came through it, and the club takes online
 *   payments now — PaymobClient::takesPayments).
 *
 * - OmanNet: Paymob's documentation lists it among the card schemes («Cards — Methods included: Visa, Mastercard, Amex,
 *   MADA, OmanNet»); an account may also have it as an integration of its own. With its own id it climbs like any
 *   method; without one it goes with the cards, and a payment proves it only when its signed card scheme
 *   (source_data.sub_type) says OmanNet — any other value proves nothing.
 * - Apple Pay: «has Live integration IDs only». Under test keys its number is kept but not sent (checkoutIds), so it
 *   is «not for this environment», never checked or available.
 */
final class PaymobMethods
{
    public const LABELS = ['card' => 'البطاقات البنكية', 'omannet' => 'OmanNet', 'apple_pay' => 'Apple Pay'];

    public const NOT_SET = 'not_set';

    public const SAVED = 'saved';

    public const CHECKED = 'checked';

    public const ENABLED = 'enabled';

    public const AVAILABLE = 'available';

    public const WRONG_MODE = 'wrong_mode';

    /**
     * What the screen shows for each method — never an id.
     *
     * @return list<array{key: string, label: string, saved: bool, state: string, reason: string, within_cards?: bool}>
     */
    public static function states(?TenantIntegration $integration): array
    {
        $ids = PaymobClient::methodIds($integration);
        $checked = $integration !== null && $integration->verified_at !== null && ! $integration->failing();
        $taking = PaymobClient::takesPayments($integration);
        $live = $integration?->mode === 'live';
        $rows = [];

        foreach (self::LABELS as $method => $label) {
            $id = $ids[$method];
            $withinCards = $method === 'omannet' && $id === null;
            $through = $withinCards ? $ids['card'] : $id;
            $scheme = $withinCards ? 'omannet' : null;

            [$state, $reason] = match (true) {
                $method === 'apple_pay' && $id !== null && ! $live => [self::WRONG_MODE, 'رقم Apple Pay محفوظ، لكنه لا يُرسل مع مفاتيح الاختبار: لـ Apple Pay أرقام حقيقية (Live) فقط لدى Paymob، وإرساله مع مفاتيح تجريبية يخالف بيئتها.'],
                $withinCards && $through === null => [self::NOT_SET, 'لا رقم لـ OmanNet ولا للبطاقات. إن كان OmanNet ضمن تكامل البطاقات في حسابك يكفي رقم البطاقات؛ وإن أعطتك Paymob تكاملًا مستقلًا له فأدخل رقمه.'],
                $through === null => [self::NOT_SET, $method === 'apple_pay' ? 'اختياري: رقم تكامل Apple Pay الحقيقي (Live) بعد أن تفعّله Paymob لحسابك.' : 'أدخل رقم تكامل البطاقات من لوحة Paymob ← Payment Integrations.'],
                self::through($integration, $through, true, $scheme) && $taking => [self::AVAILABLE, $withinCards ? 'وصلت دفعة موقّعة ببطاقة OmanNet عبر تكامل البطاقات وسُجّلت، والدفع الإلكتروني مفعّل الآن.' : 'وصلت عبره دفعة موقّعة وسُجّلت، والدفع الإلكتروني مفعّل الآن.'],
                self::through($integration, $through, false, $scheme) => [self::ENABLED, $withinCards ? 'نفّذت Paymob دفعة ببطاقة OmanNet عبر تكامل البطاقات.' : 'نفّذت Paymob عبره دفعة موقّعة'.($taking ? '، ولم تُسجَّل عبره دفعة ناجحة بعد.' : '، والدفع الإلكتروني غير مفعّل الآن.')],
                $withinCards => [self::SAVED, 'يعمل ضمن تكامل البطاقات إن فعّلته Paymob عُمان لحسابك، ولا يثبت ذلك إلا أول دفعة موقّعة ببطاقة OmanNet.'],
                $checked => [self::CHECKED, 'قبلت Paymob الرقم في «اختبار الاتصال» بالمفاتيح والبيئة المحفوظة، ولم تنفّذ عبره دفعة بعد.'],
                default => [self::SAVED, 'الرقم محفوظ، ولم ينجح «اختبار الاتصال» بعد حفظه.'],
            };
            $rows[] = ['key' => $method, 'label' => $label, 'saved' => $id !== null, 'state' => $state, 'reason' => $reason] + ($method === 'omannet' ? ['within_cards' => $withinCards] : []);
        }

        return $rows;
    }

    /**
     * A signed callback of this club through this integration id — one that recorded a payment when $recorded — and,
     * for a card scheme, whose signed source_data.sub_type names it (letters only, any case: «OmanNet», «OMAN-NET»…).
     */
    private static function through(?TenantIntegration $integration, int $id, bool $recorded, ?string $scheme): bool
    {
        if ($integration === null) {
            return false;
        }

        $outcomes = ['processed', 'recorded_with_error', 'held_for_review'];

        return PaymentWebhookEvent::where('tenant_id', $integration->tenant_id)->where('provider', 'paymob')->where('signature_valid', true)
            ->whereIn('outcome', $recorded ? $outcomes : [...$outcomes, 'failed_payment', 'duplicate'])
            ->whereRaw("(payload->'obj'->>'integration_id') = ?", [(string) $id])
            ->when($scheme !== null, fn ($q) => $q->whereRaw("lower(regexp_replace(payload->'obj'->'source_data'->>'sub_type', '[^A-Za-z]', '', 'g')) = ?", [$scheme]))
            ->exists();
    }
}
