<?php

namespace App\Modules\Reports\Reports\Stock;

use App\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Reports\Reports\TabularReport;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/** حركة المخزون: every stock movement of the period by type, with quantities, value and the document behind each. */
class StockMovementsReport extends TabularReport
{
    protected function build(ReportFilters $f, User $user): array
    {
        $type = $f->choice('type', array_keys(StockMovement::TYPES));
        $base = fn () => $this->in(StockMovement::query(), $f, 'stock_movements.branch_id')->whereBetween('stock_movements.created_at', [$f->from->startOfDay(), $f->to->endOfDay()])
            ->when($type, fn ($q, $t) => $q->where('type', $t));
        $byType = $base()->toBase()->selectRaw('type, count(*) as n, sum(quantity) as qty, sum(quantity * unit_cost) as value')->groupBy('type')->orderBy('type')->get();

        return [
            'figures' => [['الحركات', (int) $byType->sum('n'), S::INT], ['الكمية الداخلة', round((float) $base()->where('quantity', '>', 0)->sum('quantity'), 3), S::NUMBER], ['الكمية الخارجة', round(-(float) $base()->where('quantity', '<', 0)->sum('quantity'), 3), S::NUMBER]],
            'tables' => [
                new S('حسب النوع', [['النوع', S::TEXT], ['الحركات', S::INT], ['صافي الكمية', S::NUMBER], ['صافي القيمة', S::MONEY]], $byType->map(fn ($r) => [StockMovement::TYPES[$r->type] ?? $r->type, (int) $r->n, round((float) $r->qty, 3), $this->money($r->value)])->all()),
                new S('الحركات', [['التاريخ', S::TEXT], ['المستند', S::TEXT], ['المنتج', S::TEXT], ['الفرع', S::TEXT], ['النوع', S::TEXT], ['السبب', S::TEXT], ['الكمية', S::NUMBER], ['التكلفة', S::MONEY], ['الرصيد بعدها', S::NUMBER], ['الموظف', S::TEXT]],
                    (function () use ($base) {
                        foreach ($base()->with(['product:id,name', 'branch:id,name', 'user:id,name'])->orderBy('id')->lazy(500) as $m) {
                            yield [$m->created_at->format('Y-m-d H:i'), $m->document, $m->product?->name, $m->branch?->name, StockMovement::TYPES[$m->type] ?? $m->type, $m->reason ? (StockService::REASONS[$m->reason] ?? $m->reason) : null, $m->quantity, $m->unit_cost, $m->branch_balance ?? $m->balance_after, $m->user?->name];
                        }
                    })()),
            ],
            'empty' => $byType->isEmpty(),
            'filters' => $type ? [['نوع الحركة', StockMovement::TYPES[$type]]] : [],
            'options' => ['types' => StockMovement::TYPES, 'type' => $type],
        ];
    }
}
