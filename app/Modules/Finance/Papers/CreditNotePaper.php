<?php

namespace App\Modules\Finance\Papers;

use App\Core\Papers\PaperBuilder;
use App\Core\Papers\PaperFormat as F;
use App\Core\Papers\PaperIssuer;
use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\CreditNote;
use Illuminate\Database\Eloquent\Model;

/** إشعار دائن — lowers what an issued invoice is worth, which stays as it was; paying money back is a separate step. */
final class CreditNotePaper extends PaperBuilder
{
    public static function find(int $id): ?Model
    {
        return CreditNote::find($id);
    }

    public static function allows(User $user, Model $record): bool
    {
        return $user->hasPermission('invoices.view');
    }

    public static function latest(User $user): ?Model
    {
        return CreditNote::latest('id')->first();
    }

    public static function filename(Model $record): string
    {
        return 'credit-note-'.$record->number;
    }

    public static function issuer(Model $record, Tenant $tenant): array
    {
        return PaperIssuer::resolve($tenant, $record->invoice?->issuer);
    }

    public static function document(Model $record, array $tpl, Tenant $tenant): array
    {
        /** @var CreditNote $note */
        $note = $record->loadMissing(['invoice', 'creator:id,name']);
        $invoice = $note->invoice;
        $currency = self::currency($tenant, $note->currency);

        $totals = [['label' => 'إجمالي الإشعار', 'value' => F::amount($note->amount), 'currency' => F::currency($currency), 'grand' => true]];
        if (Money::cmp($note->refunded_total, 0) > 0) {
            $totals[] = ['label' => 'المبلغ المردود', 'value' => F::amount($note->refunded_total)];
        }
        if ($invoice) {
            $totals[] = ['label' => 'صافي الفاتورة', 'value' => F::amount($invoice->netTotal())];
        }

        return [
            'title' => 'إشعار دائن',
            'number' => $note->number,
            'status' => '',
            'parties' => [['caption' => 'العميل', 'lines' => [$invoice?->customer_name, $invoice?->customer_phone]]],
            'meta' => self::fields([
                ['label' => 'رقم الإشعار', 'value' => $note->number, 'ltr' => true],
                ['label' => 'التاريخ', 'value' => $note->created_at->format('Y-m-d'), 'ltr' => true],
                ['label' => 'الفاتورة الأصلية', 'value' => (string) ($invoice?->number ?? ''), 'ltr' => true],
                ['label' => 'تاريخ الإصدار', 'value' => $invoice?->issue_date?->format('Y-m-d'), 'ltr' => true],
                ($tpl['show_employee'] ?? false) ? ['label' => 'أصدرها', 'value' => $note->creator?->name] : null,
            ]),
            'figure' => ['label' => 'إجمالي الإشعار', 'value' => F::amount($note->amount), 'currency' => F::currency($currency)],
            'table' => ['columns' => [['key' => 'name', 'label' => 'البيان'], ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 24]], 'rows' => [[
                'name' => 'تخفيض على الفاتورة '.($invoice?->number ?? ''),
                'notes' => [$note->reason],
                'total' => F::amount($note->amount),
            ]]],
            'totals' => $totals,
            // The reason is part of the note itself (in the table); the notes panel repeats it only when asked.
            'panels' => ($tpl['show_notes'] ?? true) && filled($invoice?->notes) ? [['caption' => 'ملاحظات', 'text' => $invoice->notes]] : [],
            'signatures' => ($tpl['show_signature'] ?? false) ? ['المحاسب', 'المعتمد'] : [],
        ];
    }

    public static function sample(Tenant $tenant, array $tpl): array
    {
        $currency = self::currency($tenant);

        return [
            'title' => 'إشعار دائن',
            'number' => 'CN-000014',
            'status' => '',
            'parties' => [['caption' => 'العميل', 'lines' => ['عضو تجريبي', '91234567']]],
            'meta' => self::fields([
                ['label' => 'رقم الإشعار', 'value' => 'CN-000014', 'ltr' => true],
                ['label' => 'التاريخ', 'value' => today()->format('Y-m-d'), 'ltr' => true],
                ['label' => 'الفاتورة الأصلية', 'value' => 'INV-'.today()->format('Y').'-000123', 'ltr' => true],
            ]),
            'figure' => ['label' => 'إجمالي الإشعار', 'value' => F::amount(15), 'currency' => F::currency($currency)],
            'table' => ['columns' => [['key' => 'name', 'label' => 'البيان'], ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 24]], 'rows' => [
                ['name' => 'تخفيض على الفاتورة INV-'.today()->format('Y').'-000123', 'notes' => ['استرداد جزئي بعد تجميد العضوية'], 'total' => F::amount(15)],
            ]],
            'totals' => [['label' => 'إجمالي الإشعار', 'value' => F::amount(15), 'currency' => F::currency($currency), 'grand' => true], ['label' => 'صافي الفاتورة', 'value' => F::amount(65)]],
            'panels' => [],
            'signatures' => ($tpl['show_signature'] ?? false) ? ['المحاسب', 'المعتمد'] : [],
        ];
    }
}
