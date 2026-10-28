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
        'members' => ['label' => 'المشتركات', 'permissions' => [
            'members.view' => 'عرض المشتركات',
            'members.manage' => 'إضافة وتعديل المشتركات',
            'members.delete' => 'حذف المشتركات',
            'members.documents' => 'المستندات والعقود',
            'members.export' => 'تصدير بيانات المشتركات',
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
            'trainers.manage' => 'إدارة المدربات ومواعيدهن',
            'bookings.manage' => 'حجز الحصص للمشتركات وإلغاؤها',
            'attendance.mark' => 'تسجيل الحضور والغياب في الحصص',
            'pt.manage' => 'إدارة الجلسات الفردية',
            'pt.close_early' => 'إغلاق الجلسات الفردية قبل موعدها',
        ]],
        'access' => ['label' => 'الدخول والحضور', 'permissions' => [
            'checkin.perform' => 'تسجيل دخول المشتركات',
            'checkin.view' => 'عرض سجل الدخول',
            'devices.manage' => 'إدارة أجهزة الدخول',
            'staff_attendance.view' => 'عرض حضور الموظفات',
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
        'inventory' => ['label' => 'المخزون والمبيعات', 'permissions' => [
            'pos.sell' => 'نقطة البيع',
            'products.manage' => 'المنتجات والمخزون',
            'suppliers.manage' => 'الموردون',
            'purchases.manage' => 'المشتريات',
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
            'branches.manage' => 'الفروع',
            'users.manage' => 'المستخدمون',
            'roles.manage' => 'الأدوار والصلاحيات',
            'integrations.manage' => 'إعدادات الدفع وواتساب',
            'audit.view' => 'سجل التدقيق',
            'backups.manage' => 'النسخ الاحتياطي والاستعادة',
            'billing.view' => 'اشتراك النادي في المنصة',
        ]],
    ];

    /**
     * Permissions only the club owner holds (her role is "*"): never granted through another role, whatever is
     * stored on it or sent by the role editor. The club website is the owner's alone.
     */
    public const OWNER_ONLY = ['website.manage'];

    /** Permission groups that belong to an optional module (config gym.modules.<module>). */
    private const MODULE_GROUPS = ['inventory' => 'inventory'];

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
