<?php

namespace App\Modules\Reports\Services;

use App\Core\Auth\PermissionRegistry;
use App\Models\Branch;
use App\Models\User;

/**
 * The reports page: one door to every report the club already has, in gym categories. Each card opens a page —
 * the period reports here (ReportController::show) and the reports that live in their own section (the books,
 * dues, payroll, the check-in log, documents). A user is given only the reports they may open, and a report of a
 * switched-off module or of a club with a single branch is not offered.
 */
final class ReportCatalog
{
    public const CATEGORIES = [
        'finance' => 'التقارير المالية',
        'members' => 'الأعضاء والعضويات',
        'classes' => 'الحصص والحجوزات',
        'training' => 'التدريب والمدربون',
        'sales' => 'المبيعات والمنتجات',
        'payroll' => 'الموظفون والرواتب',
        'attendance' => 'الحضور والدخول',
        'documents' => 'المستندات',
        'branches' => 'أداء الفروع',
    ];

    /** The period reports drawn on this module's own page, with who may open them. */
    public const DETAIL = [
        'finance' => 'reports.financial',
        'members' => 'reports.view',
        'classes' => 'reports.view',
        'trainers' => 'reports.view',
        'branches' => 'reports.view',
    ];

    /**
     * key, category, title, description, icon (a name the page maps to an icon), route, its parameters, the
     * permissions (any one is enough) and, when it matters, the module.
     *
     * @return list<array{key: string, category: string, title: string, desc: string, icon: string, route: string, params?: array<string, string>, can: list<string>, module?: string}>
     */
    private static function all(): array
    {
        $detail = fn (string $key) => ['route' => 'club.reports.show', 'params' => ['report' => $key], 'can' => [self::DETAIL[$key]]];

        return [
            ['key' => 'finance', 'category' => 'finance', 'title' => 'الإيرادات والمصروفات', 'icon' => 'wallet',
                'desc' => 'صافي التحصيل والمصروفات والربح التشغيلي ونسبة التحصيل، يومًا بيوم وحسب طريقة الدفع ونوع الإيراد، مقارنة بفترة سابقة.'] + $detail('finance'),
            ['key' => 'statements', 'category' => 'finance', 'title' => 'قائمة الدخل وميزان المراجعة', 'icon' => 'landmark', 'route' => 'club.finance.statements', 'can' => ['accounting.view'],
                'desc' => 'الإيرادات والمصروفات من دفتر القيود لفترة تختارها، وأرصدة الحسابات حتى تاريخها.'],
            ['key' => 'movement', 'category' => 'finance', 'title' => 'الحركة المالية', 'icon' => 'arrow-left-right', 'route' => 'club.finance.movement', 'can' => ['accounting.view'],
                'desc' => 'كل مبلغ دخل إلى الصندوق والبنوك وحسابات التسوية أو خرج منها، بالحساب والفترة.'],
            ['key' => 'dues', 'category' => 'finance', 'title' => 'المستحقات والمتأخرات', 'icon' => 'hand-coins', 'route' => 'club.dues', 'can' => ['dues.view'],
                'desc' => 'الفواتير غير المسددة موزعة على مدة التأخير، ومن عليه المتبقي.'],

            ['key' => 'members', 'category' => 'members', 'title' => 'العضويات والأعضاء', 'icon' => 'ticket',
                'desc' => 'الاشتراكات الجديدة والتجديدات والترقيات والإلغاءات والتجميد، والأعضاء الجدد ومرات الدخول، والباقات المباعة.'] + $detail('members'),
            ['key' => 'expiring', 'category' => 'members', 'title' => 'العضويات قريبة الانتهاء', 'icon' => 'hourglass', 'route' => 'club.memberships.index', 'params' => ['filter' => 'expiring'], 'can' => ['memberships.view'],
                'desc' => 'العضويات التي تنتهي خلال سبعة أيام، لمتابعة تجديدها قبل أن تنتهي.'],

            ['key' => 'classes', 'category' => 'classes', 'title' => 'الحصص والحضور', 'icon' => 'calendar-days',
                'desc' => 'الحصص والحجوزات ونسبتا الحضور والإشغال، والبرامج الأكثر طلبًا، مقارنة بفترة سابقة.'] + $detail('classes'),

            ['key' => 'trainers', 'category' => 'training', 'title' => 'أداء المدربين', 'icon' => 'dumbbell',
                'desc' => 'حصص كل مدرب وحجوزاتها وحضورها، وجلسات التدريب الشخصي التي أنجزها في الفترة.'] + $detail('trainers'),

            ['key' => 'sales', 'category' => 'sales', 'title' => 'تقارير المبيعات', 'icon' => 'shopping-bag', 'route' => 'club.sales.reports', 'can' => ['sales.view'], 'module' => 'sales',
                'desc' => 'إجمالي المبيعات وعدد العمليات والملغى والمسترد، والمبيعات اليومية وحسب المنتج.'],

            ['key' => 'payroll', 'category' => 'payroll', 'title' => 'مسيرات الرواتب', 'icon' => 'briefcase', 'route' => 'club.payroll.index', 'can' => ['payroll.prepare', 'payroll.approve', 'payroll.pay', 'payroll.reports'],
                'desc' => 'مسيرة كل شهر بموظفيها ورواتبها، وحالتها من التجهيز إلى الاعتماد والصرف.'],
            ['key' => 'payouts', 'category' => 'payroll', 'title' => 'صرف الرواتب', 'icon' => 'banknote', 'route' => 'club.payroll.payouts', 'can' => ['payroll.pay', 'payroll.reports'],
                'desc' => 'ما ينتظر الصرف في المسيرات المعتمدة، وسجل دفعات الصرف ومن أي حساب.'],

            ['key' => 'checkins', 'category' => 'attendance', 'title' => 'سجل دخول الأعضاء', 'icon' => 'door-open', 'route' => 'club.checkins.index', 'can' => ['checkin.view'],
                'desc' => 'كل قرار دخول من الاستقبال أو QR أو أجهزة البوابة، مسموحًا أو مرفوضًا مع السبب.'],
            ['key' => 'staff-attendance', 'category' => 'attendance', 'title' => 'حضور الموظفين', 'icon' => 'clock', 'route' => 'club.staff-attendance.index', 'can' => ['staff_attendance.view'],
                'desc' => 'أيام الحضور وساعات العمل لكل موظف من البصمة والتسجيل اليدوي، ومن لم يسجّل انصرافه.'],

            ['key' => 'documents', 'category' => 'documents', 'title' => 'المستندات قريبة الانتهاء', 'icon' => 'file-clock', 'route' => 'club.business-documents.index', 'params' => ['view' => 'expiring'], 'can' => ['documents.view'],
                'desc' => 'السجلات والتراخيص والعقود التي يقترب انتهاؤها، ومن المسؤول عن تجديدها.'],
            ['key' => 'renewals', 'category' => 'documents', 'title' => 'سجل تجديد المستندات', 'icon' => 'history', 'route' => 'club.business-documents.renewals', 'can' => ['documents.view'],
                'desc' => 'كل تجديد: من نفّذه ومتى، والانتهاء قبله وبعده، والنسخة السابقة.'],

            ['key' => 'branches', 'category' => 'branches', 'title' => 'مقارنة الفروع', 'icon' => 'building-2',
                'desc' => 'الأعضاء الجدد والعضويات النشطة ومرات الدخول والحصص والتحصيل لكل فرع، مقارنة بفترة سابقة.'] + $detail('branches'),
        ];
    }

    /** @return list<array{key: string, category: string, title: string, desc: string, icon: string, href: string}> */
    public function for(User $user): array
    {
        $branches = null;

        return array_values(array_map(
            fn (array $r) => ['key' => $r['key'], 'category' => $r['category'], 'title' => $r['title'], 'desc' => $r['desc'], 'icon' => $r['icon'], 'href' => route($r['route'], $r['params'] ?? [])],
            array_filter(self::all(), function (array $r) use ($user, &$branches) {
                if (isset($r['module']) && ! PermissionRegistry::moduleEnabled($r['module'])) {
                    return false;
                }
                if ($r['key'] === 'branches' && ! ($branches ??= $this->multiBranch())) {
                    return false;
                }

                return collect($r['can'])->contains(fn (string $p) => $user->can($p));
            }),
        ));
    }

    /** The branch comparison is offered to a club with more than one branch. */
    public function multiBranch(): bool
    {
        return Branch::count() > 1;
    }

    /** May this user open the period report $key here? */
    public function allows(User $user, string $key): bool
    {
        return isset(self::DETAIL[$key]) && $user->can(self::DETAIL[$key]) && ($key !== 'branches' || $this->multiBranch());
    }

    public function title(string $key): string
    {
        return collect(self::all())->firstWhere('key', $key)['title'] ?? 'التقارير';
    }
}
