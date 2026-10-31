<?php

namespace App\Modules\Reports\Reports\Stock;

use App\Models\User;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptLine;
use App\Modules\Reports\Reports\TabularReport;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/** استلام البضائع: goods receipt notes of the period, approved, waiting or rejected, with what arrived short. */
class GoodsReceiptsReport extends TabularReport
{
    protected function build(ReportFilters $f, User $user): array
    {
        $labels = [GoodsReceipt::PENDING => 'بانتظار الاعتماد', GoodsReceipt::APPROVED => 'معتمد', GoodsReceipt::REJECTED => 'مرفوض'];
        $receipts = $this->in(GoodsReceipt::query(), $f)->whereBetween('received_on', [$f->from, $f->to])->with(['supplier:id,name', 'order:id,number', 'branch:id,name', 'receiver:id,name'])->orderBy('received_on')->get();
        $lines = GoodsReceiptLine::whereIn('goods_receipt_id', $receipts->pluck('id'))->with('product:id,name')->get();

        return [
            'figures' => [['سندات الاستلام', $receipts->count(), S::INT], ['المعتمد', round((float) $receipts->where('status', GoodsReceipt::APPROVED)->sum('total'), 3), S::MONEY], ['بانتظار الاعتماد', $receipts->where('status', GoodsReceipt::PENDING)->count(), S::INT], ['بنود وصلت ناقصة', $lines->filter(fn ($l) => (float) $l->quantity < (float) $l->ordered_quantity)->count(), S::INT]],
            'tables' => [
                new S('السندات', [['السند', S::TEXT], ['التاريخ', S::TEXT], ['المورد', S::TEXT], ['أمر الشراء', S::TEXT], ['الفرع', S::TEXT], ['الحالة', S::TEXT], ['القيمة', S::MONEY], ['المستلم', S::TEXT]],
                    $receipts->map(fn ($r) => [$r->number, $r->received_on->toDateString(), $r->supplier?->name, $r->order?->number, $r->branch?->name, $labels[$r->status] ?? $r->status, $r->total, $r->receiver?->name])->all()),
                new S('البنود', [['السند', S::TEXT], ['المنتج', S::TEXT], ['المتبقي على الأمر', S::NUMBER], ['المستلم', S::NUMBER], ['الفرق', S::NUMBER], ['بوحدة المخزون', S::NUMBER], ['الإجمالي', S::MONEY], ['ملاحظة', S::TEXT]],
                    $lines->map(fn ($l) => [$receipts->firstWhere('id', $l->goods_receipt_id)?->number, $l->product?->name, $l->ordered_quantity, $l->quantity, round((float) $l->quantity - (float) $l->ordered_quantity, 3), round((float) $l->quantity * (float) $l->unit_factor, 3), $l->line_total, $l->note])->all()),
            ],
            'empty' => $receipts->isEmpty(),
        ];
    }
}
