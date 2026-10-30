<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/** الحصص والحضور: classes held, bookings, attendance and occupancy, and the programs in most demand. */
final class ClassesReport extends Report
{
    private const FIGURES = [
        'sessions' => ['الحصص', S::INT], 'bookings' => ['الحجوزات', S::INT], 'attendance_rate' => ['نسبة الحضور', S::PERCENT], 'occupancy_rate' => ['نسبة الإشغال', S::PERCENT],
    ];

    public function __construct(private readonly ReportService $reports) {}

    public function page(ReportFilters $f, User $user): array
    {
        $report = $this->reports->period($f->from, $f->to, false, $f->prevFrom, $f->prevTo, $f->branch);

        return ['report' => $report, 'empty' => $report['programs'] === [] && (int) $report['comparison']['sessions']['current'] === 0];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $r = $this->reports->period($f->from, $f->to, false, $f->prevFrom, $f->prevTo, $f->branch);

        return ['empty' => $r['programs'] === [] && (int) $r['comparison']['sessions']['current'] === 0, 'sheets' => [
            S::summary($r['comparison'], self::FIGURES),
            new S('البرامج', [['البرنامج', S::TEXT], ['الحصص', S::INT], ['الحجوزات', S::INT], ['متوسط الحجوزات للحصة', S::NUMBER], ['حضر', S::INT], ['غاب', S::INT], ['نسبة الحضور', S::PERCENT]],
                array_map(fn ($p) => [$p['program'], $p['sessions'], $p['bookings'], $p['avg_per_session'], $p['attended'], $p['no_show'], $p['attendance_rate']], $r['programs'])),
            new S('الحصص', [['التاريخ', S::TEXT], ['الوقت', S::TEXT], ['البرنامج', S::TEXT], ['المدرب', S::TEXT], ['السعة', S::INT], ['المحجوز', S::INT], ['الحالة', S::TEXT]],
                (function () use ($f) {
                    foreach ($this->in(ClassSession::with(['program:id,name', 'trainer:id,name'])->whereBetween('starts_at', [$f->from, $f->to->endOfDay()]), $f)->orderBy('starts_at')->lazy(500) as $s) {
                        yield [$s->starts_at->toDateString(), $s->starts_at->format('H:i'), $s->program?->name, $s->trainer?->name, $s->capacity, $s->booked_count, ClassSession::STATUS_LABELS[$s->status] ?? $s->status];
                    }
                })()),
        ]];
    }
}
