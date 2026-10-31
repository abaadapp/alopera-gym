<?php

namespace App\Modules\Reports\Reports\Stock;

use App\Models\User;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Reports\Reports\TabularReport;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;
use Illuminate\Support\Facades\DB;

/**
 * المشتريات: the purchase orders of the period (approved or further) and the supplier bills without an order — a bill
 * on an order is the same purchase and is not counted again — by supplier and product.
 */
class PurchasesReport extends TabularReport
{
    protected function build(ReportFilters $f, User $user): array
    {
        $orders = $this->in(PurchaseOrder::query(), $f)->whereNotIn('status', [PurchaseOrder::DRAFT, PurchaseOrder::CANCELLED])->whereBetween('order_date', [$f->from, $f->to])
            ->with(['supplier:id,name', 'branch:id,name'])->orderBy('order_date')->get();
        $bills = $this->in(SupplierBill::query(), $f)->whereNull('purchase_order_id')->where('status', SupplierBill::APPROVED)->whereBetween('issue_date', [$f->from, $f->to])->with('supplier:id,name')->get();
        $received = DB::table('goods_receipts')->whereIn('purchase_order_id', $orders->pluck('id'))->where('status', 'approved')->groupBy('purchase_order_id')->selectRaw('purchase_order_id, sum(total) as t')->pluck('t', 'purchase_order_id');
        $products = DB::table('purchase_order_items as i')->join('products as p', 'p.id', '=', 'i.product_id')->whereIn('i.purchase_order_id', $orders->pluck('id'))
            ->groupBy('p.name')->selectRaw('p.name, sum(i.quantity * i.unit_factor) as qty, sum(i.line_total) as total')->orderByDesc('total')->get();
        $total = round((float) $orders->sum('total') + (float) $bills->sum('total'), 3);
        $bySupplier = $orders->map(fn ($o) => [$o->supplier?->name, (float) $o->total])->concat($bills->map(fn ($b) => [$b->supplier?->name, (float) $b->total]))->groupBy(0)->map(fn ($g, $name) => [$name, $g->count(), round($g->sum(1), 3)])->sortByDesc(2)->values();

        return [
            'figures' => [['إجمالي المشتريات', $total, S::MONEY], ['أوامر الشراء', $orders->count(), S::INT], ['سندات بلا أمر', $bills->count(), S::INT], ['قيمة ما استُلم', round((float) $received->sum(), 3), S::MONEY]],
            'tables' => [
                new S('حسب المورد', [['المورد', S::TEXT], ['المستندات', S::INT], ['الإجمالي', S::MONEY]], $bySupplier->all()),
                new S('حسب المنتج', [['المنتج', S::TEXT], ['الكمية بوحدة المخزون', S::NUMBER], ['الإجمالي قبل الخصم والضريبة', S::MONEY]], $products->map(fn ($p) => [$p->name, round((float) $p->qty, 3), $this->money($p->total)])->all()),
                new S('المستندات', [['التاريخ', S::TEXT], ['المرجع', S::TEXT], ['المصدر', S::TEXT], ['المورد', S::TEXT], ['الفرع', S::TEXT], ['الحالة', S::TEXT], ['الإجمالي', S::MONEY], ['المستلم', S::MONEY]],
                    $orders->map(fn ($o) => [$o->order_date->toDateString(), $o->number, 'أمر شراء', $o->supplier?->name, $o->branch?->name, PurchaseOrder::LABELS[$o->status] ?? $o->status, $o->total, $this->money($received[$o->id] ?? 0)])
                        ->concat($bills->map(fn ($b) => [$b->issue_date->toDateString(), $b->reference, 'سند مورد', $b->supplier?->name, null, 'معتمد', $b->total, null]))->sortBy(0)->values()->all()),
            ],
            'empty' => $orders->isEmpty() && $bills->isEmpty(),
        ];
    }
}
