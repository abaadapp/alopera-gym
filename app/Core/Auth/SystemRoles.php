<?php

namespace App\Core\Auth;

/**
 * Roles every new club starts with. Clubs can add custom roles; only the owner
 * role is locked.
 */
final class SystemRoles
{
    public const OWNER = 'owner';

    public const MANAGER = 'manager';

    public const RECEPTION = 'reception';

    public const FINANCE = 'finance';

    public const TRAINER = 'trainer';

    /** @return array<string, array{name: string, description: string, permissions: list<string>, home: string}> */
    public static function definitions(): array
    {
        return [
            self::OWNER => [
                'name' => 'مالكة النادي',
                'description' => 'كل الصلاحيات، ولا يمكن تعديل هذا الدور.',
                'permissions' => ['*'],
                'home' => 'club.dashboard',
            ],
            self::MANAGER => [
                'name' => 'الإدارة',
                'description' => 'إدارة النادي اليومية دون النسخ الاحتياطي والأدوار.',
                'permissions' => array_values(array_diff(PermissionRegistry::all(), [
                    'roles.manage', 'backups.manage', 'billing.view', 'integrations.manage', 'members.delete',
                ])),
                'home' => 'club.dashboard',
            ],
            self::RECEPTION => [
                'name' => 'الاستقبال',
                'description' => 'تسجيل الدخول والحجوزات وبيع العضويات وتحصيل الدفعات.',
                'permissions' => [
                    'members.view', 'members.manage', 'members.documents',
                    'memberships.view', 'memberships.sell', 'memberships.freeze',
                    'classes.view', 'bookings.manage', 'attendance.mark',
                    'checkin.perform', 'checkin.view',
                    'invoices.view', 'invoices.manage', 'payments.record',
                    'pos.sell', 'whatsapp.inbox', 'whatsapp.send',
                ],
                'home' => 'club.reception',
            ],
            self::FINANCE => [
                'name' => 'المالية',
                'description' => 'الفواتير والتحصيل والمصروفات والتقارير المالية.',
                'permissions' => [
                    'dashboard.view', 'members.view', 'memberships.view',
                    'invoices.view', 'invoices.manage', 'invoices.void', 'invoices.credit',
                    'payments.record', 'payments.confirm', 'payments.refund',
                    'expenses.manage', 'dues.view',
                    'products.manage', 'suppliers.manage', 'purchases.manage', 'pos.sell',
                    'reports.view', 'reports.financial', 'reports.export',
                ],
                'home' => 'club.dashboard',
            ],
            self::TRAINER => [
                'name' => 'المدربة',
                'description' => 'حصصها وجلساتها الفردية وتسجيل حضور مشتركاتها.',
                'permissions' => ['classes.view', 'attendance.mark'],
                'home' => 'club.trainer',
            ],
        ];
    }
}
