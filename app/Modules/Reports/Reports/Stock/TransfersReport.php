<?php

namespace App\Modules\Reports\Reports\Stock;

use App\Models\User;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferLine;
use App\Modules\Reports\Reports\TabularReport;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/** التحويلات بين الفروع: transfers of the period — sent, received, short, settled — by route and status. */
class TransfersReport extends TabularReport
{
    protected function build(ReportFilters $f, User $user): array
    {
        $transfers = StockTransfer::query()->whereBetween('created_at', [$f->from->startOfDay(), $f->to->endOfDay()])
            ->when($f->branch, fn ($q, $b) => $q->where(fn ($w) => $w->where('from_branch_id', $b)->orWhere('to_branch_id', $b)))
            ->with(['fromBranch:id,name', 'toBranch:id,name', 'requester:id,name'])->orderBy('id')->get();
        $lines = StockTransferLine::whereIn('stock_transfer_id', $transfers->pluck('id'))->with('product:id,name')->get();
        $sum = fn (string $col, $id = null) => round((float) $lines->when($id, fn ($c) => $c->where('stock_transfer_id', $id))->sum($col), 3);

        return [
            'figures' => [['عمليات النقل', $transfers->count(), S::INT], ['الكمية المرسلة', $sum('sent_quantity'), S::NUMBER], ['الكمية المستلمة', $sum('received_quantity'), S::NUMBER], ['المفقود أثناء النقل', $sum('lost_quantity'), S::NUMBER]],
            'tables' => [
                new S('عمليات النقل', [['السند', S::TEXT], ['التاريخ', S::TEXT], ['من', S::TEXT], ['إلى', S::TEXT], ['الحالة', S::TEXT], ['المطلوب', S::NUMBER], ['المرسل', S::NUMBER], ['المستلم', S::NUMBER], ['الفاقد', S::NUMBER], ['طلبه', S::TEXT]],
                    $transfers->map(fn ($t) => [$t->number, $t->created_at->format('Y-m-d'), $t->fromBranch?->name, $t->toBranch?->name, StockTransfer::LABELS[$t->status] ?? $t->status, $sum('requested_quantity', $t->id), $sum('sent_quantity', $t->id), $sum('received_quantity', $t->id), $sum('lost_quantity', $t->id), $t->requester?->name])->all()),
                new S('البنود', [['السند', S::TEXT], ['المنتج', S::TEXT], ['المرسل', S::NUMBER], ['المستلم', S::NUMBER], ['فاقد', S::NUMBER], ['عاد', S::NUMBER], ['متأخر', S::NUMBER], ['الناقص', S::NUMBER], ['تكلفة الوحدة', S::MONEY]],
                    $lines->map(fn ($l) => [$transfers->firstWhere('id', $l->stock_transfer_id)?->number, $l->product?->name, $l->sent_quantity, $l->received_quantity, $l->lost_quantity, $l->returned_quantity, $l->late_quantity, $l->outstanding(), $l->unit_cost])->all()),
            ],
            'empty' => $transfers->isEmpty(),
        ];
    }
}
