<?php

namespace App\Modules\Finance\Papers;

use App\Core\Papers\PaperBuilder;
use App\Core\Papers\PaperFormat as F;
use App\Core\Papers\PaperIssuer;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\Payment;
use Illuminate\Database\Eloquent\Model;

/**
 * سند القبض — the receipt handed to whoever paid; for money paid back, the same paper titled «سند رد مبلغ». Only a
 * completed payment has one: a transfer awaiting confirmation has received nothing yet.
 */
final class ReceiptPaper extends PaperBuilder
{
    public static function find(int $id): ?Model
    {
        return Payment::where('status', Payment::COMPLETED)->find($id);
    }

    public static function allows(User $user, Model $record): bool
    {
        return $user->hasPermission('invoices.view');
    }

    public static function latest(User $user): ?Model
    {
        return Payment::where('status', Payment::COMPLETED)->where('kind', Payment::KIND_PAYMENT)->whereNotNull('invoice_id')->latest('id')->first();
    }

    public static function filename(Model $record): string
    {
        return 'receipt-'.($record->receipt_number ?? $record->id);
    }

    public static function issuer(Model $record, Tenant $tenant): array
    {
        return PaperIssuer::resolve($tenant, $record->invoice?->issuer);
    }

    public static function document(Model $record, array $tpl, Tenant $tenant): array
    {
        /** @var Payment $payment */
        $payment = $record->loadMissing(['invoice', 'receiver:id,name', 'branch:id,name']);
        $invoice = $payment->invoice;
        $currency = self::currency($tenant, $payment->currency);
        $refund = $payment->kind === Payment::KIND_REFUND;

        $totals = [['label' => $refund ? 'المبلغ المردود' : 'المبلغ المستلم', 'value' => F::amount($payment->amount), 'currency' => F::currency($currency), 'grand' => true]];
        if ($invoice) {
            $totals[] = ['label' => 'صافي الفاتورة', 'value' => F::amount($invoice->netTotal())];
            $totals[] = ['label' => 'المدفوع', 'value' => F::amount($invoice->paid_total)];
            $totals[] = ['label' => 'المتبقي', 'value' => F::amount($invoice->status === 'void' ? 0 : $invoice->balance())];
        }

        return [
            'title' => $refund ? 'سند رد مبلغ' : 'سند قبض',
            'number' => (string) ($payment->receipt_number ?? '#'.$payment->id),
            'status' => '',
            'parties' => [['caption' => $refund ? 'المستفيد' : 'الدافع', 'lines' => [$invoice?->customer_name ?? 'عميل', $invoice?->customer_phone]]],
            'meta' => self::fields([
                ['label' => 'رقم السند', 'value' => (string) ($payment->receipt_number ?? ''), 'ltr' => true],
                ['label' => 'تاريخ القبض', 'value' => $payment->paid_at?->format('Y-m-d H:i'), 'ltr' => true],
                ['label' => 'وسيلة الدفع', 'value' => Payment::METHOD_LABELS[$payment->method] ?? $payment->method],
                ['label' => 'المرجع', 'value' => (string) ($payment->reference ?? ''), 'ltr' => true],
                ['label' => 'عن الفاتورة', 'value' => (string) ($invoice?->number ?? ''), 'ltr' => true],
                ($tpl['show_branch'] ?? false) ? ['label' => 'الفرع', 'value' => $payment->branch?->name] : null,
                ($tpl['show_employee'] ?? true) ? ['label' => 'استلمه', 'value' => $payment->receiver?->name] : null,
            ]),
            'figure' => ['label' => $refund ? 'المبلغ المردود' : 'المبلغ المستلم', 'value' => F::amount($payment->amount), 'currency' => F::currency($currency)],
            'table' => ['columns' => [['key' => 'name', 'label' => 'البيان'], ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 24]], 'rows' => [[
                'name' => ($refund ? 'رد مبلغ من الفاتورة ' : 'سداد الفاتورة ').($invoice?->number ?? ''),
                'notes' => [$invoice ? 'صدرت في '.$invoice->issue_date->format('Y-m-d') : null],
                'total' => F::amount($payment->amount),
            ]]],
            'totals' => $totals,
            'panels' => ($tpl['show_notes'] ?? true) && filled($payment->notes) ? [['caption' => 'ملاحظات', 'text' => $payment->notes]] : [],
            'signatures' => ($tpl['show_signature'] ?? true) ? ['المستلم', 'الدافع'] : [],
        ];
    }

    public static function sample(Tenant $tenant, array $tpl): array
    {
        $currency = self::currency($tenant);

        return [
            'title' => 'سند قبض',
            'number' => 'R-000311',
            'status' => '',
            'parties' => [['caption' => 'الدافع', 'lines' => ['عضو تجريبي', '91234567']]],
            'meta' => self::fields([
                ['label' => 'رقم السند', 'value' => 'R-000311', 'ltr' => true],
                ['label' => 'تاريخ القبض', 'value' => now()->format('Y-m-d H:i'), 'ltr' => true],
                ['label' => 'وسيلة الدفع', 'value' => 'نقدًا'],
                ['label' => 'عن الفاتورة', 'value' => 'INV-'.today()->format('Y').'-000123', 'ltr' => true],
                ($tpl['show_branch'] ?? false) ? ['label' => 'الفرع', 'value' => 'الفرع الرئيسي'] : null,
                ($tpl['show_employee'] ?? true) ? ['label' => 'استلمه', 'value' => 'موظف الاستقبال'] : null,
            ]),
            'figure' => ['label' => 'المبلغ المستلم', 'value' => F::amount(40), 'currency' => F::currency($currency)],
            'table' => ['columns' => [['key' => 'name', 'label' => 'البيان'], ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 24]], 'rows' => [
                ['name' => 'سداد الفاتورة INV-'.today()->format('Y').'-000123', 'notes' => ['صدرت في '.today()->format('Y-m-d')], 'total' => F::amount(40)],
            ]],
            'totals' => [
                ['label' => 'المبلغ المستلم', 'value' => F::amount(40), 'currency' => F::currency($currency), 'grand' => true],
                ['label' => 'صافي الفاتورة', 'value' => F::amount(80)],
                ['label' => 'المدفوع', 'value' => F::amount(40)],
                ['label' => 'المتبقي', 'value' => F::amount(40)],
            ],
            'panels' => [],
            'signatures' => ($tpl['show_signature'] ?? true) ? ['المستلم', 'الدافع'] : [],
        ];
    }
}
