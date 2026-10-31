<?php

namespace App\Core\Auth;

/**
 * Every permission a club role can hold, grouped for the role editor.
 *
 * Keys are checked server-side through the Gate (`can:members.view` route
 * middleware, `$this->authorize()` and policies). Hiding a button in the UI is
 * never the only protection.
 */
final class PermissionRegistry
{
    /** @var array<string, array{label: string, permissions: array<string, string>}> */
    public const GROUPS = [
        'dashboard' => ['label' => 'لوحة التحكم', 'permissions' => [
            'dashboard.view' => 'عرض لوحة التحكم والمؤشرات',
        ]],
        'members' => ['label' => 'الأعضاء', 'permissions' => [
            'members.view' => 'عرض الأعضاء',
            'members.manage' => 'إضافة وتعديل الأعضاء',
            'members.delete' => 'حذف الأعضاء',
            'members.documents' => 'مستندات الأعضاء وعقودهم',
            'members.export' => 'تصدير بيانات الأعضاء',
        ]],
        'memberships' => ['label' => 'العضويات', 'permissions' => [
            'memberships.view' => 'عرض العضويات',
            'memberships.sell' => 'بيع وتجديد العضويات',
            'memberships.freeze' => 'تجميد واستئناف العضويات',
            'memberships.cancel' => 'إلغاء العضويات',
            'memberships.upgrade' => 'ترقية العضويات',
            'plans.manage' => 'إدارة الباقات والأسعار والخصومات',
        ]],
        'training' => ['label' => 'التدريب والحصص', 'permissions' => [
            'classes.view' => 'عرض الجداول والحصص',
            'classes.manage' => 'إدارة البرامج والجداول والحصص',
            'trainers.manage' => 'إدارة فريق التدريب ومواعيدهم',
            'bookings.manage' => 'حجز الحصص للأعضاء وإلغاؤها',
            'attendance.mark' => 'تسجيل الحضور والغياب في الحصص',
            'pt.manage' => 'إدارة الجلسات الفردية',
            'pt.close_early' => 'إغلاق الجلسات الفردية قبل موعدها',
        ]],
        'access' => ['label' => 'الدخول والحضور', 'permissions' => [
            'checkin.perform' => 'تسجيل دخول الأعضاء',
            'checkin.view' => 'عرض سجل الدخول',
            'devices.manage' => 'أجهزة البصمة والبوابات',
        ]],
        'finance' => ['label' => 'المالية', 'permissions' => [
            'invoices.view' => 'عرض الفواتير',
            'invoices.manage' => 'إصدار الفواتير',
            'invoices.void' => 'إلغاء الفواتير',
            'invoices.credit' => 'إصدار الإشعارات الدائنة',
            'payments.record' => 'تسجيل الدفعات',
            'payments.confirm' => 'تأكيد التحويلات البنكية',
            'payments.refund' => 'رد المبالغ',
            'expenses.manage' => 'المصروفات',
            'dues.view' => 'المستحقات والمتأخرات',
        ]],
        'accounting' => ['label' => 'المحاسبة', 'permissions' => [
            'accounting.view' => 'الملخص المالي والحسابات والقيود والتقارير المالية',
            'accounting.manage' => 'الحسابات البنكية والقيود اليدوية والإعدادات المالية',
        ]],
        'payroll' => ['label' => 'الرواتب والموظفون', 'permissions' => [
            'employees.view' => 'عرض ملفات الموظفين (دون الرواتب)',
            'employees.create' => 'إضافة الموظفين',
            'employees.manage' => 'تعديل بيانات الموظفين الأساسية والمسميات الوظيفية',
            'employees.access' => 'حسابات دخول الموظفين وأدوارهم وصلاحياتهم',
            'payroll.salaries' => 'عرض الرواتب والبيانات البنكية (سري)',
            'payroll.compensation' => 'تعديل الرواتب والبدلات والبيانات البنكية',
            'payroll.prepare' => 'إعداد مسيرات الرواتب',
            'payroll.edit' => 'تعديل المسيرات غير المعتمدة',
            'payroll.approve' => 'اعتماد مسيرات الرواتب',
            'payroll.pay' => 'صرف الرواتب',
            'payroll.payslips' => 'عرض كشوف الرواتب وسندات الصرف وطباعتها',
            'payroll.reports' => 'تقارير الرواتب وتصديرها',
            'staff_attendance.view' => 'عرض حضور وانصراف الموظفين',
            'staff_attendance.manage' => 'تسجيل الحضور يدويًا وتصحيحه',
        ]],
        'sales' => ['label' => 'المبيعات والمتجر', 'permissions' => [
            'sales.view' => 'ملخص المبيعات وعملياتها وتقاريرها',
            'pos.sell' => 'البيع من نقطة البيع',
            'sales.discount' => 'تطبيق الخصومات عند البيع',
            'sales.refund' => 'إلغاء عمليات البيع ورد المبالغ',
            'products.manage' => 'المنتجات والتصنيفات والإضافات والأسعار والمخزون',
            'orders.manage' => 'طلبات الموقع: المراجعة والتجهيز والتسليم',
            'pos_devices.manage' => 'أجهزة نقاط البيع وملحقاتها وتفعيلها',
        ]],
        'inventory' => ['label' => 'المخزون والمشتريات', 'permissions' => [
            'inventory.view' => 'عرض المخزون والأرصدة وسجل الحركة',
            'inventory.adjust' => 'الجرد وتسجيل التالف والفاقد والتسويات',
            'inventory.transfer' => 'طلب نقل المخزون وصرفه واستلامه وطلبات تزويد الفروع',
            'inventory.approve' => 'اعتماد الجرد ونقل المخزون وطلبات التزويد',
            'purchases.view' => 'عرض المشتريات وأوامر الشراء وسندات الموردين',
            'purchases.manage' => 'إنشاء أوامر الشراء وسندات الموردين',
            'purchases.approve' => 'اعتماد أوامر الشراء وسندات الاستلام وسندات الموردين',
            'purchases.receive' => 'استلام البضاعة',
            'purchases.pay' => 'سداد الموردين',
            'suppliers.manage' => 'الموردون',
        ]],
        'documents' => ['label' => 'مستندات النشاط', 'permissions' => [
            'documents.view' => 'عرض المستندات (العامة، والسرية المسندة إليه)',
            'documents.create' => 'إضافة المستندات',
            'documents.renew' => 'تجديد المستندات',
            'documents.manage' => 'تعديل المستندات وحذفها، وأنواعها وتنبيهاتها',
            'documents.confidential' => 'الاطلاع على كل المستندات السرية',
        ]],
        'reports' => ['label' => 'التقارير', 'permissions' => [
            'reports.view' => 'عرض التقارير',
            'reports.financial' => 'التقارير المالية',
            'reports.export' => 'تصدير التقارير',
        ]],
        'messaging' => ['label' => 'واتساب والتسويق', 'permissions' => [
            'whatsapp.inbox' => 'صندوق المحادثات',
            'whatsapp.send' => 'إرسال الرسائل',
            'campaigns.manage' => 'الحملات التسويقية',
            'templates.manage' => 'القوالب والردود الآلية',
        ]],
        'website' => ['label' => 'الموقع الإلكتروني', 'permissions' => [
            'website.manage' => 'إدارة محتوى الموقع',
        ]],
        'settings' => ['label' => 'الإعدادات والإدارة', 'permissions' => [
            'settings.manage' => 'إعدادات النادي والهوية',
            'papers.manage' => 'قوالب الأوراق (الفواتير والسندات والكشوف المطبوعة)',
            'branches.manage' => 'الفروع',
            'users.manage' => 'المستخدمون',
            'roles.manage' => 'الأدوار والصلاحيات',
            'integrations.manage' => 'التطبيقات التكاملية: مفاتيح Paymob وواتساب',
            'audit.view' => 'سجل التدقيق',
            'backups.manage' => 'النسخ الاحتياطي والاستعادة',
            'billing.view' => 'اشتراك النادي في المنصة',
        ]],
    ];

    /**
     * Permissions only the club owner holds (the owner role is "*"): never granted through another role, whatever is
     * stored on it or sent by the role editor. The club website is the owner's alone.
     */
    public const OWNER_ONLY = ['website.manage'];

    /** Permission groups that belong to an optional module (config gym.modules.<module>). */
    private const MODULE_GROUPS = ['sales' => 'sales', 'inventory' => 'inventory'];

    public static function moduleEnabled(string $module): bool
    {
        return (bool) config("gym.modules.{$module}", false);
    }

    /**
     * The groups of the modules that are switched on. A switched-off module's permissions are not
     * granted to anyone (the owner's "*" included) and do not appear in the role editor.
     *
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public static function groups(): array
    {
        return array_filter(
            self::GROUPS,
            fn (string $key) => ! isset(self::MODULE_GROUPS[$key]) || self::moduleEnabled(self::MODULE_GROUPS[$key]),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return list<string> */
    public static function all(): array
    {
        $keys = [];

        foreach (self::groups() as $group) {
            array_push($keys, ...array_keys($group['permissions']));
        }

        return $keys;
    }

    /**
     * Permissions of switched-off modules found in a stored list: kept when a role is edited, so they come
     * back if the module is switched on again.
     *
     * @param  array<int, mixed>  $stored
     * @return list<string>
     */
    public static function dormant(array $stored): array
    {
        $dormant = [];

        foreach (array_diff_key(self::GROUPS, self::groups()) as $group) {
            array_push($dormant, ...array_keys($group['permissions']));
        }

        return array_values(array_intersect($dormant, $stored));
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /**
     * Drop unknown keys so a crafted request cannot store a permission the
     * system does not define.
     *
     * @param  array<int, mixed>  $permissions
     * @return list<string>
     */
    public static function sanitize(array $permissions): array
    {
        return array_values(array_diff(array_intersect(self::all(), $permissions), self::OWNER_ONLY));
    }

    /**
     * The groups as the role editor shows them: owner-only permissions are not offered.
     *
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public static function editorGroups(): array
    {
        $groups = array_map(fn (array $group) => ['label' => $group['label'], 'permissions' => array_diff_key($group['permissions'], array_flip(self::OWNER_ONLY))], self::groups());

        return array_filter($groups, fn (array $group) => $group['permissions'] !== []);
    }
}
