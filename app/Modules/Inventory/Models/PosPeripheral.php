<?php

namespace App\Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Papers\PaperSize;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hardware attached to a point-of-sale device. A receipt printer is driven two ways, and only these are offered:
 * a network printer (ESC/POS on the local network, its address and port) that the club's app on a phone or tablet
 * prints to directly, and a printer installed on the device itself (USB, Bluetooth or AirPrint) reached through the
 * system's or the browser's print dialog. A barcode scanner types like a keyboard. A cash drawer, customer display or
 * card terminal is recorded for the record; driving it needs a helper on the device.
 */
class PosPeripheral extends Model
{
    use Auditable, BelongsToTenant;

    public const TYPES = ['printer' => 'طابعة إيصالات', 'scanner' => 'ماسح باركود', 'drawer' => 'درج نقدي', 'display' => 'شاشة عميل', 'card_terminal' => 'جهاز دفع بالبطاقة'];

    /** What the panel can drive from the browser today. */
    public const DRIVABLE = ['printer', 'scanner'];

    public const CONNECTIONS = ['browser' => 'عبر المتصفح', 'network' => 'شبكة (IP)', 'usb' => 'USB', 'bluetooth' => 'Bluetooth', 'keyboard' => 'كلوحة مفاتيح'];

    /**
     * How a printer is reached, as the printing layer really does it. USB and Bluetooth are not offered as direct
     * printing: no driver for them exists in the app, so such a printer is one installed on the device and printed to
     * through the print dialog. A printer saved before with usb or bluetooth reads as that.
     */
    public const PRINTER_CONNECTIONS = [
        'network' => 'طابعة شبكة (IP) — طباعة مباشرة من تطبيق الجم',
        'browser' => 'طابعة مثبتة على الجهاز (USB أو Bluetooth أو AirPrint) — عبر حوار الطباعة',
    ];

    /** The paper a printer feeds: a thermal strip or a sheet (PaperSize keys). */
    public const PAPERS = ['80mm' => 'حراري 80 مم', '58mm' => 'حراري 58 مم', 'A4' => 'A4', 'A5' => 'A5'];

    /** The documented default port for raw printing, asked when none is set; shown as such, not assumed for every printer. */
    public const DEFAULT_PORT = 9100;

    protected $guarded = ['id', 'tenant_id', 'last_status', 'last_error', 'last_checked_at'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'auto_print' => 'boolean', 'print_website_orders' => 'boolean', 'is_active' => 'boolean', 'last_checked_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'pos_device_id');
    }

    /** How it is reached (the column: inside the model $this->connection is the database connection's name). */
    public function way(): ?string
    {
        return $this->getAttribute('connection');
    }

    /** The paper this printer feeds (a PaperSize key); a printer saved before the field carries its strip width. */
    public function paperSize(): string
    {
        return isset(self::PAPERS[(string) $this->paper]) ? (string) $this->paper : ($this->paper_width === 58 ? '58mm' : '80mm');
    }

    public function isThermal(): bool
    {
        return PaperSize::isStrip($this->paperSize());
    }

    /** May the club's app print to it directly? A thermal printer on the network with an address. */
    public function printsDirect(): bool
    {
        return $this->type === 'printer' && $this->way() === 'network' && filled($this->address) && $this->isThermal();
    }

    /** What the printing layer on the page needs to pick a way to print (never more: no secrets here). */
    public function forPrinting(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'model' => $this->getAttribute('model'), 'connection' => $this->way() === 'network' ? 'network' : 'browser',
            'address' => $this->way() === 'network' ? $this->address : null, 'port' => $this->way() === 'network' ? $this->port : null,
            'paper' => $this->paperSize(), 'paper_width' => $this->isThermal() ? (int) $this->paperSize() : null, 'auto_print' => $this->auto_print,
        ];
    }
}
