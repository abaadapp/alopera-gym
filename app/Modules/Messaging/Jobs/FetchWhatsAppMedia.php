<?php

namespace App\Modules\Messaging\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Messaging\Gateways\WhatsAppGateway;
use App\Modules\Messaging\Models\Attachment;
use App\Modules\Messaging\Services\MediaStore;
use App\Modules\Messaging\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * Downloads a file a customer sent (or one sent from the WhatsApp Business app) onto the private disk. WhatsApp's
 * links last minutes, so it runs soon after the webhook and retries a temporary failure; a lasting one is written on
 * the attachment and the message still shows that a file came.
 */
class FetchWhatsAppMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [15, 60, 300];

    public function __construct(public readonly int $attachmentId, public readonly int $tenantId, public readonly ?string $url = null)
    {
        $this->onQueue('notifications');
    }

    public function handle(TenantContext $tenants, WhatsAppGateway $gateway, MediaStore $store, WhatsAppService $whatsapp): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        $tenants->run($tenant, function () use ($gateway, $store, $whatsapp) {
            $attachment = Attachment::find($this->attachmentId);
            $integration = $whatsapp->integration();
            if (! $attachment || $attachment->status !== Attachment::PENDING || ! $attachment->provider_media_id) {
                return;
            }
            if (! $integration) {
                $attachment->forceFill(['status' => Attachment::FAILED, 'error' => 'واتساب غير مربوط.'])->save();

                return;
            }

            $file = $gateway->fetchMedia($integration, $attachment->provider_media_id, $this->url);
            if (! $file->ok) {
                if ($file->retryable && $this->attempts() < $this->tries) {
                    throw new RuntimeException('WhatsApp media temporarily unavailable');
                }
                $attachment->forceFill(['status' => Attachment::FAILED, 'error' => mb_substr((string) $file->error, 0, 250)])->save();

                return;
            }

            $limit = 100 * 1024 * 1024;
            if (strlen((string) $file->bytes) > $limit) {
                $attachment->forceFill(['status' => Attachment::FAILED, 'error' => 'الملف أكبر من 100 ميغابايت.'])->save();

                return;
            }

            $attachment->forceFill([
                'path' => $store->storeBytes((string) $file->bytes, $file->mime ?? $attachment->mime, $attachment->tenant_id),
                'mime' => $attachment->mime ?? $file->mime,
                'size' => strlen((string) $file->bytes),
                'status' => Attachment::STORED,
                'error' => null,
            ])->save();
        });
    }
}
