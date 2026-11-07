<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A WhatsApp webhook body as it arrived, before any processing: written once (the digest of the body is unique, so
 * a repeated delivery is one row), processed by a queued job that retries on failure. Platform-level like the payment
 * gateway's log: the club is found from the body, so the row is written before any club is known. The body holds
 * customers' messages and is encrypted at rest.
 */
class WebhookEvent extends Model
{
    public const RECEIVED = 'received';

    public const PROCESSED = 'processed';

    public const FAILED = 'failed';

    public const IGNORED = 'ignored';

    protected $table = 'whatsapp_webhook_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'processed_at' => 'datetime', 'attempts' => 'integer'];
    }
}
