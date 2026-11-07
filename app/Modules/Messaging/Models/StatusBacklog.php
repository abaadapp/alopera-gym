<?php

namespace App\Modules\Messaging\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A delivery status that arrived before the message it is about had its WhatsApp id (the webhook can beat the
 * answer to our own send). It waits here and is applied as soon as the message gets that id.
 */
class StatusBacklog extends Model
{
    use BelongsToTenant;

    protected $table = 'whatsapp_status_backlog';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['wa_timestamp' => 'datetime'];
    }
}
