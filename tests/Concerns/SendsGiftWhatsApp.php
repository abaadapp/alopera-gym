<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Services\GiftWhatsApp;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use Illuminate\Testing\TestResponse;

/**
 * A club's WhatsApp connected for gift tests (#192), and the recipient's own message reaching it through the signed
 * webhook, as WhatsApp sends it. The development `log` driver sends nothing anywhere.
 */
trait SendsGiftWhatsApp
{
    protected const CLUB_WHATSAPP = '96824000000';

    protected const APP_SECRET = 'gift-app-secret';

    /** Connected, with the club's number known; the gift's template approved and the gift notice on when asked. */
    protected function connectWhatsApp(Tenant $club, bool $sendGifts = true, bool $approved = true, string $pnid = 'PNID-GIFT'): TenantIntegration
    {
        config(['services.whatsapp.app_secret' => self::APP_SECRET]);
        if ($sendGifts) {
            // As the panel saves it: on, and since when (what was paid before is not sent by the switch).
            $club->forceFill(['settings' => array_replace_recursive($club->settings ?? [], ['whatsapp_automation' => [
                'enabled' => ['gift_received'], 'enabled_since' => ['gift_received' => now()->subMinute()->toIso8601String()],
            ]])])->save();
        }

        return $this->asTenant($club, function () use ($approved, $pnid) {
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => $approved ? 'approved' : 'draft']);

            return TenantIntegration::create([
                'provider' => 'whatsapp', 'is_enabled' => true, 'public_identifier' => $pnid,
                'credentials' => ['phone_number_id' => $pnid, 'access_token' => 'EAAG-test'],
                'settings' => ['vendor' => 'meta', 'waba_id' => 'WABA-'.$pnid, 'display_phone' => '+968 2400 0000'],
            ]);
        });
    }

    /** @param array<string, mixed> $value */
    protected function whatsappChange(array $value, string $pnid = 'PNID-GIFT'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA-'.$pnid, 'changes' => [['field' => 'messages', 'value' => $value + [
            'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => self::CLUB_WHATSAPP, 'phone_number_id' => $pnid],
        ]]]]]];
    }

    protected function deliverWhatsApp(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET), 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /** A text from `$from` to the club's number, as WhatsApp's webhook carries it. */
    protected function whatsappText(string $from, string $body, string $id, string $pnid = 'PNID-GIFT'): TestResponse
    {
        return $this->deliverWhatsApp($this->whatsappChange([
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Noor']]],
            'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $body]]],
        ], $pnid));
    }

    /** A delivery status for one of the club's sent messages. */
    protected function whatsappStatus(string $providerId, string $status, ?array $error = null, string $pnid = 'PNID-GIFT'): TestResponse
    {
        return $this->deliverWhatsApp($this->whatsappChange(['statuses' => [array_filter([
            'id' => $providerId, 'status' => $status, 'timestamp' => (string) now()->timestamp, 'recipient_id' => '96895551234',
            'errors' => $error ? [$error] : null,
        ])]], $pnid));
    }

    /** The recipient sends the gift's confirmation message from `$from` (her own number unless said otherwise). */
    protected function recipientConfirms(Tenant $club, GiftMembership $gift, ?string $from = null, ?string $id = null): TestResponse
    {
        $text = $this->asTenant($club, fn () => app(GiftWhatsApp::class)->verification($gift->fresh())['text']);
        $this->assertNotNull($text, 'the gift has a code waiting');

        return $this->whatsappText($from ?? $gift->recipient_phone, $text, $id ?? 'wamid.confirm.'.$gift->id.'.'.uniqid());
    }
}
