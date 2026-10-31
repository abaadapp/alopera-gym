<?php

namespace App\Modules\Reports\Reports\Stock;

use App\Models\User;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Inventory\Models\SupplierPayment;
use App\Modules\Reports\Reports\TabularReport;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/** الموردون: each supplier's orders, approved bills and payments in the period, and what is owed to them today. */
class SuppliersReport extends TabularReport
{
    protected function build(ReportFilters $f, User $user): array
    {
        $suppliers = Supplier::orderBy('name')->get();
        $orders = $this->in(PurchaseOrder::query(), $f)->whereNotIn('status', [PurchaseOrder::DRAFT, PurchaseOrder::CANCELLED])->whereBetween('order_date', [$f->from, $f->to])->get(['supplier_id', 'total']);
        $bills = $this->in(SupplierBill::query(), $f)->where('status', SupplierBill::APPROVED)->whereBetween('issue_date', [$f->from, $f->to])->get(['supplier_id', 'total']);
        $paid = SupplierPayment::whereNull('reversed_at')->whereBetween('paid_on', [$f->from, $f->to])->get(['supplier_id', 'amount']);
        $due = SupplierBill::where('status', SupplierBill::APPROVED)->selectRaw('supplier_id, sum(total - paid_total) as d')->groupBy('supplier_id')->pluck('d', 'supplier_id');
        $rows = $suppliers->map(fn ($s) => [
            $s->name, $s->phone, $s->tax_number, $orders->where('supplier_id', $s->id)->count(), round((float) $orders->where('supplier_id', $s->id)->sum('total'), 3),
            round((float) $bills->where('supplier_id', $s->id)->sum('total'), 3), round((float) $paid->where('supplier_id', $s->id)->sum('amount'), 3), round((float) ($due[$s->id] ?? 0), 3),
        ])->filter(fn ($r) => $r[3] || $r[5] || $r[6] || $r[7])->values();

        return [
            'figures' => [['موردون تعاملنا معهم', $rows->count(), S::INT], ['السندات المعتمدة', round((float) $bills->sum('total'), 3), S::MONEY], ['المدفوع', round((float) $paid->sum('amount'), 3), S::MONEY], ['المستحق اليوم', round((float) $due->sum(), 3), S::MONEY]],
            'tables' => [new S('الموردون', [['المورد', S::TEXT], ['الهاتف', S::TEXT], ['الرقم الضريبي', S::TEXT], ['أوامر الشراء', S::INT], ['قيمة الأوامر', S::MONEY], ['السندات المعتمدة', S::MONEY], ['المدفوع', S::MONEY], ['المستحق اليوم', S::MONEY]], $rows->all())],
            'empty' => $rows->isEmpty(),
        ];
    }
}
