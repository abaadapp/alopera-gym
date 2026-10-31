<?php

namespace App\Modules\Reports\Reports\Stock;

use App\Models\User;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Reports\Reports\TabularReport;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/** المستحقات للموردين: what is owed on approved bills today, by how late it is, and the bills behind it. */
class SupplierDuesReport extends TabularReport
{
    protected function build(ReportFilters $f, User $user): array
    {
        $today = now()->startOfDay();
        $bills = $this->in(SupplierBill::query(), $f)->where('status', SupplierBill::APPROVED)->whereColumn('paid_total', '<', 'total')->with('supplier:id,name')->orderBy('due_date')->get();
        $late = fn ($b) => $b->due_date && $b->due_date->lt($today) ? (int) $b->due_date->diffInDays($today) : 0;
        $bucket = fn ($b) => match (true) {
            $late($b) === 0 => 'غير مستحق بعد أو اليوم', $late($b) <= 30 => '1–30 يومًا', $late($b) <= 60 => '31–60 يومًا', $late($b) <= 90 => '61–90 يومًا', default => 'أكثر من 90 يومًا'
        };
        $buckets = collect(['غير مستحق بعد أو اليوم', '1–30 يومًا', '31–60 يومًا', '61–90 يومًا', 'أكثر من 90 يومًا'])
            ->map(fn ($name) => [$name, $bills->filter(fn ($b) => $bucket($b) === $name)->count(), round((float) $bills->filter(fn ($b) => $bucket($b) === $name)->sum(fn ($b) => (float) $b->balance()), 3)]);
        $total = round((float) $bills->sum(fn ($b) => (float) $b->balance()), 3);
        $overdue = round((float) $bills->filter(fn ($b) => $late($b) > 0)->sum(fn ($b) => (float) $b->balance()), 3);

        return [
            'figures' => [['المستحق للموردين', $total, S::MONEY], ['منه متأخر', $overdue, S::MONEY], ['السندات المفتوحة', $bills->count(), S::INT]],
            'tables' => [
                new S('حسب مدة التأخير', [['المدة', S::TEXT], ['السندات', S::INT], ['المبلغ', S::MONEY]], $buckets->all()),
                new S('السندات المفتوحة', [['فاتورة المورد', S::TEXT], ['المورد', S::TEXT], ['الإصدار', S::TEXT], ['الاستحقاق', S::TEXT], ['أيام التأخير', S::INT], ['الإجمالي', S::MONEY], ['المدفوع', S::MONEY], ['المتبقي', S::MONEY]],
                    $bills->map(fn ($b) => [$b->reference, $b->supplier?->name, $b->issue_date->toDateString(), $b->due_date?->toDateString(), $late($b), $b->total, $b->paid_total, $b->balance()])->all()),
            ],
            'empty' => $bills->isEmpty(),
        ];
    }
}
