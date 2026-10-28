<?php

namespace App\Modules\Finance\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\NumberSequence;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Contracts\Billable;
use App\Modules\Finance\Events\InvoicePaid;
use App\Modules\Finance\Events\InvoiceVoided;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InvoiceService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Issue an invoice.
     *
     * @param  list<array{description: string, quantity?: int|float|string, unit_price: string|int|float, discount?: string|int|float|null, itemable?: Model|null}>  $lines
     * @param  array{type?: string, branch_id?: int|null, due_date?: string|null, notes?: string|null, tax_rate?: float|int|null, issue_date?: string|null}  $options
     */
    public function issue(?Billable $billable, array $lines, array $options = [], ?string $customerName = null): Invoice
    {
        if ($lines === []) {
            throw new BusinessRuleException('invoice.empty', 'لا يمكن إصدار فاتورة بدون بنود.');
        }

        $tenant = $this->tenants->require();

        return DB::transaction(function () use ($billable, $lines, $options, $customerName, $tenant) {
            $subtotal = 0;
            $discount = 0;
            $prepared = [];

            foreach ($lines as $line) {
                $qty = (float) ($line['quantity'] ?? 1);
                $gross = Money::toMinor(Money::mul($line['unit_price'], $qty));
                $lineDiscount = Money::toMinor($line['discount'] ?? 0);

                if ($qty <= 0 || $gross < 0 || $lineDiscount < 0 || $lineDiscount > $gross) {
                    throw new BusinessRuleException('invoice.line', 'بند غير صالح في الفاتورة.');
                }

                $subtotal += $gross;
                $discount += $lineDiscount;
                $prepared[] = [
                    'description' => Str::limit($line['description'], 250),
                    'quantity' => $qty,
                    'unit_price' => Money::normalize($line['unit_price']),
                    'discount' => Money::fromMinor($lineDiscount),
                    'line_total' => Money::fromMinor($gross - $lineDiscount),
                    'itemable' => $line['itemable'] ?? null,
                ];
            }

            $taxRate = (float) ($options['tax_rate'] ?? $tenant->setting('vat_rate', 0));
            $taxable = $subtotal - $discount;
            $tax = (int) round($taxable * $taxRate / 100);
            $total = $taxable + $tax;
            $issueDate = CarbonImmutable::parse($options['issue_date'] ?? today());
            $dueDays = (int) $tenant->setting('invoice_due_days', 0);

            $invoice = Invoice::create([
                'branch_id' => $options['branch_id'] ?? null,
                'number' => NumberSequence::next('invoice'),
                'billable_type' => $billable instanceof Model ? $billable->getMorphClass() : null,
                'billable_id' => $billable instanceof Model ? $billable->getKey() : null,
                'customer_name' => $billable?->billingName() ?? ($customerName ?: 'عميل نقدي'),
                'customer_phone' => $billable?->billingPhone(),
                'customer_email' => $billable?->billingEmail(),
                'type' => $options['type'] ?? 'other',
                'status' => Invoice::ISSUED,
                'issue_date' => $issueDate,
                'due_date' => $options['due_date'] ?? $issueDate->addDays($dueDays),
                'subtotal' => Money::fromMinor($subtotal),
                'discount_total' => Money::fromMinor($discount),
                'tax_rate' => $taxRate,
                'tax_total' => Money::fromMinor($tax),
                'total' => Money::fromMinor($total),
                'paid_total' => '0',
                'currency' => $tenant->currency,
                'notes' => $options['notes'] ?? null,
                'public_token' => Str::random(48),
                'created_by' => Auth::guard('web')->id(),
            ]);

            foreach ($prepared as $line) {
                $itemable = $line['itemable'];
                unset($line['itemable']);
                $invoice->items()->create($line + [
                    'itemable_type' => $itemable?->getMorphClass(),
                    'itemable_id' => $itemable?->getKey(),
                ]);
            }

            // Nothing to collect (fully discounted, upgrade credit covers it): it is paid on issue.
            if ($total === 0) {
                $invoice->forceFill(['status' => Invoice::PAID, 'paid_at' => now()])->save();
                InvoicePaid::dispatch($invoice);
            }

            return $invoice;
        });
    }

    public function void(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === Invoice::VOID) {
                return $invoice;
            }

            // Net money counts: a fully refunded invoice can be voided; a transfer awaiting confirmation blocks it.
            $hasPending = Payment::where('invoice_id', $invoice->id)->where('status', Payment::PENDING)->exists();

            if (! Money::isZero($invoice->paid_total) || $hasPending) {
                throw new BusinessRuleException('invoice.has_payments', 'لا يمكن إلغاء فاتورة عليها دفعات. قومي برد المبلغ أولًا.');
            }

            $invoice->forceFill([
                'status' => Invoice::VOID,
                'voided_at' => now(),
                'void_reason' => $reason,
                'voided_by' => Auth::guard('web')->id(),
            ])->save();

            $this->audit->log('invoice.voided', $invoice, ['reason' => $reason]);
            InvoiceVoided::dispatch($invoice);

            return $invoice;
        });
    }
}
