<?php

namespace App\Modules\Reports\Reports;

use App\Core\Auth\PermissionRegistry;
use App\Models\User;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Payment;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/** الإيرادات والمصروفات: net collected, expenses, operating result and collection rate, day by day and by kind. */
final class FinanceReport extends Report
{
    public const INVOICE_TYPES = ['membership' => 'عضوية', 'pt' => 'تدريب شخصي', 'pos' => 'مبيعات المنتجات', 'other' => 'أخرى'];

    private const FIGURES = [
        'revenue' => ['صافي التحصيل', S::MONEY], 'expenses' => ['المصروفات', S::MONEY], 'net' => ['صافي الربح التشغيلي', S::MONEY],
        'invoiced' => ['قيمة الفواتير الصادرة', S::MONEY], 'collection_rate' => ['نسبة التحصيل', S::PERCENT], 'refunds' => ['المردودات', S::MONEY],
        'credit_notes' => ['الإشعارات الدائنة', S::MONEY], 'product_sales' => ['مبيعات المنتجات', S::MONEY],
    ];

    public function __construct(private readonly ReportService $reports) {}

    public function page(ReportFilters $f, User $user): array
    {
        $report = $this->reports->period($f->from, $f->to, true, $f->prevFrom, $f->prevTo, $f->branch);

        return ['report' => $report, 'empty' => $this->isEmpty($report)];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $r = $this->reports->period($f->from, $f->to, true, $f->prevFrom, $f->prevTo, $f->branch);
        $sales = PermissionRegistry::moduleEnabled('sales');
        $end = $f->to->endOfDay();

        $sheets = [
            S::summary($r['comparison'], $sales ? self::FIGURES : array_diff_key(self::FIGURES, ['product_sales' => 1])),
            new S('التحصيل اليومي', [['التاريخ', S::TEXT], ['صافي التحصيل', S::MONEY]], array_map(fn ($d) => [$d['date'], $d['amount']], $r['trend'])),
            new S('حسب طريقة الدفع', [['طريقة الدفع', S::TEXT], ['المبلغ', S::MONEY]], array_map(fn ($x) => [Payment::METHOD_LABELS[$x['key']] ?? $x['key'], $x['amount']], $r['revenue_by_method'])),
            new S('حسب نوع الإيراد', [['نوع الإيراد', S::TEXT], ['المبلغ', S::MONEY]], array_map(fn ($x) => [self::INVOICE_TYPES[$x['key']] ?? $x['key'], $x['amount']], $r['revenue_by_type'])),
            new S('المصروفات حسب التصنيف', [['التصنيف', S::TEXT], ['المبلغ', S::MONEY]], array_map(fn ($x) => [$x['key'], $x['amount']], $r['expenses_by_category'])),
        ];
        if ($sales) {
            $sheets[] = new S('المنتجات الأكثر مبيعًا', [['المنتج', S::TEXT], ['الكمية', S::NUMBER], ['الإيراد', S::MONEY], ['الربح', S::MONEY]],
                array_map(fn ($p) => [$p['product'], $p['quantity'], $p['revenue'], $p['profit']], $r['products']));
        }
        $sheets[] = new S('الدفعات', [['الإيصال', S::TEXT], ['التاريخ', S::TEXT], ['النوع', S::TEXT], ['الطريقة', S::TEXT], ['المبلغ', S::MONEY], ['الفاتورة', S::TEXT], ['العميل', S::TEXT], ['المرجع', S::TEXT]],
            (function () use ($f, $end) {
                foreach ($this->in(Payment::with('invoice:id,number,customer_name')->completed()->whereBetween('paid_at', [$f->from, $end]), $f)->orderBy('paid_at')->lazy(500) as $p) {
                    yield [$p->receipt_number, $p->paid_at?->format('Y-m-d H:i'), Payment::KIND_LABELS[$p->kind] ?? $p->kind, Payment::METHOD_LABELS[$p->method] ?? $p->method,
                        $p->kind === Payment::KIND_REFUND ? -1 * (float) $p->amount : (float) $p->amount, $p->invoice?->number, $p->invoice?->customer_name, $p->reference];
                }
            })());
        $sheets[] = new S('المصروفات', [['الرقم', S::TEXT], ['التاريخ', S::TEXT], ['التصنيف', S::TEXT], ['الوصف', S::TEXT], ['المبلغ', S::MONEY]],
            (function () use ($f) {
                foreach ($this->in(Expense::with('category:id,name')->whereBetween('expense_date', [$f->from, $f->to]), $f)->orderBy('expense_date')->lazy(500) as $e) {
                    yield [$e->number, $e->expense_date->toDateString(), $e->category?->name, $e->description, $e->amount];
                }
            })());

        return ['sheets' => $sheets, 'empty' => $this->isEmpty($r)];
    }

    private function isEmpty(array $report): bool
    {
        foreach (['revenue', 'expenses', 'refunds', 'invoiced', 'credit_notes'] as $key) {
            if ((float) ($report['comparison'][$key]['current'] ?? 0) != 0.0) {
                return false;
            }
        }

        return true;
    }
}
