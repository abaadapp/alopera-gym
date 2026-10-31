<?php

namespace App\Modules\Reports\Reports\Stock;

use App\Models\User;
use App\Modules\Inventory\Models\Stocktake;
use App\Modules\Inventory\Models\StocktakeLine;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Reports\Reports\TabularReport;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/** نتائج الجرد: the counts approved in the period, their differences (shortage and surplus) and their value. */
class StocktakesReport extends TabularReport
{
    protected function build(ReportFilters $f, User $user): array
    {
        $counts = $this->in(Stocktake::query(), $f)->where('status', Stocktake::APPROVED)->whereBetween('approved_at', [$f->from->startOfDay(), $f->to->endOfDay()])
            ->with(['branch:id,name', 'approver:id,name'])->orderBy('approved_at')->get();
        $lines = StocktakeLine::whereIn('stocktake_id', $counts->pluck('id'))->whereNotNull('counted_quantity')->with('product:id,name')->get();
        $value = fn ($l) => round((float) $l->difference * (float) $l->unit_cost, 3);

        return [
            'figures' => [
                ['عمليات الجرد', $counts->count(), S::INT], ['أصناف بفرق', $lines->where('difference', '!=', 0)->count(), S::INT],
                ['قيمة النقص', round(-$lines->filter(fn ($l) => (float) $l->difference < 0)->sum($value), 3), S::MONEY], ['قيمة الزيادة', round($lines->filter(fn ($l) => (float) $l->difference > 0)->sum($value), 3), S::MONEY],
            ],
            'tables' => [
                new S('عمليات الجرد', [['الجرد', S::TEXT], ['الفرع', S::TEXT], ['اعتُمد', S::TEXT], ['المعتمد', S::TEXT], ['المعدود', S::INT], ['بفرق', S::INT], ['صافي القيمة', S::MONEY]], $counts->map(fn ($c) => [
                    $c->number, $c->branch?->name, $c->approved_at?->format('Y-m-d H:i'), $c->approver?->name,
                    $lines->where('stocktake_id', $c->id)->count(), $lines->where('stocktake_id', $c->id)->where('difference', '!=', 0)->count(), round($lines->where('stocktake_id', $c->id)->sum($value), 3),
                ])->all()),
                new S('الفروقات', [['الجرد', S::TEXT], ['المنتج', S::TEXT], ['رصيد النظام', S::NUMBER], ['المعدود', S::NUMBER], ['الفرق', S::NUMBER], ['تكلفة الوحدة', S::MONEY], ['القيمة', S::MONEY], ['السبب', S::TEXT], ['ملاحظة', S::TEXT]],
                    $lines->where('difference', '!=', 0)->map(fn ($l) => [$counts->firstWhere('id', $l->stocktake_id)?->number, $l->product?->name, $l->system_quantity, $l->counted_quantity, $l->difference, $l->unit_cost, $value($l), StockService::REASONS[$l->reason ?? 'count'] ?? $l->reason, $l->note])->values()->all()),
            ],
            'empty' => $counts->isEmpty(),
        ];
    }
}
