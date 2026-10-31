<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\PermissionRegistry;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Reports\Services\ReportCatalog;
use App\Modules\Reports\Support\ReportDocument;
use App\Modules\Reports\Support\ReportFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The reports page, one report's page and one report's file (ReportCatalog lists them and who may open each). */
class ReportController extends Controller
{
    public function __construct(private readonly ReportCatalog $catalog) {}

    /** Every report this user may open, in categories, with a search; each card opens or exports its report. */
    public function index(Request $request): Response|RedirectResponse
    {
        // The period reports were tabs of this page (?section=); an old link opens the report itself.
        if (in_array($section = $request->query('section'), ['finance', 'members', 'classes'], true)) {
            return redirect()->route('club.reports.show', ['report' => $section] + $request->except('section'));
        }

        return Inertia::render('Reports/Index', [
            'reports' => $this->catalog->for($request->user()),
            'categories' => ReportCatalog::CATEGORIES,
        ]);
    }

    /** One report: its filters (period, comparison, branch and its own), figures, charts and tables. */
    public function show(Request $request, string $report): Response
    {
        $entry = $this->authorizeReport($request, $report);
        $user = $request->user();
        $filters = ReportFilters::fromRequest($request, $user, $entry['preset']);
        $branches = app(BranchContext::class);
        $multi = $this->catalog->multiBranch();

        return Inertia::render('Reports/Show', [
            'kind' => $report,
            'title' => $entry['title'],
            'description' => $entry['desc'],
            'mode' => $entry['mode'],
            'compare' => $entry['compare'],
            'filters' => $filters->toArray(),
            // The branch filter: a club-wide user of a club with branches chooses one; a user tied to a branch sees theirs.
            'branchFilter' => $entry['branch'] && $multi ? [
                'choices' => $branches->canSwitch($user) ? $branches->choices(true, $user)->map->only('id', 'name')->values() : [],
                'name' => $filters->branchName(),
            ] : null,
            'canFinancial' => $user->can('reports.financial'),
            'canExport' => $this->catalog->canExport($user, $report),
            'data' => $this->catalog->report($report)->page($filters, $user),
        ]);
    }

    /** One report as Excel or CSV, for the same filters as its page, for those who may export it. */
    public function export(Request $request, string $report, AuditLogger $audit, TenantContext $tenants): StreamedResponse
    {
        $entry = $this->authorizeReport($request, $report);
        abort_unless($this->catalog->canExport($request->user(), $report), 403);
        $format = $request->string('format')->toString() === 'xlsx' ? 'xlsx' : 'csv';
        $filters = ReportFilters::fromRequest($request, $request->user(), $entry['preset']);
        $result = $this->catalog->report($report)->export($filters, $request->user());

        $period = $entry['mode'] === 'snapshot'
            ? [['الوضع في', today()->toDateString()]]
            : [['الفترة', 'من '.$filters->from->toDateString().' إلى '.$filters->to->toDateString()], ...($entry['compare'] ? [['فترة المقارنة', 'من '.$filters->prevFrom->toDateString().' إلى '.$filters->prevTo->toDateString()]] : [])];
        $meta = [
            ...$period,
            ...($entry['branch'] ? [['الفرع', $filters->branchName()]] : []),
            ...($result['filters'] ?? []),
            ['تاريخ الإصدار', now()->format('Y-m-d H:i')],
            ...($result['empty'] ? [['ملاحظة', 'لا توجد بيانات لهذه الفترة والفلاتر؛ الملف يحتوي على رؤوس الأعمدة فقط.']] : []),
        ];
        $slug = $entry['mode'] === 'snapshot' ? "{$report}-".today()->format('Y-m-d') : "{$report}-{$filters->from->toDateString()}_{$filters->to->toDateString()}";

        $audit->log('report.exported', null, ['report' => $report, 'format' => $format, 'from' => $filters->from->toDateString(), 'to' => $filters->to->toDateString(), 'branch' => $filters->branch]);

        return (new ReportDocument($slug, $entry['title'], $meta, $result['sheets'], $result['empty'], $tenants->require()->currency ?? 'OMR'))->download($format);
    }

    /** The report's entry, after its checks: unknown or switched off is 404, not allowed is 403. */
    private function authorizeReport(Request $request, string $report): array
    {
        $entry = $this->catalog->find($report);
        abort_if($entry === null, 404);
        abort_if(isset($entry['module']) && ! PermissionRegistry::moduleEnabled($entry['module']), 404);
        abort_if($report === 'branches' && ! $this->catalog->multiBranch(), 404);
        abort_unless($this->catalog->allows($request->user(), $report), 403);

        return $entry;
    }
}
