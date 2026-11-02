<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\PosDevice;
use App\Modules\Inventory\Models\PosPeripheral;
use App\Modules\Inventory\Rules\PrinterAddress;
use Illuminate\Support\Collection;

/**
 * What keeps a device's receipts from coming out as its settings say, read from the devices and their printers and
 * never mended here: the devices screen says it and the manager decides (mending it by itself would change a till's
 * printer without anyone knowing).
 *
 * Two degrees. «certain»: the device's own settings contradict each other (the default printer is stopped, an address
 * the app refuses, the same network printer twice, an automatic print on a printer receipts do not go to). «check»: a
 * pattern that may be a mistake or may be meant (two printers and none default, two printers of one name and way, a
 * device without a printer while another in its branch holds one).
 */
final class PrinterHealth
{
    public const CERTAIN = 'certain';

    public const CHECK = 'check';

    /**
     * The findings of each active device, by its id; a device with nothing to say is left out.
     *
     * @param  Collection<int, PosDevice>  $devices  every device of the club, with its peripherals
     * @return array<int, list<array{level: string, code: string, message: string, peripheral: int|null}>>
     */
    public static function assess(Collection $devices): array
    {
        $printersOf = fn (PosDevice $d) => $d->peripherals->where('type', 'printer')->sortBy('id')->values();
        $found = [];

        foreach ($devices->where('status', PosDevice::ACTIVE) as $device) {
            $note = function (string $level, string $code, string $message, ?PosPeripheral $p = null) use (&$found, $device) {
                $found[$device->id][] = ['level' => $level, 'code' => $code, 'message' => $message, 'peripheral' => $p?->id];
            };
            $all = $printersOf($device);
            $active = $all->where('is_active', true)->values();
            $default = $all->firstWhere('is_default', true);
            $receipts = $device->printer();

            if ($default && ! $default->is_active) {
                $note(self::CERTAIN, 'default_stopped', "الطابعة الافتراضية «{$default->name}» موقوفة، فتخرج الإيصالات على طابعة أخرى أو لا تخرج. فعّلها أو اختر غيرها افتراضية.", $default);
            }
            if ($active->count() > 1 && ! $active->contains('is_default', true)) {
                $note(self::CHECK, 'no_default', "على الجهاز أكثر من طابعة مفعّلة ولم تُحدَّد الافتراضية، فتخرج الإيصالات على أقدمها «{$receipts?->name}». حدّد الطابعة الافتراضية.");
            }

            foreach ($active as $p) {
                if ($p->way() === 'network' && ! PrinterAddress::allows((string) $p->address)) {
                    $note(self::CERTAIN, 'bad_address', "عنوان الطابعة «{$p->name}» ليس عنوانًا محليًا يقبله تطبيق الجم، فلن يطبع عليها. صحّحه (مثل 192.168.1.50).", $p);
                }
                if ($receipts && $p->id !== $receipts->id && ($p->auto_print || $p->print_website_orders)) {
                    $what = $p->auto_print ? 'الطباعة التلقائية بعد العملية' : 'طباعة طلبات الموقع';
                    $note(self::CERTAIN, 'auto_elsewhere', "«{$what}» مفعّلة على «{$p->name}» وليست الطابعة الافتراضية، فلا أثر لها. فعّلها على «{$receipts->name}».", $p);
                }
            }

            $seen = [];
            foreach ($active as $p) {
                $name = 'name:'.$p->way().':'.mb_strtolower(trim($p->name));
                $net = $p->way() === 'network' && filled($p->address) ? 'net:'.mb_strtolower(trim($p->address)).':'.($p->port ?: PosPeripheral::DEFAULT_PORT) : null;
                if ($net !== null && isset($seen[$net])) {
                    $note(self::CERTAIN, 'duplicate', "الطابعة «{$p->name}» مسجّلة مرتين على الجهاز بالعنوان والمنفذ نفسيهما. احذف المكرّرة أو أوقفها.", $p);
                } elseif (isset($seen[$name])) {
                    $note(self::CHECK, 'same_name', "طابعتان باسم «{$p->name}» والاتصال نفسه. إن كانتا طابعة واحدة فاحذف المكرّرة، وإلا فغيّر اسم إحداهما.", $p);
                }
                $seen[$name] = true;
                if ($net !== null) {
                    $seen[$net] = true;
                }
            }

            if ($active->isEmpty()) {
                self::holder($device, $devices, $printersOf, $note);
            }
        }

        return $found;
    }

    /**
     * A device without a printer: is its printer on another device of its branch? Only ever «check» — a stopped device
     * may be the one this replaced, or a till stopped on purpose; nothing links the two.
     *
     * @param  Collection<int, PosDevice>  $devices
     */
    private static function holder(PosDevice $device, Collection $devices, callable $printersOf, callable $note): void
    {
        foreach ($devices as $other) {
            if ($other->id === $device->id || $other->branch_id !== $device->branch_id) {
                continue;
            }
            $held = $printersOf($other)->firstWhere('is_active', true);
            if (! $held) {
                continue;
            }
            if ($other->status === PosDevice::REVOKED) {
                $note(self::CHECK, 'printer_on_revoked', "الجهاز بلا طابعة، وفي فرعه جهاز موقوف «{$other->name}» عليه الطابعة «{$held->name}». إن كان هذا بديله فأضف الطابعة هنا أو أعد ربط الجهاز القديم برمز جديد؛ وإلا فلا شيء عليك.");

                return;
            }
            if ($other->status === PosDevice::ACTIVE && $other->id < $device->id && $device->activated_at
                && ($other->last_seen_at === null || $other->last_seen_at->lt($device->activated_at))) {
                $note(self::CHECK, 'printer_on_idle', "الجهاز بلا طابعة، وجهاز أقدم في فرعه «{$other->name}» عليه الطابعة «{$held->name}» ولم يُستعمل منذ فُعّل هذا. إن كان هذا بديله فانقل الطابعة إليه؛ وإن كانا جهازين فلا شيء عليك.");

                return;
            }
        }
    }
}
