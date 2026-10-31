<?php

namespace App\Modules\Inventory\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\NumberSequence;
use App\Core\Tenancy\BranchContext;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders and the goods that arrive against them. An order is a draft until approved (then it is sent to
 * the supplier); it moves no stock and posts nothing. Goods arrive on a goods receipt note — recorded, then
 * approved — and only the approval puts them on the receiving branch's shelf (in stock units, at the purchase cost),
 * once. An order may arrive in parts; more than ordered is refused, counting notes still waiting for approval.
 */
final class PurchaseService
{
    public function __construct(private readonly StockService $stock, private readonly BranchContext $branches, private readonly AuditLogger $audit) {}

    /**
     * @param  list<array{product_id: int, quantity: float|string, unit_cost: float|string, unit?: string|null, unit_factor?: float|string|null}>  $items
     * @param  array<string, mixed>  $data  branch_id, order_date, expected_on, notes, discount, shipping, tax_rate, supplier_reference, payment_terms_days, client_key
     */
    public function create(Supplier $supplier, array $items, array $data = []): PurchaseOrder
    {
        if (! empty($data['client_key']) && ($existing = PurchaseOrder::where('client_key', $data['client_key'])->first())) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($supplier, $items, $data) {
                $order = new PurchaseOrder([
                    'supplier_id' => $supplier->id,
                    'branch_id' => $data['branch_id'] ?? $this->branches->mainId(),
                    'number' => NumberSequence::next('purchase_order'),
                    'status' => PurchaseOrder::DRAFT,
                    'order_date' => $data['order_date'] ?? today(),
                    'created_by' => Auth::guard('web')->id(),
                    'client_key' => $data['client_key'] ?? null,
                ]);
                $this->fill($order, $supplier, $items, $data);
                $this->audit->log('purchase.created', $order, ['total' => $order->total]);

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            return PurchaseOrder::where('client_key', $data['client_key'] ?? '')->first() ?? throw $e;
        }
    }

    /** A draft only: its lines are replaced. */
    public function update(PurchaseOrder $order, Supplier $supplier, array $items, array $data = []): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $supplier, $items, $data) {
            $order = $this->lock($order, [PurchaseOrder::DRAFT], 'لا يُعدّل إلا أمر الشراء المسودة.');
            $order->supplier_id = $supplier->id;
            $order->branch_id = $data['branch_id'] ?? $order->branch_id;
            $order->order_date = $data['order_date'] ?? $order->order_date;
            $order->items()->delete();
            $this->fill($order, $supplier, $items, $data);

            return $order;
        });
    }

    /** Approved and sent to the supplier. Nothing moves and nothing is posted. */
    public function approve(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order = $this->lock($order, [PurchaseOrder::DRAFT], 'أمر الشراء معتمد مسبقًا أو مغلق.');
            $order->forceFill(['status' => PurchaseOrder::ORDERED, 'approved_by' => Auth::guard('web')->id(), 'approved_at' => now()])->save();
            $this->audit->log('purchase.approved', $order, ['total' => $order->total]);

            return $order;
        });
    }

    /** Before anything arrived: no approved and no waiting receipt note. */
    public function cancel(PurchaseOrder $order, string $reason = ''): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = $this->lock($order, [PurchaseOrder::DRAFT, PurchaseOrder::ORDERED], 'تم استلام جزء من الأمر أو كله؛ أغلقه بدل إلغائه.');
            if ($order->receipts()->whereIn('status', [GoodsReceipt::PENDING, GoodsReceipt::APPROVED])->exists() || $order->bills()->where('status', '!=', 'void')->where('status', '!=', 'rejected')->exists()) {
                throw new BusinessRuleException('purchase.in_use', 'على الأمر سند استلام أو سند مورد؛ لا يمكن إلغاؤه.');
            }
            $order->forceFill(['status' => PurchaseOrder::CANCELLED, 'cancelled_by' => Auth::guard('web')->id(), 'cancelled_at' => now(), 'cancel_reason' => $reason ?: null])->save();
            $this->audit->log('purchase.cancelled', $order, ['reason' => $reason]);

            return $order;
        });
    }

    /** Received in part and nothing more will come. */
    public function close(PurchaseOrder $order, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = $this->lock($order, [PurchaseOrder::PARTIAL], 'يُغلق الأمر المستلم جزئيًا فقط.');
            if ($order->receipts()->where('status', GoodsReceipt::PENDING)->exists()) {
                throw new BusinessRuleException('purchase.pending_receipt', 'على الأمر سند استلام بانتظار الاعتماد؛ اعتمده أو ارفضه أولًا.');
            }
            $order->forceFill(['status' => PurchaseOrder::CLOSED, 'cancel_reason' => $reason])->save();
            $this->audit->log('purchase.closed', $order, ['reason' => $reason]);

            return $order;
        });
    }

    /**
     * Record what arrived (purchase units by order line). Nothing moves until the note is approved.
     *
     * @param  array<int, array{quantity: float|string, note?: string|null}>  $lines  by purchase order item id
     * @param  array<string, mixed>  $data  received_on, delivery_reference, notes, client_key
     */
    public function receive(PurchaseOrder $order, array $lines, array $data = [], bool $approveNow = false): GoodsReceipt
    {
        if (! empty($data['client_key']) && ($existing = GoodsReceipt::where('client_key', $data['client_key'])->first())) {
            return $existing;
        }

        $receipt = DB::transaction(function () use ($order, $lines, $data) {
            $order = $this->lock($order, [PurchaseOrder::ORDERED, PurchaseOrder::PARTIAL], 'لا يُستلم إلا أمر شراء معتمد لم يكتمل استلامه.');
            $pending = DB::table('goods_receipt_lines as l')->join('goods_receipts as r', 'r.id', '=', 'l.goods_receipt_id')
                ->where('r.purchase_order_id', $order->id)->where('r.status', GoodsReceipt::PENDING)
                ->groupBy('l.purchase_order_item_id')->selectRaw('l.purchase_order_item_id, sum(l.quantity) as q')->pluck('q', 'purchase_order_item_id');

            $rows = [];
            $total = '0';
            foreach ($order->items()->with('product')->get() as $item) {
                $qty = round((float) ($lines[$item->id]['quantity'] ?? 0), 3);
                if ($qty <= 0) {
                    continue;
                }
                $allowed = round($item->remaining() - (float) ($pending[$item->id] ?? 0), 3);
                if ($qty > $allowed) {
                    throw new BusinessRuleException('receipt.over', 'المتبقي من «'.$item->product?->name.'» '.$allowed.' فقط (بعد السندات التي بانتظار الاعتماد).', 'lines');
                }
                $line = Money::mul($item->unit_cost, $qty);
                $total = Money::add($total, $line);
                $rows[] = ['purchase_order_item_id' => $item->id, 'product_id' => $item->product_id, 'ordered_quantity' => $item->remaining(), 'quantity' => $qty, 'unit_factor' => $item->unit_factor ?: 1, 'unit_cost' => $item->unit_cost, 'line_total' => $line, 'note' => $lines[$item->id]['note'] ?? null];
            }
            if ($rows === []) {
                throw new BusinessRuleException('receipt.empty', 'أدخل الكمية المستلمة لبند واحد على الأقل.', 'lines');
            }

            $receipt = GoodsReceipt::create([
                'number' => NumberSequence::next('goods_receipt'), 'purchase_order_id' => $order->id, 'supplier_id' => $order->supplier_id,
                'branch_id' => $order->branch_id ?? $this->branches->mainId(), 'status' => GoodsReceipt::PENDING,
                'received_on' => $data['received_on'] ?? today(), 'delivery_reference' => $data['delivery_reference'] ?? null, 'notes' => $data['notes'] ?? null,
                'total' => $total, 'received_by' => Auth::guard('web')->id(), 'client_key' => $data['client_key'] ?? null,
            ]);
            foreach ($rows as $row) {
                $receipt->lines()->create($row);
            }
            $this->audit->log('receipt.recorded', $receipt, ['order' => $order->number, 'total' => $total]);

            return $receipt;
        });

        return $approveNow ? $this->approveReceipt($receipt) : $receipt;
    }

    /** The goods go on the branch's shelf — once — and the order's received quantities and status follow. */
    public function approveReceipt(GoodsReceipt $receipt): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt) {
            $receipt = $this->lockReceipt($receipt);
            $order = PurchaseOrder::whereKey($receipt->purchase_order_id)->lockForUpdate()->firstOrFail();
            if (! $order->isReceivable()) {
                throw new BusinessRuleException('receipt.order_closed', 'أمر الشراء '.$order->number.' لم يعد ينتظر بضاعة.');
            }
            $items = $order->items()->with('product')->get()->keyBy('id');
            foreach ($receipt->lines as $line) {
                $item = $items[$line->purchase_order_item_id];
                if ((float) $line->quantity > $item->remaining() + 0.0005) {
                    throw new BusinessRuleException('receipt.over', 'تجاوز المستلم من «'.$item->product?->name.'» الكمية المطلوبة.');
                }
                $factor = (float) $line->unit_factor ?: 1;
                $this->stock->move($item->product, round((float) $line->quantity * $factor, 3), 'purchase', $receipt, 'استلام '.$order->number, round((float) $line->unit_cost / $factor, 3), branchId: $receipt->branch_id);
                $item->forceFill(['received_quantity' => round((float) $item->received_quantity + (float) $line->quantity, 3)])->save();
            }
            $done = $order->items()->get()->every(fn ($i) => $i->remaining() <= 0);
            $order->forceFill($done
                ? ['status' => PurchaseOrder::RECEIVED, 'received_at' => now(), 'received_by' => Auth::guard('web')->id()]
                : ['status' => PurchaseOrder::PARTIAL])->save();
            $receipt->forceFill(['status' => GoodsReceipt::APPROVED, 'approved_by' => Auth::guard('web')->id(), 'approved_at' => now()])->save();
            $this->audit->log('receipt.approved', $receipt, ['order' => $order->number, 'order_status' => $order->status]);

            return $receipt;
        });
    }

    public function rejectReceipt(GoodsReceipt $receipt, string $reason): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $reason) {
            $receipt = $this->lockReceipt($receipt);
            $receipt->forceFill(['status' => GoodsReceipt::REJECTED, 'reason' => $reason, 'rejected_by' => Auth::guard('web')->id(), 'rejected_at' => now()])->save();
            $this->audit->log('receipt.rejected', $receipt, ['reason' => $reason]);

            return $receipt;
        });
    }

    /** Subtotal − discount + shipping = taxable; + tax at the order's rate = total (3 decimals). */
    public static function totals(array $items, string|float $discount = 0, string|float $shipping = 0, float $taxRate = 0): array
    {
        $subtotal = '0';
        foreach ($items as $item) {
            $subtotal = Money::add($subtotal, Money::mul($item['unit_cost'], (float) $item['quantity']));
        }
        if (Money::toMinor($discount) < 0 || Money::cmp($discount, $subtotal) > 0) {
            throw new BusinessRuleException('purchase.discount', 'الخصم أكبر من قيمة البنود.', 'discount');
        }
        $taxable = Money::add(Money::sub($subtotal, $discount), $shipping);
        $tax = Money::percent($taxable, $taxRate);

        return ['subtotal' => $subtotal, 'discount' => Money::normalize($discount), 'shipping' => Money::normalize($shipping), 'tax' => $tax, 'total' => Money::add($taxable, $tax)];
    }

    private function fill(PurchaseOrder $order, Supplier $supplier, array $items, array $data): void
    {
        if ($items === []) {
            throw new BusinessRuleException('purchase.empty', 'أضف بندًا واحدًا على الأقل.', 'items');
        }
        $taxRate = (float) ($data['tax_rate'] ?? 0);
        $totals = self::totals($items, $data['discount'] ?? 0, $data['shipping'] ?? 0, $taxRate);
        $order->fill([
            'expected_on' => $data['expected_on'] ?? null,
            'notes' => $data['notes'] ?? null,
            'supplier_reference' => $data['supplier_reference'] ?? null,
            'payment_terms_days' => $data['payment_terms_days'] ?? $supplier->payment_terms_days,
            'tax_rate' => $taxRate,
        ] + $totals);
        $order->save();
        foreach ($items as $item) {
            $order->items()->create([
                'product_id' => $item['product_id'],
                'unit' => $item['unit'] ?? null,
                'unit_factor' => max(0.001, (float) ($item['unit_factor'] ?? 1)),
                'quantity' => $item['quantity'],
                'unit_cost' => Money::normalize($item['unit_cost']),
                'line_total' => Money::mul($item['unit_cost'], (float) $item['quantity']),
            ]);
        }
    }

    private function lock(PurchaseOrder $order, array $statuses, string $message): PurchaseOrder
    {
        $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
        if (! in_array($order->status, $statuses, true)) {
            throw new BusinessRuleException('purchase.status', $message);
        }

        return $order;
    }

    private function lockReceipt(GoodsReceipt $receipt): GoodsReceipt
    {
        $receipt = GoodsReceipt::with('lines')->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
        if ($receipt->status !== GoodsReceipt::PENDING) {
            throw new BusinessRuleException('receipt.status', 'سند الاستلام '.$receipt->number.' '.($receipt->status === GoodsReceipt::APPROVED ? 'معتمد مسبقًا' : 'مرفوض').'.');
        }

        return $receipt;
    }
}
