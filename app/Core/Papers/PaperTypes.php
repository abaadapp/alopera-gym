<?php

namespace App\Core\Papers;

use App\Core\Auth\PermissionRegistry;
use App\Models\Tenant;

/**
 * Every paper the club panel prints, and how each club has set it up (قوالب الأوراق).
 *
 * One registry feeds the settings cards, the editor, the validation and the drawing, so a paper cannot be offered on
 * screen and then refused on save, or saved and then ignored when drawn. A paper is listed only when the panel really
 * prints it from a screen; there is no template for a document the club cannot produce.
 *
 * The switches offered are the optional parts. What a document is not valid without (its number and date, the
 * customer on an invoice, the amounts, the club's tax number on a tax invoice) has no switch: it is listed under
 * `required` and always printed.
 *
 * Settings belong to the club: they are kept in its own settings (tenants.settings.papers.<type>), never shared.
 */
final class PaperTypes
{
    /** The optional parts a paper may switch on or off. */
    public const FIELDS = [
        'show_logo' => ['label' => 'شعار النادي', 'hint' => 'يظهر إن كان للنادي شعار محفوظ في الإعدادات العامة'],
        'show_branch' => ['label' => 'اسم الفرع', 'hint' => 'الفرع الذي تمت فيه العملية — يفيد النادي متعدد الفروع'],
        'show_employee' => ['label' => 'اسم الموظف', 'hint' => 'من أصدر المستند أو استلم المبلغ'],
        'show_phone' => ['label' => 'هاتف العميل'],
        'show_items_count' => ['label' => 'عدد الأصناف'],
        'show_payments' => ['label' => 'المدفوعات المسجلة', 'hint' => 'قائمة الدفعات وأرقام إيصالاتها تحت الإجماليات'],
        'show_prices' => ['label' => 'الأسعار والمبالغ', 'hint' => 'أطفئه في سند يوقّعه أمين المخزن فلا يرى التكلفة'],
        'show_attendance' => ['label' => 'أيام وساعات الحضور'],
        'show_bank' => ['label' => 'البنك وآخر أرقام الحساب', 'hint' => 'يظهر آخر أربعة أرقام فقط — لا يُطبع الآيبان كاملًا'],
        'show_notes' => ['label' => 'الملاحظات'],
        'show_signature' => ['label' => 'خانات التوقيع'],
    ];

    public const TYPES = [
        'sale' => [
            'module' => 'sales',
            'label' => 'فاتورة البيع',
            'desc' => 'ما يُباع من نقطة البيع وطلبات المتجر — إيصال حراري وفاتورة A4 من قالب واحد',
            'section' => 'المبيعات',
            'sheet' => true,
            'strip' => true,
            'footer' => "شكرًا لزيارتكم\nنتمنى لكم تمرينًا ممتعًا",
            'fields' => ['show_logo' => true, 'show_branch' => true, 'show_employee' => true, 'show_phone' => false, 'show_items_count' => false, 'show_notes' => true],
            'required' => ['رقم العملية والتاريخ', 'الأصناف والإضافات بكمياتها وأسعارها', 'الإجمالي ووسيلة الدفع', 'الرقم الضريبي عند احتساب الضريبة'],
        ],
        'invoice' => [
            'label' => 'فاتورة العميل',
            'desc' => 'فواتير العضويات والتجديد والترقية والتدريب الشخصي والخدمات والفواتير اليدوية',
            'section' => 'المالية',
            'sheet' => true,
            'footer' => '',
            'fields' => ['show_logo' => true, 'show_branch' => false, 'show_employee' => false, 'show_phone' => true, 'show_payments' => true, 'show_notes' => true, 'show_signature' => false],
            'required' => ['رقم الفاتورة وتاريخ إصدارها', 'اسم العضو أو العميل', 'البنود والأسعار والخصومات', 'الإجمالي والمدفوع والمتبقي', 'الرقم الضريبي عند احتساب الضريبة'],
        ],
        'receipt' => [
            'label' => 'سند القبض',
            'desc' => 'إيصال الدفع الذي يُسلَّم لمن دفع — وسند رد المبلغ عند الاسترداد',
            'section' => 'المالية',
            'sheet' => true,
            'strip' => true,
            'footer' => '',
            'fields' => ['show_logo' => true, 'show_branch' => false, 'show_employee' => true, 'show_notes' => true, 'show_signature' => true],
            'required' => ['رقم السند وتاريخه', 'اسم الدافع', 'المبلغ ووسيلة الدفع', 'الفاتورة المسددة ورصيدها'],
        ],
        'credit_note' => [
            'label' => 'إشعار دائن',
            'desc' => 'يخفّض صافي فاتورة صدرت دون تعديلها — مع سببه',
            'section' => 'المالية',
            'sheet' => true,
            'footer' => '',
            'fields' => ['show_logo' => true, 'show_employee' => false, 'show_notes' => true, 'show_signature' => false],
            'required' => ['رقم الإشعار وتاريخه', 'الفاتورة الأصلية', 'اسم العميل', 'المبلغ وسببه'],
        ],
        'purchase_order' => [
            'module' => 'inventory',
            'label' => 'أمر شراء',
            'desc' => 'يُرسل إلى المورد بما يُطلب وكمياته وأسعاره',
            'section' => 'المشتريات',
            'sheet' => true,
            'footer' => '',
            'fields' => ['show_logo' => true, 'show_branch' => true, 'show_prices' => true, 'show_items_count' => true, 'show_notes' => true, 'show_signature' => false],
            'required' => ['رقم الأمر وتاريخه', 'اسم المورد', 'الأصناف والكميات'],
        ],
        'goods_receipt' => [
            'module' => 'inventory',
            'label' => 'سند استلام بضاعة',
            'desc' => 'يُوقَّع عند استلام الشحنة في المخزن',
            'section' => 'المشتريات',
            'sheet' => true,
            'footer' => '',
            'fields' => ['show_logo' => true, 'show_branch' => true, 'show_employee' => true, 'show_prices' => false, 'show_notes' => true, 'show_signature' => true],
            'required' => ['رقم السند وتاريخه', 'اسم المورد وأمر الشراء', 'الأصناف والكميات المستلمة'],
        ],
        'supplier_bill' => [
            'module' => 'inventory',
            'label' => 'فاتورة المورد',
            'desc' => 'ما على النادي لمورده — مرجعها وتواريخها ومبالغها وسدادها',
            'section' => 'المشتريات',
            'sheet' => true,
            'footer' => '',
            'fields' => ['show_logo' => true, 'show_payments' => true, 'show_notes' => true],
            'required' => ['مرجع فاتورة المورد وتاريخها', 'اسم المورد', 'الإجمالي والمسدد والمتبقي'],
        ],
        'payslip' => [
            'label' => 'كشف راتب الموظف',
            'desc' => 'راتب موظف واحد في شهر: مستحقاته وخصوماته وصافيه — سري',
            'section' => 'الرواتب',
            'sheet' => true,
            'footer' => '',
            'fields' => ['show_logo' => true, 'show_attendance' => true, 'show_bank' => true, 'show_notes' => true, 'show_signature' => true],
            'required' => ['الموظف والشهر', 'الأساسي والبدلات والإضافي والخصومات', 'صافي الراتب'],
        ],
        'payroll_sheet' => [
            'label' => 'كشف مسيرة الرواتب',
            'desc' => 'رواتب الشهر كلها في ورقة واحدة للاعتماد والحفظ — سري',
            'section' => 'الرواتب',
            'sheet' => true,
            'footer' => '',
            'fields' => ['show_logo' => true, 'show_attendance' => false, 'show_signature' => true],
            'required' => ['رقم المسيرة وشهرها وحالتها', 'سطر لكل موظف بمستحقاته وخصوماته وصافيه', 'الإجماليات'],
        ],
        'payroll_payout' => [
            'label' => 'سند صرف رواتب',
            'desc' => 'ما صُرف في عملية واحدة: من أي حساب، ولمن، وكم — يُسجَّل ولا يُحوِّل مالًا',
            'section' => 'الرواتب',
            'sheet' => true,
            'footer' => '',
            'fields' => ['show_logo' => true, 'show_notes' => true, 'show_signature' => true],
            'required' => ['رقم السند وتاريخ الصرف', 'حساب الصرف', 'الموظفون ومبالغهم', 'إجمالي الصرف'],
        ],
    ];

    public const FONTS = ['small' => 'صغير', 'normal' => 'عادي', 'large' => 'كبير'];

    public const LANGS = ['ar_en' => 'عربي وإنجليزي', 'ar' => 'عربي فقط'];

    /** A known paper whose module (sales, inventory) is switched on for this installation. */
    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]) && (! isset(self::TYPES[$type]['module']) || PermissionRegistry::moduleEnabled(self::TYPES[$type]['module']));
    }

    /** @return array<string, mixed> */
    public static function defaults(string $type): array
    {
        $spec = self::TYPES[$type];
        $out = $spec['fields'] + ['header' => '', 'footer' => $spec['footer'] ?? '', 'font' => 'normal', 'lang' => 'ar_en'];
        if ($spec['sheet'] ?? false) {
            $out['paper'] = PaperSize::A4;
        }
        if ($spec['strip'] ?? false) {
            $out['strip'] = PaperSize::T80;
        }

        return $out;
    }

    /**
     * The settings of a paper for a club, each value cast and checked: a saved value the paper no longer accepts
     * falls back to the default. $override is what the editor has not saved yet (live preview).
     *
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>
     */
    public static function settings(Tenant $tenant, string $type, ?array $override = null): array
    {
        $saved = (array) ($tenant->settings['papers'][$type] ?? []);
        $defaults = self::defaults($type);
        // The invoice footer written in the club settings before templates existed stays the invoice's footer
        // until one is written here.
        if ($type === 'invoice' && filled($tenant->setting('invoice_footer'))) {
            $defaults['footer'] = (string) $tenant->setting('invoice_footer');
        }
        $out = [];
        foreach ($defaults as $key => $default) {
            $raw = $override !== null && array_key_exists($key, $override) ? $override[$key] : ($saved[$key] ?? null);
            $out[$key] = self::cast($key, $raw, $default);
        }

        return $out;
    }

    private static function cast(string $key, mixed $raw, mixed $default): mixed
    {
        if ($raw === null) {
            return $default;
        }
        if (str_starts_with($key, 'show_')) {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $default;
        }
        $value = trim((string) $raw);

        return match ($key) {
            'font' => isset(self::FONTS[$value]) ? $value : $default,
            'lang' => isset(self::LANGS[$value]) ? $value : $default,
            'paper' => in_array($value, PaperSize::sheets(), true) ? $value : $default,
            'strip' => in_array($value, PaperSize::strips(), true) ? $value : $default,
            // An emptied header or footer is a choice, not a missing value.
            default => mb_substr($value, 0, $key === 'header' ? 120 : 500),
        };
    }

    /** @return array<string, list<string>> */
    public static function rules(string $type): array
    {
        $rules = [];
        foreach (self::defaults($type) as $key => $default) {
            $rules[$key] = match (true) {
                str_starts_with($key, 'show_') => ['sometimes', 'boolean'],
                $key === 'font' => ['sometimes', 'in:'.implode(',', array_keys(self::FONTS))],
                $key === 'lang' => ['sometimes', 'in:'.implode(',', array_keys(self::LANGS))],
                $key === 'paper' => ['sometimes', 'in:'.implode(',', PaperSize::sheets())],
                $key === 'strip' => ['sometimes', 'in:'.implode(',', PaperSize::strips())],
                $key === 'header' => ['sometimes', 'nullable', 'string', 'max:120'],
                default => ['sometimes', 'nullable', 'string', 'max:500'],
            };
        }

        return $rules;
    }

    /** Keep only the keys this paper has, cast as they will be read. */
    public static function save(Tenant $tenant, string $type, array $data): void
    {
        $values = [];
        foreach (self::defaults($type) as $key => $default) {
            if (array_key_exists($key, $data)) {
                $values[$key] = self::cast($key, $data[$key] ?? '', $default);
            }
        }
        $settings = $tenant->settings ?? [];
        $settings['papers'][$type] = array_replace((array) ($settings['papers'][$type] ?? []), $values);
        $tenant->forceFill(['settings' => $settings])->save();
    }

    /** Back to the defaults: the club's saved values for this paper are dropped. */
    public static function reset(Tenant $tenant, string $type): void
    {
        $settings = $tenant->settings ?? [];
        unset($settings['papers'][$type]);
        $tenant->forceFill(['settings' => $settings])->save();
    }

    /** @return list<array{key: string, label: string, desc: string, section: string, customised: bool}> */
    public static function cards(Tenant $tenant): array
    {
        $out = [];
        foreach (self::TYPES as $key => $spec) {
            if (! self::exists($key)) {
                continue;
            }
            $out[] = ['key' => $key, 'label' => $spec['label'], 'desc' => $spec['desc'], 'section' => $spec['section'], 'customised' => ! empty($tenant->settings['papers'][$key] ?? null)];
        }

        return $out;
    }

    /** Everything the editor needs for one paper. */
    public static function describe(Tenant $tenant, string $type): array
    {
        $spec = self::TYPES[$type];

        return [
            'key' => $type,
            'label' => $spec['label'],
            'desc' => $spec['desc'],
            'section' => $spec['section'],
            'sheet' => (bool) ($spec['sheet'] ?? false),
            'strip' => (bool) ($spec['strip'] ?? false),
            'fields' => array_map(fn ($key) => ['key' => $key, 'label' => self::FIELDS[$key]['label'], 'hint' => self::FIELDS[$key]['hint'] ?? null], array_keys($spec['fields'])),
            'required' => $spec['required'],
            'fonts' => self::FONTS,
            'langs' => self::LANGS,
            'papers' => PaperSize::sheets(),
            'strips' => PaperSize::strips(),
            'values' => self::settings($tenant, $type),
            'defaults' => self::defaults($type),
        ];
    }
}
