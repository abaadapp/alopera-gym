<?php

namespace App\Modules\Reports\Reports\Stock;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Reports\Reports\TabularReport;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;
use Illuminate\Support\Facades\DB;

/**
 * المخزون والأرصدة (and, with only=low, المنتجات منخفضة المخزون): each stocked product's quantity in the chosen
 * branch or the whole club, its reorder level, unit cost, value and state, today; with every branch's quantity when
 * all are shown.
 */
class StockBalanceReport extends TabularReport
{
    public function __construct(protected readonly bool $lowOnly = false) {}

    protected function build(ReportFilters $f, User $user): array
    {
        $branch = $f->branch;
        $qty = $branch ? 'coalesce(ps.quantity, 0)' : 'products.stock_quantity';
        $min = $branch ? 'coalesce(ps.min_quantity, products.reorder_level)' : 'products.reorder_level';
        $rows = Product::query()->where('products.track_stock', true)->leftJoin('product_categories as c', 'c.id', '=', 'products.product_category_id')
            ->when($branch, fn ($q) => $q->leftJoin('product_stocks as ps', fn ($j) => $j->on('ps.product_id', '=', 'products.id')->where('ps.branch_id', $branch)))
            ->when($this->lowOnly, fn ($q) => $q->whereRaw("{$qty} <= {$min}"))
            ->orderBy('products.name')->get(['products.id', 'products.name', 'products.sku', 'c.name as category', 'products.cost', DB::raw("{$qty} as qty"), DB::raw("{$min} as min")]);
        $split = DB::table('product_stocks as s')->join('branches as b', 'b.id', '=', 's.branch_id')->where('s.tenant_id', $user->tenant_id)
            ->get(['s.product_id', 'b.name', 's.quantity'])->groupBy('product_id');

        $value = $rows->sum(fn ($r) => max(0, (float) $r->qty) * (float) $r->cost);
        $out = $rows->filter(fn ($r) => (float) $r->qty <= 0)->count();
        $low = $rows->filter(fn ($r) => (float) $r->qty > 0 && (float) $r->qty <= (float) $r->min)->count();
        $state = fn ($r) => (float) $r->qty <= 0 ? 'نفد' : ((float) $r->qty <= (float) $r->min ? 'منخفض' : 'متوفر');

        return [
            'figures' => [['الأصناف', $rows->count(), S::INT], ['قيمة المخزون', round($value, 3), S::MONEY], ['منخفضة', $low, S::INT], ['نافدة', $out, S::INT]],
            'tables' => [new S($this->lowOnly ? 'منخفضة المخزون' : 'الأرصدة', [
                ['المنتج', S::TEXT], ['SKU', S::TEXT], ['التصنيف', S::TEXT], ['الكمية', S::NUMBER], ['الحد الأدنى', S::NUMBER],
                ...($this->lowOnly ? [['الكمية المقترحة للطلب', S::NUMBER]] : []), ['تكلفة الوحدة', S::MONEY], ['القيمة', S::MONEY], ['الحالة', S::TEXT], ...($branch ? [] : [['حسب الفرع', S::TEXT]]),
            ], $rows->map(fn ($r) => [
                $r->name, $r->sku, $r->category, round((float) $r->qty, 3), round((float) $r->min, 3),
                ...($this->lowOnly ? [max(0, round(2 * (float) $r->min - (float) $r->qty, 3))] : []),
                $r->cost, round(max(0, (float) $r->qty) * (float) $r->cost, 3), $state($r),
                ...($branch ? [] : [collect($split[$r->id] ?? [])->map(fn ($s) => $s->name.': '.round((float) $s->quantity, 3))->join(' · ')]),
            ])->all())],
            'empty' => $rows->isEmpty(),
        ];
    }
}
