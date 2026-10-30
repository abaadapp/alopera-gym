<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Gym\Models\Membership;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/** العضويات والأعضاء: memberships started, renewed, changed, cancelled, frozen and ended, new members and visits. */
final class MembersReport extends Report
{
    private const FIGURES = [
        'new_memberships' => ['اشتراكات جديدة', S::INT], 'renewals' => ['تجديدات', S::INT], 'upgrades' => ['تغيير/ترقية باقة', S::INT],
        'new_members' => ['أعضاء جدد', S::INT], 'cancellations' => ['إلغاءات', S::INT], 'freezes' => ['تجميدات', S::INT],
        'expirations' => ['عضويات انتهت', S::INT], 'checkins' => ['مرات الدخول', S::INT],
    ];

    public function __construct(private readonly ReportService $reports) {}

    public function page(ReportFilters $f, User $user): array
    {
        $report = $this->reports->period($f->from, $f->to, $user->can('reports.financial'), $f->prevFrom, $f->prevTo, $f->branch);

        return ['report' => $report, 'empty' => $this->isEmpty($report)];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $financial = $user->can('reports.financial');
        $r = $this->reports->period($f->from, $f->to, $financial, $f->prevFrom, $f->prevTo, $f->branch);

        return ['empty' => $this->isEmpty($r), 'sheets' => [
            S::summary($r['comparison'], self::FIGURES),
            new S('الباقات المباعة', [['الباقة', S::TEXT], ['المباع', S::INT], ['منها تجديد', S::INT], ...($financial ? [['القيمة', S::MONEY]] : [])],
                array_map(fn ($p) => [$p['plan'], $p['sold'], $p['renewals'], ...($financial ? [$p['revenue']] : [])], $r['plans'])),
            new S('العضويات المسجلة', [['رقم العضوية', S::TEXT], ['العضو', S::TEXT], ['الباقة', S::TEXT], ['النوع', S::TEXT], ['الحالة', S::TEXT], ['من', S::TEXT], ['إلى', S::TEXT], ...($financial ? [['الإجمالي', S::MONEY]] : []), ['تاريخ التفعيل', S::TEXT]],
                (function () use ($f, $financial) {
                    foreach ($this->in(Membership::with('member:id,full_name,member_no')->whereBetween('created_at', [$f->from, $f->to->endOfDay()]), $f)->orderBy('id')->lazy(500) as $m) {
                        yield [$m->member?->member_no, $m->member?->full_name, $m->plan_snapshot['name'] ?? '', Membership::KIND_LABELS[$m->kind] ?? $m->kind, Membership::STATUS_LABELS[$m->status] ?? $m->status,
                            $m->starts_on->toDateString(), $m->ends_on->toDateString(), ...($financial ? [$m->total] : []), $m->activated_at?->format('Y-m-d H:i')];
                    }
                })()),
        ]];
    }

    private function isEmpty(array $report): bool
    {
        return $report['plans'] === [] && collect(array_keys(self::FIGURES))->every(fn ($k) => (float) ($report['comparison'][$k]['current'] ?? 0) == 0.0);
    }
}
