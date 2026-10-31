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
        'inventory' => 'تقارير المخزون',
        'purchasing' => 'تقارير المشتريات والموردين',
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
        'stock-balance' => ['inventory.view'],
        'low-stock' => ['inventory.view'],
        'stock-movements' => ['inventory.view'],
        'stocktakes' => ['inventory.view'],
        'transfers' => ['inventory.view'],
        'purchases' => ['purchases.view'],
        'suppliers' => ['purchases.view'],
        'supplier-dues' => ['purchases.view'],
        'goods-receipts' => ['purchases.view'],
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

            ['key' => 'stock-balance', 'category' => 'inventory', 'title' => 'المخزون والأرصدة', 'icon' => 'boxes', 'class' => Reports\Stock\StockBalanceReport::class, 'module' => 'inventory',
                'desc' => 'رصيد كل منتج مخزني في الفرع أو في كل الفروع، وحده الأدنى وتكلفته وقيمته وحالته، اليوم.'] + $snapshot,
            ['key' => 'low-stock', 'category' => 'inventory', 'title' => 'المنتجات منخفضة المخزون', 'icon' => 'package-x', 'class' => Reports\Stock\LowStockReport::class, 'module' => 'inventory',
                'desc' => 'ما وصل إلى حد إعادة الطلب أو نفد، في كل فرع، مع كمية مقترحة للطلب.'] + $snapshot,
            ['key' => 'stock-movements', 'category' => 'inventory', 'title' => 'حركة المخزون', 'icon' => 'history', 'class' => Reports\Stock\StockMovementsReport::class, 'module' => 'inventory',
                'desc' => 'كل حركة في الفترة: الاستلام والبيع والطلبات والإلغاء والجرد والتالف والنقل، بمستندها والموظف والرصيد بعدها.'] + ['compare' => false] + $period,
            ['key' => 'stocktakes', 'category' => 'inventory', 'title' => 'نتائج الجرد', 'icon' => 'clipboard-check', 'class' => Reports\Stock\StocktakesReport::class, 'module' => 'inventory',
                'desc' => 'عمليات الجرد المعتمدة في الفترة وفروقاتها بالكمية والقيمة وأسبابها.'] + ['compare' => false] + $period,
            ['key' => 'transfers', 'category' => 'inventory', 'title' => 'التحويلات بين الفروع', 'icon' => 'truck', 'class' => Reports\Stock\TransfersReport::class, 'module' => 'inventory',
                'desc' => 'عمليات النقل بين الفروع في الفترة: المرسل والمستلم والناقص والمفقود، حسب الحالة.'] + ['compare' => false] + $period,

            ['key' => 'purchases', 'category' => 'purchasing', 'title' => 'المشتريات', 'icon' => 'shopping-cart', 'class' => Reports\Stock\PurchasesReport::class, 'module' => 'inventory',
                'desc' => 'أوامر الشراء المعتمدة وسندات الموردين بلا أمر في الفترة، حسب المورد والمنتج، وما استُلم منها.'] + ['compare' => false] + $period,
            ['key' => 'suppliers', 'category' => 'purchasing', 'title' => 'الموردون', 'icon' => 'users', 'class' => Reports\Stock\SuppliersReport::class, 'module' => 'inventory',
                'desc' => 'أوامر كل مورد وسنداته المعتمدة ومدفوعاته في الفترة، وما له اليوم.'] + ['compare' => false] + $period,
            ['key' => 'supplier-dues', 'category' => 'purchasing', 'title' => 'المستحقات للموردين', 'icon' => 'hand-coins', 'class' => Reports\Stock\SupplierDuesReport::class, 'module' => 'inventory',
                'desc' => 'المتبقي على سندات الموردين المعتمدة اليوم، موزعًا على مدة التأخير، والسندات المفتوحة.'] + $snapshot,
            ['key' => 'goods-receipts', 'category' => 'purchasing', 'title' => 'استلام البضائع', 'icon' => 'package-check', 'class' => Reports\Stock\GoodsReceiptsReport::class, 'module' => 'inventory',
                'desc' => 'سندات استلام البضاعة في الفترة: المعتمد والمنتظر والمرفوض، وما وصل ناقصًا عن المطلوب.'] + ['compare' => false] + $period,

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
