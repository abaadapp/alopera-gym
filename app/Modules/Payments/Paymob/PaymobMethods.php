<?php

namespace App\Modules\Payments\Paymob;

use App\Models\TenantIntegration;
use App\Modules\Payments\Models\PaymentWebhookEvent;

/**
 * Which of the club's Paymob integration ids is which payment method, and how far each is proven. Only a label on ids
 * the club already put in «Integration IDs»: what a checkout offers and which callback is accepted stay decided by that
 * list (PaymobClient::integrationIds), so labelling changes no checkout and no callback.
 *
 * A method is never «ready» because a number was typed: not set → set → accepted by Paymob (a connection check passed
 * with it in the list) → proven (a signed, recorded payment came through it).
 * - OmanNet: Paymob's documentation does not list it among the payment methods, nor says whether it has an
 *   integration id of its own or comes under the card one; it stays unproven until Paymob Oman confirms it for the
 *   account. No id and no button for it.
 * - Apple Pay: «has Live integration IDs only» (Paymob's documentation): never accepted under a test account.
 */
final class PaymobMethods
{
    public const LABELLED = ['card' => 'البطاقات البنكية', 'apple_pay' => 'Apple Pay'];

    public const NOT_SET = 'not_set';

    public const SET = 'set';

    public const ACCEPTED = 'accepted';

    public const PROVEN = 'proven';

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
        $rows = [];
        foreach (self::LABELLED as $method => $label) {
            $id = $ids[$method];
            [$state, $reason] = match (true) {
                $method === 'apple_pay' && $integration?->mode !== 'live' => [self::UNAVAILABLE, 'Apple Pay له أرقام ربط حقيقية (Live) فقط لدى Paymob، فلا يعمل في وضع الاختبار.'],
                $id === null => [self::NOT_SET, 'لم يُحدَّد رقم ربطه من «Integration IDs».'],
                self::proven($integration, $id) => [self::PROVEN, 'وصلت عبره دفعة موقّعة وسُجّلت.'],
                $accepted => [self::ACCEPTED, 'قبلت Paymob أرقام الربط في «اختبار الاتصال»، ولم تصل عبره دفعة بعد.'],
                default => [self::SET, 'الرقم محدد، ولم ينجح «اختبار الاتصال» بعد.'],
            };
            $rows[] = ['key' => $method, 'label' => $label, 'id' => $id, 'state' => $state, 'reason' => $reason];
        }
        $rows[] = [
            'key' => 'omannet', 'label' => 'OmanNet', 'id' => null, 'state' => self::UNAVAILABLE,
            'reason' => 'لا تذكره وثائق Paymob بين وسائل الدفع، ولا تبيّن أله رقم ربط مستقل أم يدخل ضمن البطاقات. يبقى غير متاح حتى تؤكد Paymob عُمان تفعيله لحساب النادي.',
        ];

        return $rows;
    }

    /** A signed callback of this club, through this integration id, that recorded a payment. */
    private static function proven(?TenantIntegration $integration, int $id): bool
    {
        if ($integration === null) {
            return false;
        }

        return PaymentWebhookEvent::where('tenant_id', $integration->tenant_id)->where('provider', 'paymob')
            ->where('signature_valid', true)->whereIn('outcome', ['processed', 'recorded_with_error'])
            ->whereRaw("(payload->'obj'->>'integration_id') = ?", [(string) $id])->exists();
    }
}
