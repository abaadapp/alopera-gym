<?php

namespace App\Modules\Reports\Services;

use App\Core\Auth\PermissionRegistry;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Reports\Reports;
use App\Modules\Reports\Reports\Report;

/**
 * The reports page: every report of the club, in gym categories, each with its own page (ReportController::show)
 * and its own file (ReportController::export). The sections keep their working screens (invoices, the point of sale,
 * payroll runs, the check-in log, documents); their reports live here only.
 *
 * Opening a report takes only its own permissions (PERMISSIONS): the report permission or the permission of the
 * section its figures come from, so a user is given one report without the others. The reports page opens for whoever
 * may open at least one report. Exporting a report takes its permissions and reports.export as well. A report of a
 * switched-off module, the branch comparison of a club with one branch, and the branch comparison for a user tied to a
 * branch are not offered.
 *
 * `mode`: a «period» report covers dates and is compared with an earlier period; a «snapshot» report is the state
 * today (what is owed, what expires soon). `preset`: the period a report opens with.
 */
final class ReportCatalog
{
    public const CATEGORIES = [
        'finance' => 'التقارير المالية',
        'sales' => 'تقارير المبيعات والمنتجات',
        'members' => 'تقارير الأعضاء والعضويات',
        'classes' => 'تقارير الحصص والحجوزات',
        'training' => 'تقارير التدريب والمدربين',
        'attendance' => 'تقارير الحضور والدخول',
        'payroll' => 'تقارير الموظفين والرواتب',
        'documents' => 'تقارير المستندات والتجديدات',
        'branches' => 'تقارير أداء الفروع',
    ];

    /** What opening each report takes (all of them): nothing broader than its own figures. */
    public const PERMISSIONS = [
        'finance' => ['reports.financial'],
        'statements' => ['accounting.view'],
        'movement' => ['accounting.view'],
        'dues' => ['dues.view'],
        'sales' => ['sales.view'],
        'members' => ['reports.view'],
        'expiring' => ['memberships.view'],
        'classes' => ['reports.view'],
        'trainers' => ['reports.view'],
        'checkins' => ['checkin.view'],
        'staff-attendance' => ['staff_attendance.view'],
        'payroll' => ['payroll.reports'],
        'payouts' => ['payroll.reports'],
        'documents' => ['documents.view'],
        'renewals' => ['documents.view'],
        'branches' => ['reports.view'],
    ];

    /** @return list<array{key: string, category: string, title: string, desc: string, icon: string, class: class-string<Report>, mode: string, preset: string, compare: bool, branch: bool, module?: string}> */
    private static function all(): array
    {
        $period = ['mode' => 'period', 'preset' => 'month', 'compare' => true, 'branch' => true];
        $snapshot = ['mode' => 'snapshot', 'preset' => 'month', 'compare' => false, 'branch' => true];

        return [
            ['key' => 'finance', 'category' => 'finance', 'title' => 'الإيرادات والمصروفات', 'icon' => 'wallet', 'class' => Reports\FinanceReport::class,
                'desc' => 'صافي التحصيل والمصروفات والربح التشغيلي ونسبة التحصيل، يومًا بيوم وحسب طريقة الدفع ونوع الإيراد، مقارنة بفترة سابقة.'] + $period,
            ['key' => 'statements', 'category' => 'finance', 'title' => 'قائمة الدخل وميزان المراجعة', 'icon' => 'landmark', 'class' => Reports\StatementsReport::class,
                'desc' => 'الإيرادات والمصروفات من دفتر القيود لفترة تختارها بجانب فترة المقارنة، وأرصدة الحسابات حتى تاريخها.'] + $period,
            ['key' => 'movement', 'category' => 'finance', 'title' => 'الحركة المالية', 'icon' => 'arrow-left-right', 'class' => Reports\MovementReport::class,
                'desc' => 'كل مبلغ دخل إلى الصندوق والبنوك وحسابات التسوية أو خرج منها، بالحساب والاتجاه والفترة، مع رصيد الحساب.'] + $period,
            ['key' => 'dues', 'category' => 'finance', 'title' => 'المستحقات والمتأخرات', 'icon' => 'hand-coins', 'class' => Reports\DuesReport::class,
                'desc' => 'المتبقي على الفواتير المفتوحة اليوم موزعًا على مدة التأخير، وأعلى المدينين، وحسب نوع الفاتورة.'] + $snapshot,

            ['key' => 'sales', 'category' => 'sales', 'title' => 'تقرير المبيعات', 'icon' => 'shopping-bag', 'class' => Reports\SalesReport::class, 'module' => 'sales',
                'desc' => 'إجمالي المبيعات وعدد العمليات والملغى والمسترد، يوميًا وحسب المنتج وطريقة الدفع والمصدر والموظف.'] + $period,

            ['key' => 'members', 'category' => 'members', 'title' => 'العضويات والأعضاء', 'icon' => 'ticket', 'class' => Reports\MembersReport::class,
                'desc' => 'الاشتراكات الجديدة والتجديدات والترقيات والإلغاءات والتجميد، والأعضاء الجدد ومرات الدخول، والباقات المباعة.'] + $period,
            ['key' => 'expiring', 'category' => 'members', 'title' => 'العضويات قريبة الانتهاء', 'icon' => 'hourglass', 'class' => Reports\ExpiringReport::class,
                'desc' => 'العضويات السارية التي تنتهي خلال الأيام القادمة، ومن جُدّدت عضويته مسبقًا ومن لم تُجدَّد.'] + $snapshot,

            ['key' => 'classes', 'category' => 'classes', 'title' => 'الحصص والحضور', 'icon' => 'calendar-days', 'class' => Reports\ClassesReport::class,
                'desc' => 'الحصص والحجوزات ونسبتا الحضور والإشغال، والبرامج الأكثر طلبًا، مقارنة بفترة سابقة.'] + $period,

            ['key' => 'trainers', 'category' => 'training', 'title' => 'أداء المدربين', 'icon' => 'dumbbell', 'class' => Reports\TrainersReport::class,
                'desc' => 'حصص كل مدرب وحجوزاتها وحضورها، وجلسات التدريب الشخصي التي أنجزها في الفترة.'] + $period,

            ['key' => 'checkins', 'category' => 'attendance', 'title' => 'دخول الأعضاء', 'icon' => 'door-open', 'class' => Reports\CheckinsReport::class,
                'desc' => 'مرات الدخول المسموحة والمرفوضة وأسباب الرفض، والأعضاء الذين حضروا، حسب اليوم والساعة وطريقة الدخول.'] + $period,
            ['key' => 'staff-attendance', 'category' => 'attendance', 'title' => 'حضور الموظفين', 'icon' => 'clock', 'class' => Reports\StaffAttendanceReport::class,
                'desc' => 'أيام الحضور وساعات العمل لكل موظف من البصمة والتسجيل اليدوي، ومن لم يسجّل انصرافه.'] + $period,

            ['key' => 'payroll', 'category' => 'payroll', 'title' => 'مسيرات الرواتب', 'icon' => 'briefcase', 'class' => Reports\PayrollReport::class,
                'desc' => 'مسيرات أشهر الفترة: الاستحقاقات والخصومات والصافي والمصروف والمتبقي، وكشف رواتب كل مسيرة.'] + ['preset' => 'year'] + $period,
            ['key' => 'payouts', 'category' => 'payroll', 'title' => 'صرف الرواتب', 'icon' => 'banknote', 'class' => Reports\PayoutsReport::class,
                'desc' => 'دفعات صرف الرواتب في الفترة ومن أي حساب، ومن صُرف له، وما ينتظر الصرف في المسيرات المعتمدة.'] + ['preset' => 'year'] + $period,

            ['key' => 'documents', 'category' => 'documents', 'title' => 'المستندات وصلاحيتها', 'icon' => 'file-clock', 'class' => Reports\DocumentsReport::class,
                'desc' => 'السجلات والتراخيص والعقود السارية: ما يقترب انتهاؤه وما انتهى، حسب النوع، ومن المسؤول عن تجديدها.'] + $snapshot,
            ['key' => 'renewals', 'category' => 'documents', 'title' => 'سجل تجديد المستندات', 'icon' => 'history', 'class' => Reports\RenewalsReport::class,
                'desc' => 'كل تجديد في الفترة: من نفّذه ومتى، والانتهاء والرقم المرجعي قبله وبعده، حسب نوع المستند.'] + $period,

            ['key' => 'branches', 'category' => 'branches', 'title' => 'مقارنة الفروع', 'icon' => 'building-2', 'class' => Reports\BranchesReport::class,
                'desc' => 'الأعضاء الجدد والعضويات النشطة ومرات الدخول والحصص والتحصيل لكل فرع، مقارنة بفترة سابقة.'] + ['branch' => false] + $period,
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /** @return array{key: string, category: string, title: string, desc: string, icon: string, class: class-string<Report>, mode: string, preset: string, compare: bool, branch: bool, module?: string}|null */
    public function find(string $key): ?array
    {
        return collect(self::all())->firstWhere('key', $key);
    }

    /**
     * The cards this user is offered: each opens its page, and exports (with its opening period) when allowed.
     *
     * @return list<array{key: string, category: string, title: string, desc: string, icon: string, href: string, export: string|null, scope: string}>
     */
    public function for(User $user): array
    {
        $exports = $user->can('reports.export');

        return array_values(array_map(
            fn (array $r) => [
                'key' => $r['key'], 'category' => $r['category'], 'title' => $r['title'], 'desc' => $r['desc'], 'icon' => $r['icon'],
                'href' => route('club.reports.show', $r['key']),
                'export' => $exports ? route('club.reports.export', $r['key']) : null,
                'scope' => $r['mode'] === 'snapshot' ? 'الوضع الحالي' : ($r['preset'] === 'year' ? 'هذه السنة' : 'هذا الشهر'),
            ],
            array_filter(self::all(), fn (array $r) => $this->allowsEntry($user, $r)),
        ));
    }

    public function allows(User $user, string $key): bool
    {
        $entry = $this->find($key);

        return $entry !== null && $this->allowsEntry($user, $entry);
    }

    public function canExport(User $user, string $key): bool
    {
        return $this->allows($user, $key) && $user->can('reports.export');
    }

    /** May this user open at least one report? (the reports page and its menu entry) */
    public function any(User $user): bool
    {
        return collect(self::all())->contains(fn (array $r) => $this->allowsEntry($user, $r));
    }

    public function report(string $key): Report
    {
        return app($this->find($key)['class']);
    }

    /** The branch comparison is offered to a club with more than one branch. */
    public function multiBranch(): bool
    {
        return Branch::count() > 1;
    }

    private function allowsEntry(User $user, array $r): bool
    {
        if (isset($r['module']) && ! PermissionRegistry::moduleEnabled($r['module'])) {
            return false;
        }
        if (! collect(self::PERMISSIONS[$r['key']])->every(fn (string $p) => $user->can($p))) {
            return false;
        }

        // The branches side by side: for those who see every branch, in a club that has more than one.
        return $r['key'] !== 'branches' || ($user->branch_id === null && $this->multiBranch());
    }
}
