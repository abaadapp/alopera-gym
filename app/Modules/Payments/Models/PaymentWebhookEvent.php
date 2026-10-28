<?php

namespace App\Modules\Payments\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-level log of raw gateway callbacks; read by super admin only. */
class PaymentWebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'signature_valid' => 'boolean', 'created_at' => 'datetime'];
    }
}
