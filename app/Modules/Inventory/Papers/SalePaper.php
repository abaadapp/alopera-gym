<?php

namespace App\Modules\Inventory\Papers;

use App\Core\Papers\PaperBuilder;
use App\Core\Papers\PaperFormat as F;
use App\Core\Papers\PaperIssuer;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Inventory\Http\Controllers\SalesController;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Models\SaleItem;
use Illuminate\Database\Eloquent\Model;

/**
 * فاتورة البيع — a point-of-sale sale or a website order. The thermal receipt and the A4 invoice are two faces of the
 * same paper. The sale's own invoice is not issued again: its number is printed, and its identity stamp is used.
 */
final class SalePaper extends PaperBuilder
{
    public static function find(int $id): ?Model
    {
        return Sale::find($id);
    }

    public static function allows(User $user, Model $record): bool
    {
        return $user->hasPermission('pos.sell') || $user->hasPermission('sales.view') || $user->hasPermission('orders.manage');
    }

    public static function latest(User $user): ?Model
    {
        return Sale::latest('id')->first();
    }

    public static function filename(Model $record): string
    {
        return 'sale-'.$record->number;
    }

    public static function issuer(Model $record, Tenant $tenant): array
    {
        return PaperIssuer::resolve($tenant, $record->invoice_id ? Invoice::whereKey($record->invoice_id)->value('issuer') : null);
    }

    public static function document(Model $record, array $tpl, Tenant $tenant): array
    {
        /** @var Sale $sale */
        $sale = $record->loadMissing(['items.addons', 'cashier:id,name', 'customer', 'invoice:id,number,tax_rate,issuer']);
        $currency = self::currency($tenant);
        $issuer = self::issuer($sale, $tenant);
        $taxed = Money::cmp($sale->tax, 0) > 0 && ($issuer['vat_number'] ?? '') !== '';

        $totals = [['label' => 'المجموع الفرعي', 'value' => F::amount($sale->subtotal)]];
        if (Money::cmp($sale->discount, 0) > 0) {
            $totals[] = ['label' => 'الخصم', 'value' => F::minus($sale->discount)];
        }
        if (Money::cmp($sale->tax, 0) > 0) {
            $totals[] = ['label' => 'ضريبة القيمة المضافة', 'hint' => $sale->invoice ? F::rate($sale->invoice->tax_rate) : null, 'value' => F::amount($sale->tax)];
        }
        $totals[] = ['label' => 'الإجمالي', 'value' => F::amount($sale->total), 'currency' => F::currency($currency), 'grand' => true];

        return [
            'title' => $taxed ? 'فاتورة بيع ضريبية' : 'فاتورة بيع',
            'issuer_caption' => 'البائع',
            'number' => $sale->number,
            'status' => match ($sale->status) {
                Sale::VOIDED => 'ملغاة ومستردة',
                Sale::CANCELLED => 'ملغاة',
                default => $sale->source === Sale::WEBSITE && ! $sale->paid_at ? 'بانتظار الدفع' : '',
            },
            'parties' => [['caption' => 'العميل', 'lines' => [$sale->buyerName(), ($tpl['show_phone'] ?? false) ? $sale->customer_phone : null]]],
            'meta' => self::fields([
                ['label' => 'رقم العملية', 'value' => $sale->number, 'ltr' => true],
                ['label' => 'التاريخ', 'value' => $sale->created_at->format('Y-m-d H:i'), 'ltr' => true],
                ['label' => 'وسيلة الدفع', 'value' => SalesController::METHOD_LABELS[$sale->payment_method] ?? $sale->payment_method],
                ['label' => 'رقم الفاتورة', 'value' => (string) ($sale->invoice?->number ?? ''), 'ltr' => true],
                ($tpl['show_branch'] ?? true) ? ['label' => 'الفرع', 'value' => $sale->branch_id ? Branch::whereKey($sale->branch_id)->value('name') : null] : null,
                ($tpl['show_employee'] ?? true) ? ['label' => 'الكاشير', 'value' => $sale->cashier?->name] : null,
            ]),
            'figure' => ['label' => 'الإجمالي', 'value' => F::amount($sale->total), 'currency' => F::currency($currency)],
            // Each add-on is its own line under its product, so every line reads quantity × price = amount and the
            // lines add up to the subtotal.
            'table' => self::table($sale->items->flatMap(fn (SaleItem $i) => [
                // A product's line total includes its add-ons; here the product line is the product alone.
                ['name' => $i->name, 'qty' => F::qty($i->quantity), 'unit' => F::amount($i->unit_price), 'total' => F::amount(Money::sub($i->line_total, $i->addons_total ?? 0))],
                // An add-on's quantity is per product unit; the line shows all the units sold (as the invoice does).
                ...$i->addons->map(fn ($a) => ['name' => '+ '.$a->name, 'notes' => ['إضافة على '.$i->name], 'qty' => F::qty((float) $a->quantity * (float) $i->quantity), 'unit' => F::amount($a->unit_price), 'total' => F::amount($a->line_total)])->all(),
            ])->values()->all(), (bool) ($tpl['show_items_count'] ?? false)),
            'totals' => $totals,
            'panels' => ($tpl['show_notes'] ?? true) && filled($sale->notes) ? [['caption' => 'ملاحظات', 'text' => $sale->notes]] : [],
            'signatures' => [],
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private static function table(array $rows, bool $count): array
    {
        return [
            'columns' => [['key' => 'name', 'label' => 'البيان'], ['key' => 'qty', 'label' => 'الكمية', 'align' => 'num', 'width' => 12], ['key' => 'unit', 'label' => 'السعر', 'align' => 'amt', 'width' => 17], ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 20]],
            'rows' => $rows,
            'count' => $count,
            'empty' => 'لا أصناف',
        ];
    }

    public static function sample(Tenant $tenant, array $tpl): array
    {
        $currency = self::currency($tenant);

        return [
            'title' => 'فاتورة بيع',
            'issuer_caption' => 'البائع',
            'number' => 'S-000245',
            'status' => '',
            'parties' => [['caption' => 'العميل', 'lines' => ['عضو تجريبي', ($tpl['show_phone'] ?? false) ? '91234567' : null]]],
            'meta' => self::fields([
                ['label' => 'رقم العملية', 'value' => 'S-000245', 'ltr' => true],
                ['label' => 'التاريخ', 'value' => now()->format('Y-m-d H:i'), 'ltr' => true],
                ['label' => 'وسيلة الدفع', 'value' => 'نقدًا'],
                ($tpl['show_branch'] ?? true) ? ['label' => 'الفرع', 'value' => 'الفرع الرئيسي'] : null,
                ($tpl['show_employee'] ?? true) ? ['label' => 'الكاشير', 'value' => 'موظف الاستقبال'] : null,
            ]),
            'figure' => ['label' => 'الإجمالي', 'value' => F::amount(4.3), 'currency' => F::currency($currency)],
            'table' => self::table([
                ['name' => 'مشروب بروتين', 'qty' => '1', 'unit' => F::amount(1.8), 'total' => F::amount(1.8)],
                ['name' => '+ جرعة إضافية', 'notes' => ['إضافة على مشروب بروتين'], 'qty' => '1', 'unit' => F::amount(0.5), 'total' => F::amount(0.5)],
                ['name' => 'مياه معدنية', 'qty' => '2', 'unit' => F::amount(0.25), 'total' => F::amount(0.5)],
                ['name' => 'منشفة رياضية', 'qty' => '1', 'unit' => F::amount(1.5), 'total' => F::amount(1.5)],
            ], (bool) ($tpl['show_items_count'] ?? false)),
            'totals' => [['label' => 'المجموع الفرعي', 'value' => F::amount(4.3)], ['label' => 'الإجمالي', 'value' => F::amount(4.3), 'currency' => F::currency($currency), 'grand' => true]],
            'panels' => [],
            'signatures' => [],
        ];
    }
}
