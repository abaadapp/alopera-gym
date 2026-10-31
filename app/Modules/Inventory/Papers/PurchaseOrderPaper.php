<?php

namespace App\Modules\Inventory\Papers;

use App\Core\Papers\PaperBuilder;
use App\Core\Papers\PaperFormat as F;
use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Inventory\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Model;

/** أمر شراء — what the supplier reads to prepare the delivery: when it is wanted, how it is paid, what makes the total. */
final class PurchaseOrderPaper extends PaperBuilder
{
    public static function find(int $id): ?Model
    {
        return PurchaseOrder::find($id);
    }

    public static function allows(User $user, Model $record): bool
    {
        return $user->hasPermission('purchases.view');
    }

    public static function latest(User $user): ?Model
    {
        return PurchaseOrder::latest('id')->first();
    }

    public static function filename(Model $record): string
    {
        return 'purchase-order-'.$record->number;
    }

    public static function document(Model $record, array $tpl, Tenant $tenant): array
    {
        /** @var PurchaseOrder $po */
        $po = $record->loadMissing(['items.product:id,name', 'supplier', 'branch:id,name']);
        $currency = self::currency($tenant);
        $prices = (bool) ($tpl['show_prices'] ?? true);

        $columns = [['key' => 'name', 'label' => 'البيان'], ['key' => 'qty', 'label' => 'الكمية', 'align' => 'num', 'width' => 12]];
        if ($prices) {
            $columns[] = ['key' => 'unit', 'label' => 'سعر الوحدة', 'align' => 'amt', 'width' => 17];
            $columns[] = ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 20];
        }

        $totals = [];
        if ($prices) {
            $totals[] = ['label' => 'المجموع الفرعي', 'value' => F::amount($po->subtotal ?? $po->total)];
            if (Money::cmp($po->discount ?? 0, 0) > 0) {
                $totals[] = ['label' => 'الخصم', 'value' => F::minus($po->discount)];
            }
            if (Money::cmp($po->shipping ?? 0, 0) > 0) {
                $totals[] = ['label' => 'الشحن', 'value' => F::amount($po->shipping)];
            }
            if (Money::cmp($po->tax ?? 0, 0) > 0) {
                $totals[] = ['label' => 'الضريبة', 'hint' => F::rate($po->tax_rate), 'value' => F::amount($po->tax)];
            }
            $totals[] = ['label' => 'الإجمالي', 'value' => F::amount($po->total), 'currency' => F::currency($currency), 'grand' => true];
        }

        return [
            'title' => 'أمر شراء',
            'number' => $po->number,
            'status' => in_array($po->status, [PurchaseOrder::DRAFT, PurchaseOrder::CANCELLED], true) ? (PurchaseOrder::LABELS[$po->status] ?? '') : '',
            'parties' => [['caption' => 'المورد', 'lines' => [$po->supplier?->name, $po->supplier?->phone, $po->supplier?->email]]],
            'meta' => self::fields([
                ['label' => 'رقم الأمر', 'value' => $po->number, 'ltr' => true],
                ['label' => 'التاريخ', 'value' => $po->order_date?->format('Y-m-d'), 'ltr' => true],
                ['label' => 'تاريخ التسليم المتوقع', 'value' => $po->expected_on?->format('Y-m-d'), 'ltr' => true],
                ['label' => 'شروط الدفع', 'value' => F::terms($po->payment_terms_days)],
                ['label' => 'مرجع المورد', 'value' => (string) ($po->supplier_reference ?? ''), 'ltr' => true],
                ['label' => 'الحالة', 'value' => PurchaseOrder::LABELS[$po->status] ?? $po->status],
                ($tpl['show_branch'] ?? true) ? ['label' => 'الفرع', 'value' => $po->branch?->name] : null,
            ]),
            'figure' => $prices ? ['label' => 'الإجمالي', 'value' => F::amount($po->total), 'currency' => F::currency($currency)] : null,
            'table' => ['columns' => $columns, 'count' => (bool) ($tpl['show_items_count'] ?? false), 'rows' => $po->items->map(fn ($i) => [
                'name' => $i->product?->name ?? '—',
                'notes' => [self::packing($i->unit, $i->unit_factor)],
                'qty' => F::qty($i->quantity),
                'unit' => F::amount($i->unit_cost),
                'total' => F::amount($i->line_total),
            ])->all()],
            'totals' => $totals,
            'panels' => ($tpl['show_notes'] ?? true) && filled($po->notes) ? [['caption' => 'ملاحظات', 'text' => $po->notes]] : [],
            'signatures' => ($tpl['show_signature'] ?? false) ? ['المعتمد', 'المورد'] : [],
        ];
    }

    /** «كرتون × 24» under the item; nothing for a single unit. */
    public static function packing(?string $unit, mixed $factor): string
    {
        $unit = trim((string) $unit);
        if ($unit === '') {
            return '';
        }

        return (float) $factor > 1 ? $unit.' × '.F::qty($factor) : $unit;
    }

    public static function sample(Tenant $tenant, array $tpl): array
    {
        $currency = self::currency($tenant);
        $prices = (bool) ($tpl['show_prices'] ?? true);
        $columns = [['key' => 'name', 'label' => 'البيان'], ['key' => 'qty', 'label' => 'الكمية', 'align' => 'num', 'width' => 12]];
        if ($prices) {
            $columns[] = ['key' => 'unit', 'label' => 'سعر الوحدة', 'align' => 'amt', 'width' => 17];
            $columns[] = ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 20];
        }

        return [
            'title' => 'أمر شراء',
            'number' => 'PO-000031',
            'status' => '',
            'parties' => [['caption' => 'المورد', 'lines' => ['مورد المكملات', '92345678']]],
            'meta' => self::fields([
                ['label' => 'رقم الأمر', 'value' => 'PO-000031', 'ltr' => true],
                ['label' => 'التاريخ', 'value' => today()->format('Y-m-d'), 'ltr' => true],
                ['label' => 'تاريخ التسليم المتوقع', 'value' => today()->addDays(7)->format('Y-m-d'), 'ltr' => true],
                ['label' => 'شروط الدفع', 'value' => F::terms(30)],
                ['label' => 'الحالة', 'value' => PurchaseOrder::LABELS[PurchaseOrder::ORDERED]],
                ($tpl['show_branch'] ?? true) ? ['label' => 'الفرع', 'value' => 'الفرع الرئيسي'] : null,
            ]),
            'figure' => $prices ? ['label' => 'الإجمالي', 'value' => F::amount(60), 'currency' => F::currency($currency)] : null,
            'table' => ['columns' => $columns, 'count' => (bool) ($tpl['show_items_count'] ?? false), 'rows' => [
                ['name' => 'مشروب بروتين', 'notes' => ['كرتون × 24'], 'qty' => '2', 'unit' => F::amount(24), 'total' => F::amount(48)],
                ['name' => 'مياه معدنية', 'notes' => ['كرتون × 12'], 'qty' => '4', 'unit' => F::amount(3), 'total' => F::amount(12)],
            ]],
            'totals' => $prices ? [['label' => 'المجموع الفرعي', 'value' => F::amount(60)], ['label' => 'الإجمالي', 'value' => F::amount(60), 'currency' => F::currency($currency), 'grand' => true]] : [],
            'panels' => [],
            'signatures' => ($tpl['show_signature'] ?? false) ? ['المعتمد', 'المورد'] : [],
        ];
    }
}
