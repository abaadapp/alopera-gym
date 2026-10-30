<?php

namespace App\Modules\Reports\Reports;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Gym\Models\StaffAttendance;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * حضور الموظفين: days present and hours worked for each member of staff, from fingerprint devices and punches in
 * the panel, and who left without punching out. Punching stays in its section.
 */
final class StaffAttendanceReport extends Report
{
    public const METHODS = ['manual' => 'يدوي من اللوحة', 'fingerprint' => 'بصمة', 'card' => 'بطاقة', 'pin' => 'رمز PIN'];

    private const FIGURES = [
        'staff' => ['موظفون حضروا', S::INT], 'days' => ['أيام حضور', S::INT], 'hours' => ['ساعات العمل', S::NUMBER], 'open' => ['بلا انصراف', S::INT],
    ];

    public function page(ReportFilters $f, User $user): array
    {
        $summary = $this->summary($this->base($f)->with('user:id,name')->get());
        $comparison = $this->compare($this->totals($summary), $this->totals($this->summary($this->base($f, $f->prevFrom, $f->prevTo)->get())));

        return [
            'comparison' => $comparison,
            'summary' => $summary,
            'records' => $this->base($f)->with(['user:id,name', 'device:id,name'])->latest('clock_in_at')->paginate(50)->withQueryString()
                ->through(fn (StaffAttendance $a) => [
                    'id' => $a->id, 'user' => $a->user?->name, 'method' => self::METHODS[$a->method] ?? $a->method, 'device' => $a->device?->name,
                    'date' => $a->clock_in_at->toDateString(), 'in' => $a->clock_in_at->format('H:i'), 'out' => $a->clock_out_at?->format('H:i'), 'hours' => $this->hours($a),
                ]),
            'staff' => User::where('tenant_id', app(TenantContext::class)->require()->id)->orderBy('name')->get(['id', 'name']),
            'options' => ['user_id' => $f->integer('user_id')],
            'empty' => $summary->isEmpty(),
        ];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $summary = $this->summary($this->base($f)->with('user:id,name')->get());
        $comparison = $this->compare($this->totals($summary), $this->totals($this->summary($this->base($f, $f->prevFrom, $f->prevTo)->get())));
        $person = $f->integer('user_id') ? User::where('tenant_id', app(TenantContext::class)->require()->id)->whereKey($f->integer('user_id'))->value('name') : null;

        return [
            'empty' => $summary->isEmpty(),
            'filters' => [['الموظف', $person ?? 'الكل']],
            'sheets' => [
                S::summary($comparison, self::FIGURES),
                new S('ملخص الموظفين', [['الموظف', S::TEXT], ['أيام الحضور', S::INT], ['ساعات العمل', S::NUMBER], ['السجلات', S::INT], ['بلا انصراف', S::INT]],
                    $summary->map(fn ($s) => [$s['user'], $s['days'], $s['hours'], $s['records'], $s['open']])->all()),
                new S('السجلات', [['الموظف', S::TEXT], ['التاريخ', S::TEXT], ['الحضور', S::TEXT], ['الانصراف', S::TEXT], ['الساعات', S::NUMBER], ['الطريقة', S::TEXT], ['الجهاز', S::TEXT]],
                    (function () use ($f) {
                        foreach ($this->base($f)->with(['user:id,name', 'device:id,name'])->orderBy('clock_in_at')->orderBy('id')->lazy(500) as $a) {
                            yield [$a->user?->name, $a->clock_in_at->toDateString(), $a->clock_in_at->format('H:i'), $a->clock_out_at?->format('H:i') ?? 'لم ينصرف', $this->hours($a), self::METHODS[$a->method] ?? $a->method, $a->device?->name];
                        }
                    })()),
            ],
        ];
    }

    private function base(ReportFilters $f, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Builder
    {
        return $this->in(StaffAttendance::query(), $f)
            ->whereBetween('clock_in_at', [($from ?? $f->from)->startOfDay(), ($to ?? $f->to)->endOfDay()])
            ->when($f->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id));
    }

    private function hours(StaffAttendance $a): ?float
    {
        return $a->clock_out_at ? round($a->clock_in_at->diffInMinutes($a->clock_out_at) / 60, 2) : null;
    }

    /** Per person over the whole period: days present, hours worked, punches left open. */
    private function summary(Collection $records): Collection
    {
        return $records->groupBy('user_id')->map(fn ($rows) => [
            'user' => $rows->first()->user?->name ?? '—',
            'days' => $rows->map(fn ($a) => $a->clock_in_at->toDateString())->unique()->count(),
            'hours' => round($rows->sum(fn ($a) => $a->clock_out_at ? $a->clock_in_at->diffInMinutes($a->clock_out_at) : 0) / 60, 2),
            'open' => $rows->whereNull('clock_out_at')->count(),
            'records' => $rows->count(),
        ])->sortByDesc('hours')->values();
    }

    private function totals(Collection $summary): array
    {
        return ['staff' => $summary->count(), 'days' => $summary->sum('days'), 'hours' => round($summary->sum('hours'), 2), 'open' => $summary->sum('open')];
    }
}
