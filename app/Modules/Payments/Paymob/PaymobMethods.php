<?php

namespace App\Modules\Payments\Paymob;

use App\Models\TenantIntegration;
use App\Modules\Payments\Models\PaymentWebhookEvent;

/**
 * Which of the club's Paymob integration ids is which payment method, and how far each is proven. Only a label on ids
 * the club already put in «Integration IDs»: what a checkout offers and which callback is accepted stay decided by that
 * list (PaymobClient::integrationIds), so labelling changes no checkout and no callback.
 *
 * A method is never «available» because a number was typed:
 *   not set → set → verified (Paymob accepted the ids in «اختبار الاتصال», or a signed payment came through it while
 *   online payment is off) → available (a signed, recorded payment came through it AND the club takes online payments
 *   now — PaymobClient::takesPayments).
 * - Cards: the card integration id.
 * - OmanNet: Paymob's documentation lists it among the card schemes («Cards — Methods included: Visa, Mastercard, Amex,
 *   MADA, OmanNet», developers.paymob.com, Overview, Aug 2026): no integration id or API of its own. Whether Paymob Oman
 *   enabled it on the club's card integration is not something the API tells; it is proven only by a signed payment
 *   through the card id whose card scheme (source_data.sub_type) says OmanNet. Any other value proves nothing.
 * - Apple Pay: «has Live integration IDs only» (Paymob's documentation): never verified under a test account.
 */
final class PaymobMethods
{
    public const LABELLED = ['card' => 'البطاقات البنكية', 'apple_pay' => 'Apple Pay'];

    public const NOT_SET = 'not_set';

    public const SET = 'set';

    public const VERIFIED = 'verified';

    public const AVAILABLE = 'available';

    public const UNAVAILABLE = 'unavailable';

    /** @return array<string, int|null> */
    public static function ids(?TenantIntegration $integration): array
    {
        $saved = (array) ($integration?->option('methods') ?? []);
        $listed = $integration ? PaymobClient::integrationIds($integration) : [];
        $ids = [];
        foreach (array_keys(self::LABELLED) as $method) {
            $id = isset($saved[$method]) && ctype_digit((string) $saved[$method]) ? (int) $saved[$method] : null;
            // A label of an id no longer listed labels nothing.
            $ids[$method] = $id !== null && in_array($id, $listed, true) ? $id : null;
        }

        return $ids;
    }

    /** @return list<array{key: string, label: string, id: int|null, state: string, reason: string}> */
    public static function states(?TenantIntegration $integration): array
    {
        $ids = self::ids($integration);
        $accepted = $integration !== null && $integration->verified_at !== null && ! $integration->failing();
        $taking = PaymobClient::takesPayments($integration);
        $rows = [];
        foreach (self::LABELLED as $method => $label) {
            $id = $ids[$method];
            $paid = $id !== null && self::paidThrough($integration, $id);
            [$state, $reason] = match (true) {
                $method === 'apple_pay' && $integration?->mode !== 'live' => [self::UNAVAILABLE, 'Apple Pay له أرقام ربط حقيقية (Live) فقط لدى Paymob، فلا يعمل في وضع الاختبار.'],
                $id === null => [self::NOT_SET, 'لم يُحدَّد رقم ربطه من «Integration IDs».'],
                $paid && $taking => [self::AVAILABLE, 'وصلت عبره دفعة موقّعة وسُجّلت، والدفع الإلكتروني مفعّل الآن.'],
                $paid => [self::VERIFIED, 'وصلت عبره دفعة موقّعة وسُجّلت، لكن الدفع الإلكتروني غير مفعّل الآن.'],
                $accepted => [self::VERIFIED, 'قبلت Paymob أرقام الربط في «اختبار الاتصال»، ولم تصل عبره دفعة بعد.'],
                default => [self::SET, 'الرقم محدد، ولم ينجح «اختبار الاتصال» بعد.'],
            };
            $rows[] = ['key' => $method, 'label' => $label, 'id' => $id, 'state' => $state, 'reason' => $reason];
        }

        $card = $ids['card'];
        $oman = $card !== null && self::paidThrough($integration, $card, 'omannet');
        [$state, $reason] = match (true) {
            $card === null => [self::NOT_SET, 'OmanNet من شبكات البطاقات لدى Paymob، بلا رقم ربط مستقل: حدّد رقم ربط البطاقات أولًا.'],
            $oman && $taking => [self::AVAILABLE, 'وصلت دفعة موقّعة ببطاقة OmanNet عبر تكامل البطاقات، والدفع الإلكتروني مفعّل الآن.'],
            $oman => [self::VERIFIED, 'وصلت دفعة موقّعة ببطاقة OmanNet، لكن الدفع الإلكتروني غير مفعّل الآن.'],
            default => [self::SET, 'يعمل ضمن تكامل البطاقات إن فعّلته Paymob عُمان لحساب النادي، ولا يثبت ذلك إلا أول دفعة موقّعة ببطاقة OmanNet.'],
        };
        $rows[] = ['key' => 'omannet', 'label' => 'OmanNet', 'id' => $card, 'state' => $state, 'reason' => $reason];

        return $rows;
    }

    /**
     * A signed callback of this club, through this integration id, that recorded a payment — and, for a card scheme,
     * whose signed source_data.sub_type names it (letters only, any case: «OmanNet», «OMANNET»…).
     */
    private static function paidThrough(?TenantIntegration $integration, int $id, ?string $scheme = null): bool
    {
        if ($integration === null) {
            return false;
        }

        return PaymentWebhookEvent::where('tenant_id', $integration->tenant_id)->where('provider', 'paymob')
            ->where('signature_valid', true)->whereIn('outcome', ['processed', 'recorded_with_error', 'held_for_review'])
            ->whereRaw("(payload->'obj'->>'integration_id') = ?", [(string) $id])
            ->when($scheme !== null, fn ($q) => $q->whereRaw("lower(regexp_replace(payload->'obj'->'source_data'->>'sub_type', '[^A-Za-z]', '', 'g')) = ?", [$scheme]))
            ->exists();
    }
}
