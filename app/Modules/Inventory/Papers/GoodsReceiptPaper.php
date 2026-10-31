<?php

namespace App\Modules\Inventory\Papers;

use App\Core\Papers\PaperBuilder;
use App\Core\Papers\PaperFormat as F;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Inventory\Models\GoodsReceipt;
use Illuminate\Database\Eloquent\Model;

/**
 * سند استلام بضاعة — signed at the store-room door. Costs are off by default: the storekeeper who signs it counts
 * what arrived, and has no need to see what it cost.
 */
final class GoodsReceiptPaper extends PaperBuilder
{
    public const STATUSES = [GoodsReceipt::PENDING => 'بانتظار الاعتماد', GoodsReceipt::APPROVED => 'معتمد', GoodsReceipt::REJECTED => 'مرفوض'];

    public static function find(int $id): ?Model
    {
        return GoodsReceipt::find($id);
    }

    public static function allows(User $user, Model $record): bool
    {
        return $user->hasPermission('purchases.view');
    }

    public static function latest(User $user): ?Model
    {
        return GoodsReceipt::latest('id')->first();
    }

    public static function filename(Model $record): string
    {
        return 'goods-receipt-'.$record->number;
    }

    public static function document(Model $record, array $tpl, Tenant $tenant): array
    {
        /** @var GoodsReceipt $grn */
        $grn = $record->loadMissing(['lines.product:id,name', 'supplier', 'order:id,number', 'branch:id,name', 'receiver:id,name']);
        $currency = self::currency($tenant);
        $prices = (bool) ($tpl['show_prices'] ?? false);

        $columns = [['key' => 'name', 'label' => 'البيان'], ['key' => 'ordered', 'label' => 'المطلوبة', 'align' => 'num', 'width' => 13], ['key' => 'qty', 'label' => 'المستلمة', 'align' => 'num', 'width' => 13]];
        if ($prices) {
            $columns[] = ['key' => 'unit', 'label' => 'سعر الوحدة', 'align' => 'amt', 'width' => 16];
            $columns[] = ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 18];
        }

        return [
            'title' => 'سند استلام بضاعة',
            'number' => $grn->number,
            'status' => $grn->status === GoodsReceipt::APPROVED ? '' : (self::STATUSES[$grn->status] ?? ''),
            'parties' => [['caption' => 'المورد', 'lines' => [$grn->supplier?->name, $grn->order ? 'أمر الشراء: '.$grn->order->number : null]]],
            'meta' => self::fields([
                ['label' => 'رقم السند', 'value' => $grn->number, 'ltr' => true],
                ['label' => 'تاريخ الاستلام', 'value' => $grn->received_on?->format('Y-m-d'), 'ltr' => true],
                ['label' => 'مرجع المورد', 'value' => (string) ($grn->delivery_reference ?? ''), 'ltr' => true],
                ['label' => 'الحالة', 'value' => self::STATUSES[$grn->status] ?? $grn->status],
                ($tpl['show_branch'] ?? true) ? ['label' => 'الفرع', 'value' => $grn->branch?->name] : null,
                ($tpl['show_employee'] ?? true) ? ['label' => 'استلمه', 'value' => $grn->receiver?->name] : null,
            ]),
            'figure' => $prices ? ['label' => 'الإجمالي', 'value' => F::amount($grn->total), 'currency' => F::currency($currency)] : null,
            'table' => ['columns' => $columns, 'rows' => $grn->lines->map(fn ($l) => [
                'name' => $l->product?->name ?? '—',
                'notes' => [$l->note],
                'ordered' => F::qty($l->ordered_quantity),
                'qty' => F::qty($l->quantity),
                'unit' => F::amount($l->unit_cost),
                'total' => F::amount($l->line_total),
            ])->all()],
            'totals' => $prices ? [['label' => 'الإجمالي', 'value' => F::amount($grn->total), 'currency' => F::currency($currency), 'grand' => true]] : [],
            'panels' => ($tpl['show_notes'] ?? true) && filled($grn->notes) ? [['caption' => 'ملاحظات', 'text' => $grn->notes]] : [],
            'signatures' => ($tpl['show_signature'] ?? true) ? ['أمين المخزن', 'المورد'] : [],
        ];
    }

    public static function sample(Tenant $tenant, array $tpl): array
    {
        $currency = self::currency($tenant);
        $prices = (bool) ($tpl['show_prices'] ?? false);
        $columns = [['key' => 'name', 'label' => 'البيان'], ['key' => 'ordered', 'label' => 'المطلوبة', 'align' => 'num', 'width' => 13], ['key' => 'qty', 'label' => 'المستلمة', 'align' => 'num', 'width' => 13]];
        if ($prices) {
            $columns[] = ['key' => 'unit', 'label' => 'سعر الوحدة', 'align' => 'amt', 'width' => 16];
            $columns[] = ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 18];
        }

        return [
            'title' => 'سند استلام بضاعة',
            'number' => 'GRN-000012',
            'status' => '',
            'parties' => [['caption' => 'المورد', 'lines' => ['مورد المكملات', 'أمر الشراء: PO-000031']]],
            'meta' => self::fields([
                ['label' => 'رقم السند', 'value' => 'GRN-000012', 'ltr' => true],
                ['label' => 'تاريخ الاستلام', 'value' => today()->format('Y-m-d'), 'ltr' => true],
                ['label' => 'الحالة', 'value' => 'معتمد'],
                ($tpl['show_branch'] ?? true) ? ['label' => 'الفرع', 'value' => 'الفرع الرئيسي'] : null,
                ($tpl['show_employee'] ?? true) ? ['label' => 'استلمه', 'value' => 'أمين المخزن'] : null,
            ]),
            'figure' => $prices ? ['label' => 'الإجمالي', 'value' => F::amount(48), 'currency' => F::currency($currency)] : null,
            'table' => ['columns' => $columns, 'rows' => [
                ['name' => 'مشروب بروتين', 'ordered' => '48', 'qty' => '48', 'unit' => F::amount(1), 'total' => F::amount(48)],
                ['name' => 'مياه معدنية', 'ordered' => '48', 'qty' => '36', 'notes' => ['النقص يصل مع الشحنة التالية'], 'unit' => F::amount(0), 'total' => F::amount(0)],
            ]],
            'totals' => $prices ? [['label' => 'الإجمالي', 'value' => F::amount(48), 'currency' => F::currency($currency), 'grand' => true]] : [],
            'panels' => [],
            'signatures' => ($tpl['show_signature'] ?? true) ? ['أمين المخزن', 'المورد'] : [],
        ];
    }
}
