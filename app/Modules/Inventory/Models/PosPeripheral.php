<?php

namespace App\Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hardware attached to a point-of-sale device. Only what the browser can really drive is used: a receipt printer
 * through the browser's print dialog (58 or 80 mm) and a barcode scanner that types like a keyboard. A cash drawer,
 * customer display or card terminal is recorded for the record; driving it needs a helper on the device.
 */
class PosPeripheral extends Model
{
    use Auditable, BelongsToTenant;

    public const TYPES = ['printer' => 'طابعة إيصالات', 'scanner' => 'ماسح باركود', 'drawer' => 'درج نقدي', 'display' => 'شاشة عميل', 'card_terminal' => 'جهاز دفع بالبطاقة'];

    /** What the panel can drive from the browser today. */
    public const DRIVABLE = ['printer', 'scanner'];

    public const CONNECTIONS = ['browser' => 'عبر المتصفح', 'network' => 'شبكة (IP)', 'usb' => 'USB', 'bluetooth' => 'Bluetooth', 'keyboard' => 'كلوحة مفاتيح'];

    protected $guarded = ['id', 'tenant_id', 'last_status', 'last_error', 'last_checked_at'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'auto_print' => 'boolean', 'print_website_orders' => 'boolean', 'is_active' => 'boolean', 'last_checked_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'pos_device_id');
    }
}
