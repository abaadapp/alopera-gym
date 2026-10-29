<?php

namespace App\Http\Controllers\Club;

use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Documents\Models\BusinessDocument;
use App\Modules\Documents\Models\DocumentAlert;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Gym\Models\Member;
use App\Modules\Reports\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** The dashboard tiles, in their default order. A user may hide and reorder them (users.preferences). */
    public const TILES = ['active_members', 'expiring_7_days', 'expired_members', 'frozen_members', 'revenue_month', 'outstanding', 'checkins_today', 'new_members_month'];

    public function index(Request $request, ReportService $reports, BranchContext $branches): Response
    {
        $branch = $branches->id();
        $data = $reports->dashboard($branch);
        $data['trends'] = $this->trends($reports, $branch, $request->user()->can('reports.financial'));
        $data['tiles'] = self::tilePreferences($request->user());

        // Money figures only for those allowed to see them.
        if (! $request->user()->can('reports.financial')) {
            foreach (['revenue_month', 'revenue_today', 'outstanding'] as $key) {
                $data['kpis'][$key] = null;
            }
            $data['revenue_trend'] = [];
        }

        $data['documents'] = $request->user()->can('documents.view') ? $this->documentAlerts($request) : null;

        return Inertia::render('Club/Dashboard', $data);
    }

    /** Save the user's tiles: their order and the hidden ones (unknown keys are dropped). */
    public function saveTiles(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order' => ['present', 'array', 'max:20'],
            'order.*' => ['string', 'in:'.implode(',', self::TILES)],
            'hidden' => ['present', 'array', 'max:20'],
            'hidden.*' => ['string', 'in:'.implode(',', self::TILES)],
        ]);
        $user = $request->user();
        $user->forceFill(['preferences' => array_merge($user->preferences ?? [], [
            'dashboard_tiles' => ['order' => array_values(array_unique($data['order'])), 'hidden' => array_values(array_unique($data['hidden']))],
        ])])->save();

        return back()->with('success', 'تم حفظ ترتيب البطاقات.');
    }

    /** @return array{order: list<string>, hidden: list<string>} */
    public static function tilePreferences(User $user): array
    {
        $saved = $user->preferences['dashboard_tiles'] ?? [];
        $order = array_values(array_intersect((array) ($saved['order'] ?? []), self::TILES));

        return [
            'order' => [...$order, ...array_values(array_diff(self::TILES, $order))], // new tiles join at the end
            'hidden' => array_values(array_intersect((array) ($saved['hidden'] ?? []), self::TILES)),
        ];
    }

    /**
     * Change against the period before, where it means something: money collected and new members this month so far
     * against the same days of last month, entries today so far against the same hours a week ago. No figure when the
     * earlier period has nothing to compare with.
     *
     * @return array<string, array{change: float, label: string}>
     */
    private function trends(ReportService $reports, ?int $branch, bool $financial): array
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $monthStart = $today->startOfMonth();
        $prevStart = $monthStart->subMonthNoOverflow();
        $prevEnd = $prevStart->addDays((int) $monthStart->diffInDays($today))->endOfDay();
        $b = fn ($query) => $branch ? $query->where(fn ($w) => $w->where('branch_id', $branch)->orWhereNull('branch_id')) : $query;
        $out = [];
        $put = function (string $key, float $current, float $previous, string $label) use (&$out) {
            if ($previous > 0) {
                $out[$key] = ['change' => round(($current - $previous) / $previous * 100, 1), 'label' => $label];
            }
        };

        if ($financial) {
            $put('revenue_month', (float) $reports->collected($monthStart, $today->endOfDay(), $branch), (float) $reports->collected($prevStart, $prevEnd, $branch), 'عن نفس الفترة من الشهر الماضي');
        }
        $put('new_members_month', $b(Member::whereBetween('joined_on', [$monthStart, $today]))->count(), $b(Member::whereBetween('joined_on', [$prevStart, $prevEnd->startOfDay()]))->count(), 'عن نفس الفترة من الشهر الماضي');
        $put('checkins_today', $b(CheckIn::where('result', 'allowed')->whereBetween('checked_in_at', [$today, $now]))->count(),
            $b(CheckIn::where('result', 'allowed')->whereBetween('checked_in_at', [$today->subWeek(), $now->subWeek()]))->count(), 'عن نفس الوقت قبل أسبوع');

        return $out;
    }

    /** Document alerts not yet looked at, for the documents this user may see. */
    private function documentAlerts(Request $request): array
    {
        $today = CarbonImmutable::today();
        $soon = DocumentService::soonDays(app(TenantContext::class)->require());
        $active = fn () => BranchContext::scope(BusinessDocument::visibleTo($request->user()))->where('status', 'active');

        return [
            'expiring' => $active()->whereBetween('expiry_date', [$today, $today->addDays($soon)])->count(),
            'expired' => $active()->where('expiry_date', '<', $today)->count(),
            'alerts' => DocumentAlert::with('document:id,name,expiry_date')->whereNull('acknowledged_at')
                ->whereIn('document_id', $active()->select('id'))->latest('id')->limit(5)->get()
                ->map(fn (DocumentAlert $a) => ['id' => $a->id, 'name' => $a->document?->name, 'expiry_date' => $a->expiry_date->toDateString(), 'days_left' => $a->document?->daysLeft($today)]),
        ];
    }
}
