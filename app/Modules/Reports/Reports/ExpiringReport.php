<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Gym\Models\Membership;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * العضويات قريبة الانتهاء, as they stand today: active memberships ending within the next days chosen, and whether
 * the member already has the next one waiting. Renewing stays on the member's page.
 */
final class ExpiringReport extends Report
{
    public const HORIZONS = ['7', '14', '30', '60'];

    public function page(ReportFilters $f, User $user): array
    {
        $rows = $this->rows($f);

        return [
            'rows' => $rows->values(),
            'stats' => [
                'total' => $rows->count(),
                'soon' => $rows->where('days_left', '<=', 3)->count(),
                'renewed' => $rows->where('renewed', true)->count(),
            ],
            'byPlan' => $rows->groupBy('plan')->map(fn ($g, $plan) => ['plan' => $plan, 'count' => $g->count()])->sortByDesc('count')->values(),
            'options' => ['days' => $this->days($f)],
            'empty' => $rows->isEmpty(),
        ];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $rows = $this->rows($f);

        return [
            'empty' => $rows->isEmpty(),
            'filters' => [['تنتهي خلال', $this->days($f).' يومًا من اليوم']],
            'sheets' => [
                new S('العضويات قريبة الانتهاء', [['رقم العضوية', S::TEXT], ['العضو', S::TEXT], ['الهاتف', S::TEXT], ['الباقة', S::TEXT], ['تاريخ الانتهاء', S::TEXT], ['الأيام المتبقية', S::INT], ['التجديد', S::TEXT]],
                    $rows->map(fn ($r) => [$r['member_no'], $r['member'], $r['phone'], $r['plan'], $r['ends_on'], $r['days_left'], $r['renewed'] ? 'له عضوية تالية' : 'لم يُجدَّد'])->all()),
            ],
        ];
    }

    private function days(ReportFilters $f): int
    {
        return (int) $f->choice('days', self::HORIZONS, '7');
    }

    private function rows(ReportFilters $f): Collection
    {
        $today = CarbonImmutable::today();
        $memberships = $this->in(Membership::with('member:id,full_name,member_no,phone'), $f)->expiringWithin($this->days($f), $today)->orderBy('ends_on')->orderBy('id')->get();
        // A member whose next membership is already waiting is not at risk.
        $renewed = Membership::whereIn('member_id', $memberships->pluck('member_id'))->where('status', Membership::SCHEDULED)->pluck('member_id')->flip();

        return $memberships->map(fn (Membership $m) => [
            'id' => $m->id, 'member_id' => $m->member_id, 'member_no' => $m->member?->member_no, 'member' => $m->member?->full_name, 'phone' => $m->member?->phone,
            'plan' => $m->plan_snapshot['name'] ?? '', 'ends_on' => $m->ends_on->toDateString(), 'days_left' => $m->daysRemaining($today), 'renewed' => $renewed->has($m->member_id),
        ]);
    }
}
