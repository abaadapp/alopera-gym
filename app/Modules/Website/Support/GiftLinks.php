<?php

namespace App\Modules\Website\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\GiftWhatsApp;
use App\Modules\Payments\Services\OnlinePaymentService;

/**
 * The gift's public link (#192) and the WhatsApp message that shares it. Built on the server, for staff allowed to see
 * gifts and for the buyer's own pages: the token is never sent anywhere else. The link shows the card only — names,
 * plan, duration and message — never the price, the invoice or anyone's phone.
 */
final class GiftLinks
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * «إهداء هذه الباقة» remains visible for public giftable plans even when online checkout is disabled.
     * The gift page explains why checkout is unavailable. The form and server-side store/payment routes
     * still require active online payment and recipient WhatsApp verification; showing a link is not enabling sales.
     */
    public function buy(MembershipPlan $plan, ?bool $siteSells = null): ?string
    {
        return $plan->giftable ? route('site.gift.create', ['plan' => $plan->id]) : null;
    }

    public function siteSells(): bool
    {
        return app(OnlinePaymentService::class)->available() && app(GiftWhatsApp::class)->verificationRefusal() === null;
    }

    public function card(GiftMembership $gift): string
    {
        return route('site.gift.show', ['club' => $this->tenants->require()->slug, 'token' => $gift->linkToken()]);
    }

    /** The usual WhatsApp share (wa.me with no number: the sender picks the chat). Nothing is sent by the system. */
    public function whatsapp(GiftMembership $gift, bool $english = false): string
    {
        $club = $this->tenants->require()->name;
        $plan = $english ? (($gift->plan_snapshot['name_en'] ?? null) ?: ($gift->plan_snapshot['name'] ?? '')) : ($gift->plan_snapshot['name'] ?? '');
        $text = $english
            ? "🎁 {$gift->recipient_name}, {$gift->giver_name} sent you a gift: the {$plan} membership at {$club}. Open it here:"
            : "🎁 {$gift->recipient_name}، لديكِ هدية من {$gift->giver_name}: اشتراك {$plan} في {$club}. افتحيها من هنا:";

        return 'https://wa.me/?text='.rawurlencode($text.' '.$this->card($gift));
    }
}
