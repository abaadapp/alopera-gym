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
                    // The WhatsApp connection's keys go with the integrations: the owner's unless granted by name.
                    'whatsapp.manage_settings',
                    // The website is the owner's, or whoever the owner grants it to by name.
                    'website.manage',
                    // Salaries stay confidential: every payroll step shows them, so none is the manager's until the owner
                    // grants salaries by name. Suppliers are paid by finance.
                    'payroll.salaries', 'payroll.compensation', 'payroll.prepare', 'payroll.edit', 'payroll.approve', 'payroll.pay',
                    'payroll.payslips', 'payroll.reports', 'accounting.manage', 'purchases.pay',
                    // A manager tied to a branch sees their branch's figures (the reports), not the club's books and banks.
                    'accounting.all_branches',
                    // Sensitive business documents are the owner's unless shared one by one.
                    'documents.confidential',
                    // Undoing money, the books' closed months and the drawer's shifts stay the owner's until granted by name.
                    'payments.cancel', 'accounting.close', 'accounting.reopen', 'cash_shifts.manage', 'cash_shifts.view',
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
                    'pos.sell', 'orders.manage', 'papers.print',
                    // The chats assigned to reception, answered; templates to a member from the profile.
                    'whatsapp.view_assigned', 'whatsapp.reply', 'whatsapp.send',
                ],
                'home' => 'club.reception',
            ],
            self::FINANCE => [
                'name' => 'المالية والمحاسبة',
                'description' => 'الفواتير والتحصيل والمصروفات والحسابات والتقارير المالية، والاطلاع على الرواتب وإعداد مسيراتها. اعتماد المسيرات وصرفها للمالك أو لمن يفوّضه بالاسم.',
                // Approving and paying salaries are the owner's until granted, each on its own (payroll.approve, payroll.pay).
                'permissions' => [
                    'dashboard.view', 'members.view', 'memberships.view',
                    'invoices.view', 'invoices.manage', 'invoices.void', 'invoices.credit',
                    'payments.record', 'payments.confirm', 'payments.refund',
                    'cash_shifts.view', 'expenses.manage', 'dues.view',
                    'accounting.view', 'accounting.manage', 'accounting.all_branches',
                    'employees.view', 'payroll.salaries', 'payroll.prepare', 'payroll.edit', 'payroll.payslips', 'payroll.reports',
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
                'permissions' => ['pos.sell', 'members.view', 'memberships.sell', 'pt.sell', 'papers.print'],
                'home' => 'club.pos.index',
            ],
        ];
    }
}
