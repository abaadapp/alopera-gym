<?php

namespace App\Modules\Reports\Reports;

use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Finance\Models\CashShift;
use App\Modules\Finance\Models\Payment;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/** إقفال الصندوق: the shifts closed in the period — opening, cash in and out, expected, counted, the difference — and their totals by method. */
class CashShiftsReport extends TabularReport
{
    protected function build(ReportFilters $f, User $user): array
    {
        $shifts = $this->in(CashShift::query(), $f)->with('account:id,name', 'opener:id,name', 'closer:id,name')->where('status', CashShift::CLOSED)
            ->whereBetween('closed_at', [$f->from->startOfDay(), $f->to->endOfDay()])->orderBy('closed_at')->get();
        $methods = [];
        foreach ($shifts as $s) {
            foreach (($s->totals['methods'] ?? []) as $method => $m) {
                $row = $methods[$method] ?? ['received' => '0', 'refunded' => '0', 'count' => 0];
                $methods[$method] = ['received' => Money::add($row['received'], $m['received']), 'refunded' => Money::add($row['refunded'], $m['refunded']), 'count' => $row['count'] + $m['count']];
            }
        }
        $sum = fn (string $key) => Money::add('0', ...$shifts->pluck($key)->all());
        $short = Money::add('0', ...$shifts->filter(fn ($s) => Money::cmp($s->difference, 0) < 0)->pluck('difference')->all());
        $over = Money::add('0', ...$shifts->filter(fn ($s) => Money::cmp($s->difference, 0) > 0)->pluck('difference')->all());

        return [
            'figures' => [
                ['الورديات المقفلة', $shifts->count(), S::INT],
                ['النقد المتوقع', $sum('expected_cash'), S::MONEY],
                ['النقد المعدود', $sum('counted_cash'), S::MONEY],
                ['صافي الفروقات', $sum('difference'), S::MONEY],
                ['مجموع العجز', $short, S::MONEY],
                ['مجموع الزيادة', $over, S::MONEY],
            ],
            'tables' => [
                new S('الورديات', [['الوردية', S::TEXT], ['الصندوق', S::TEXT], ['فتحها', S::TEXT], ['الفتح', S::TEXT], ['أقفلها', S::TEXT], ['الإقفال', S::TEXT], ['الافتتاحي', S::MONEY], ['مقبوض نقدًا', S::MONEY], ['مردود ومصروف نقدًا', S::MONEY], ['نقل النقد (صافٍ)', S::MONEY], ['المتوقع', S::MONEY], ['المعدود', S::MONEY], ['الفرق', S::MONEY], ['سبب الفرق', S::TEXT]],
                    $shifts->map(fn ($s) => [
                        $s->number, $s->account?->name, $s->opener?->name, $s->opened_at->format('Y-m-d H:i'), $s->closer?->name, $s->closed_at?->format('Y-m-d H:i'),
                        $s->opening_float, $s->totals['cash_received'] ?? '0', Money::add($s->totals['cash_refunded'] ?? '0', $s->totals['cash_expenses'] ?? '0'),
                        Money::sub($s->totals['moved_in'] ?? '0', $s->totals['moved_out'] ?? '0'), $s->expected_cash, $s->counted_cash, $s->difference, $s->close_note,
                    ])->all()),
                new S('حسب طريقة الدفع', [['الطريقة', S::TEXT], ['العمليات', S::INT], ['المقبوض', S::MONEY], ['المردود', S::MONEY], ['الصافي', S::MONEY]],
                    collect($methods)->map(fn ($m, $method) => [Payment::METHOD_LABELS[$method] ?? $method, $m['count'], $m['received'], $m['refunded'], Money::sub($m['received'], $m['refunded'])])->values()->all()),
            ],
            'empty' => $shifts->isEmpty(),
        ];
    }
}
