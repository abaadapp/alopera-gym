<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/** أداء المدربين: each trainer's classes, bookings and attendance, and the personal-training sessions completed. */
final class TrainersReport extends Report
{
    private const FIGURES = [
        'trainers' => ['مدربون لهم نشاط', S::INT], 'sessions' => ['الحصص', S::INT], 'bookings' => ['الحجوزات', S::INT],
        'attendance_rate' => ['نسبة الحضور', S::PERCENT], 'pt_completed' => ['جلسات تدريب شخصي منجزة', S::INT],
    ];

    public function __construct(private readonly ReportService $reports) {}

    public function page(ReportFilters $f, User $user): array
    {
        $trainers = $this->reports->trainers($f->from, $f->to, $f->prevFrom, $f->prevTo, $f->branch);

        return ['trainers' => $trainers, 'empty' => $trainers['rows'] === []];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $t = $this->reports->trainers($f->from, $f->to, $f->prevFrom, $f->prevTo, $f->branch);

        return ['empty' => $t['rows'] === [], 'sheets' => [
            S::summary($t['comparison'], self::FIGURES),
            new S('المدربون', [['المدرب', S::TEXT], ['الحصص', S::INT], ['الحجوزات', S::INT], ['حضر', S::INT], ['غاب', S::INT], ['نسبة الحضور', S::PERCENT], ['جلسات التدريب الشخصي المنجزة', S::INT]],
                array_map(fn ($r) => [$r['trainer'], $r['sessions'], $r['bookings'], $r['attended'], $r['no_show'], $r['attendance_rate'], $r['pt_completed']], $t['rows'])),
        ]];
    }
}
