<?php

namespace App\Modules\Inventory\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A till basket's key, the fingerprint of what it held and the documents its sale produced (PosCheckout). */
class PosCheckoutKey extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['documents' => 'array'];
    }
}
