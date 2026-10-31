<?php

namespace App\Core\Auth;

/**
 * The gym's starting roles: the business owner, the manager (of the gym or a branch), reception, finance and
 * accounting, the sales and point-of-sale clerk, and the trainer. The owner adds custom roles; only the owner role is
 * locked.
 */
final class SystemRoles
{
    public const OWNER = 'owner';

    public const MANAGER = 'manager';

    public const RECEPTION = 'reception';

    public const FINANCE = 'finance';

    public const TRAINER = 'trainer';

    public const CASHIER = 'cashier';

    /** @return array<string, array{name: string, description: string, permissions: list<string>, home: string}> */
    public static function definitions(): array
    {
        return [
            self::OWNER => [
                'name' => 'صاحب النشاط',
                'description' => 'كل الصلاحيات، ولا يمكن تعديل هذا الدور.',
                'permissions' => ['*'],
                'home' => 'club.dashboard',
            ],
            self::MANAGER => [
                'name' => 'مدير النشاط أو الفرع',
                'description' => 'إدارة النادي اليومية دون النسخ الاحتياطي والأدوار والرواتب السرية والموقع الإلكتروني.',
                'permissions' => array_values(array_diff(PermissionRegistry::all(), [
                    'roles.manage', 'backups.manage', 'integrations.manage', 'members.delete',
                    // The website is the owner's, or whoever the owner grants it to by name.
                    'website.manage',
                    // Salaries stay confidential, approved by the owner and paid by finance; suppliers are paid by finance.
                    'payroll.salaries', 'payroll.compensation', 'payroll.approve', 'payroll.pay', 'accounting.manage', 'purchases.pay',
                    // Sensitive business documents are the owner's unless shared one by one.
                    'documents.confidential',
                ])),
                'home' => 'club.dashboard',
            ],
            self::RECEPTION => [
                'name' => 'الاستقبال',
                'description' => 'تسجيل الدخول والحجوزات وبيع العضويات والمنتجات وتحصيل الدفعات وتسليم طلبات الموقع.',
                'permissions' => [
                    'members.view', 'members.manage', 'members.documents',
                    'memberships.view', 'memberships.sell', 'memberships.freeze',
                    'classes.view', 'bookings.manage', 'attendance.mark',
                    'checkin.perform', 'checkin.view',
                    'invoices.view', 'invoices.manage', 'payments.record',
                    'pos.sell', 'orders.manage', 'whatsapp.inbox', 'whatsapp.send', 'papers.print',
                ],
                'home' => 'club.reception',
            ],
            self::FINANCE => [
                'name' => 'المالية والمحاسبة',
                'description' => 'الفواتير والتحصيل والمصروفات والحسابات، وإعداد الرواتب وصرفها بعد اعتماد المالك.',
                'permissions' => [
                    'dashboard.view', 'members.view', 'memberships.view',
                    'invoices.view', 'invoices.manage', 'invoices.void', 'invoices.credit',
                    'payments.record', 'payments.confirm', 'payments.refund',
                    'expenses.manage', 'dues.view',
                    'accounting.view', 'accounting.manage',
                    'employees.view', 'payroll.salaries', 'payroll.prepare', 'payroll.edit', 'payroll.pay', 'payroll.payslips', 'payroll.reports',
                    'sales.view', 'sales.refund', 'products.manage', 'suppliers.view', 'suppliers.create', 'suppliers.manage', 'purchases.manage', 'pos.sell',
                    'inventory.view', 'purchases.view', 'purchases.pay',
                    'reports.view', 'reports.financial', 'reports.export', 'papers.print',
                ],
                'home' => 'club.dashboard',
            ],
            self::TRAINER => [
                'name' => 'المدرب',
                'description' => 'حصصه وجلساته الفردية وتسجيل حضور أعضائه.',
                'permissions' => ['classes.view', 'attendance.mark'],
                'home' => 'club.trainer',
            ],
            self::CASHIER => [
                'name' => 'موظف المبيعات ونقطة البيع',
                'description' => 'البيع من نقطة البيع: المنتجات والعضويات وتجديدها والتدريب الشخصي، وطباعة الإيصالات.',
                'permissions' => ['pos.sell', 'members.view', 'memberships.view', 'memberships.sell', 'pt.sell', 'papers.print'],
                'home' => 'club.pos.index',
            ],
        ];
    }
}
