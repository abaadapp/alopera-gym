<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/**
 * مقارنة الفروع: each branch's period figures beside the others, with records without a branch counted as the main
 * branch's. Money only for those who may see it. Offered to club-wide users of a club with more than one branch.
 */
final class BranchesReport extends Report
{
    public function __construct(private readonly ReportService $reports) {}

    public function page(ReportFilters $f, User $user): array
    {
        $branches = $this->reports->branches($f->from, $f->to, $f->prevFrom, $f->prevTo, $user->can('reports.financial'));

        return ['branches' => $branches, 'empty' => $this->isEmpty($branches)];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $financial = $user->can('reports.financial');
        $branches = $this->reports->branches($f->from, $f->to, $f->prevFrom, $f->prevTo, $financial);
        $metrics = ['new_members' => ['أعضاء جدد', S::INT], 'memberships_sold' => ['عضويات مفعّلة', S::INT], 'checkins' => ['مرات الدخول', S::INT], 'sessions' => ['الحصص', S::INT],
            ...($financial ? ['revenue' => ['صافي التحصيل', S::MONEY], 'expenses' => ['المصروفات', S::MONEY]] : [])];

        $columns = [['الفرع', S::TEXT], ['العضويات النشطة الآن', S::INT]];
        foreach ($metrics as [$label, $kind]) {
            array_push($columns, [$label, $kind], ["{$label} — فترة المقارنة", $kind], ["{$label} — التغير", S::PERCENT]);
        }

        return ['empty' => $this->isEmpty($branches), 'sheets' => [
            new S('الفروع', $columns, array_map(function ($b) use ($metrics) {
                $row = [$b['name'], $b['active_members']];
                foreach (array_keys($metrics) as $key) {
                    $m = $b['comparison'][$key];
                    array_push($row, $m['current'], $m['previous'], $m['change'] ?? '—');
                }

                return $row;
            }, $branches)),
        ]];
    }

    private function isEmpty(array $branches): bool
    {
        return collect($branches)->every(fn ($b) => $b['active_members'] === 0 && collect($b['comparison'])->every(fn ($m) => (float) $m['current'] == 0.0));
    }
}
