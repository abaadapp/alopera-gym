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
 * branch or the whole club, its reorder level, unit cost, value and state, today (a snapshot, not a period); with every
 * branch's quantity when all are shown.
 *
 * All branches: the quantity is the club's real total (counted once), and a branch at or below its own minimum (its
 * min_quantity, else the product's reorder level) is never hidden behind another branch's stock: the product is
 * «نافد في فرع» or «منخفض في فرع», named in «الفروع الناقصة», counted in «ناقصة في فرع», and kept in the low-stock
 * list. Only branches that have held the product (a product_stocks row) are looked at.
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
            ->when($this->lowOnly && $branch, fn ($q) => $q->whereRaw("{$qty} <= {$min}"))
            ->orderBy('products.name')->get(['products.id', 'products.name', 'products.sku', 'c.name as category', 'products.cost', DB::raw("{$qty} as qty"), DB::raw("{$min} as min")]);
        $split = DB::table('product_stocks as s')->join('branches as b', 'b.id', '=', 's.branch_id')->join('products as p', 'p.id', '=', 's.product_id')
            ->where('s.tenant_id', $user->tenant_id)->orderByDesc('b.is_main')->orderBy('b.name')
            ->get(['s.product_id', 'b.name', 's.quantity', DB::raw('coalesce(s.min_quantity, p.reorder_level) as min')])->groupBy('product_id');
        // All branches: the branches at or below their own minimum, each with what it holds and needs.
        $short = fn ($r) => $branch ? collect() : collect($split[$r->id] ?? [])->filter(fn ($s) => (float) $s->quantity <= (float) $s->min);
        if ($this->lowOnly && ! $branch) {
            $rows = $rows->filter(fn ($r) => (float) $r->qty <= (float) $r->min || $short($r)->isNotEmpty())->values();
        }

        $value = $rows->sum(fn ($r) => max(0, (float) $r->qty) * (float) $r->cost);
        $out = $rows->filter(fn ($r) => (float) $r->qty <= 0)->count();
        $low = $rows->filter(fn ($r) => (float) $r->qty > 0 && (float) $r->qty <= (float) $r->min)->count();
        $state = fn ($r) => (float) $r->qty <= 0 ? 'نفد' : ((float) $r->qty <= (float) $r->min ? 'منخفض'
            : ($short($r)->isEmpty() ? 'متوفر' : ($short($r)->contains(fn ($s) => (float) $s->quantity <= 0) ? 'نافد في فرع' : 'منخفض في فرع')));
        // What to order: for the club's total, as before; in all branches, at least what each short branch needs.
        $suggested = fn ($r) => max(0, round(2 * (float) $r->min - (float) $r->qty, 3), round($short($r)->sum(fn ($s) => max(0, 2 * (float) $s->min - (float) $s->quantity)), 3));
        $inBranch = $branch ? null : $rows->filter(fn ($r) => (float) $r->qty > (float) $r->min && $short($r)->isNotEmpty())->count();

        return [
            'figures' => [['الأصناف', $rows->count(), S::INT], ['قيمة المخزون', round($value, 3), S::MONEY], ['منخفضة', $low, S::INT], ['نافدة', $out, S::INT],
                ...($branch ? [] : [['ناقصة في فرع', $inBranch, S::INT]])],
            'tables' => [new S($this->lowOnly ? 'منخفضة المخزون' : 'الأرصدة', [
                ['المنتج', S::TEXT], ['SKU', S::TEXT], ['التصنيف', S::TEXT], ['الكمية', S::NUMBER], ['الحد الأدنى', S::NUMBER],
                ...($this->lowOnly ? [['الكمية المقترحة للطلب', S::NUMBER]] : []), ['تكلفة الوحدة', S::MONEY], ['القيمة', S::MONEY], ['الحالة', S::TEXT], ...($branch ? [] : [['حسب الفرع', S::TEXT], ['الفروع الناقصة', S::TEXT]]),
            ], $rows->map(fn ($r) => [
                $r->name, $r->sku, $r->category, round((float) $r->qty, 3), round((float) $r->min, 3),
                ...($this->lowOnly ? [$suggested($r)] : []),
                $r->cost, round(max(0, (float) $r->qty) * (float) $r->cost, 3), $state($r),
                ...($branch ? [] : [
                    collect($split[$r->id] ?? [])->map(fn ($s) => $s->name.': '.round((float) $s->quantity, 3))->join(' · '),
                    $short($r)->map(fn ($s) => $s->name.': '.round((float) $s->quantity, 3).' من '.round((float) $s->min, 3))->join(' · '),
                ]),
            ])->all())],
            'empty' => $rows->isEmpty(),
        ];
    }
}
