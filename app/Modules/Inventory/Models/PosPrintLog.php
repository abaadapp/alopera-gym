<?php

namespace App\Modules\Inventory\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One print the panel started and how it ended. «printed» only when the club's app sent the receipt to a network
 * printer and the printer took it; a print dialog opened (the system's or the browser's) is «opened», since nothing
 * says whether the paper came out. An automatic print is «claimed» first, under a unique key, and completed after.
 */
class PosPrintLog extends Model
{
    use BelongsToTenant;

    public const CLAIMED = 'claimed';

    public const PRINTED = 'printed';

    public const OPENED = 'opened';

    public const FAILED = 'failed';

    public const TRIGGERS = ['auto' => 'تلقائية', 'manual' => 'يدوية', 'reprint' => 'إعادة طباعة', 'test' => 'اختبار'];

    public const VIAS = ['native' => 'مباشرة من التطبيق', 'system' => 'حوار طباعة النظام', 'browser' => 'نافذة المتصفح', 'page' => 'حوار طباعة المتصفح', 'share' => 'قائمة المشاركة'];

    /** What a failure says, in plain words (mobile/PROTOCOL.md §8 and the codes the page adds). */
    public const MESSAGES = [
        'UNTRUSTED_ORIGIN' => 'طلب طباعة مرفوض', 'INVALID_JOB' => 'طلب طباعة مرفوض', 'RECEIPT_URL_REJECTED' => 'طلب طباعة مرفوض',
        'UNSUPPORTED_CONNECTION' => 'الطباعة المباشرة تدعم طابعات الشبكة فقط حاليًا',
        'INVALID_PRINTER_ADDRESS' => 'عنوان الطابعة غير صالح — يجب أن يكون عنوانًا على الشبكة المحلية',
        'INVALID_PORT' => 'رقم المنفذ غير صالح', 'SESSION_EXPIRED' => 'انتهت الجلسة — سجّل الدخول من جديد',
        'RECEIPT_DOWNLOAD_FAILED' => 'تعذر تنزيل الإيصال', 'RECEIPT_RENDER_FAILED' => 'تعذر تجهيز الإيصال للطباعة',
        'PRINTER_UNREACHABLE' => 'تعذر الوصول إلى عنوان الطابعة', 'PORT_REFUSED' => 'المنفذ لا يستجيب', 'CONNECT_TIMEOUT' => 'انتهت مهلة الاتصال',
        'SEND_FAILED' => 'انقطع الاتصال أثناء الإرسال — تحقق من الورق قبل إعادة الطباعة', 'QUEUE_FULL' => 'الطابعة مشغولة — حاول بعد لحظات',
        'BRIDGE_TIMEOUT' => 'لم يصل رد من التطبيق — تحقق من الورق قبل إعادة الطباعة', 'BRIDGE_UNAVAILABLE' => 'تعذر الوصول إلى التطبيق',
        'NATIVE_REQUIRED' => 'الطباعة المباشرة تحتاج تطبيق الجم', 'POPUP_BLOCKED' => 'منع المتصفح فتح نافذة الطباعة',
        'IN_PAGE_UNSUPPORTED' => 'هذا المتصفح لا يطبع من داخل الصفحة', 'PRINT_DIALOG_FAILED' => 'لم يُفتح حوار الطباعة',
        'SHARE_CANCELLED' => 'أُغلقت قائمة المشاركة', 'SHARE_BLOCKED' => 'لم تُفتح قائمة المشاركة', 'SHARE_FAILED' => 'تعذرت مشاركة الإيصال',
        'INTERNAL' => 'تعذرت الطباعة',
    ];

    protected $guarded = ['id', 'tenant_id'];

    public function message(): ?string
    {
        return $this->code ? (self::MESSAGES[$this->code] ?? self::MESSAGES['INTERNAL']) : null;
    }

    protected function casts(): array
    {
        return ['may_have_printed' => 'boolean', 'connected' => 'boolean'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PosDevice::class, 'pos_device_id');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(PosPeripheral::class, 'pos_peripheral_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
