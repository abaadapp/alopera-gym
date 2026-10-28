<?php

namespace App\Modules\Inventory\Services;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\NumberSequence;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class PurchaseService
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /** @param list<array{product_id: int, quantity: float, unit_cost: float|string}> $items */
    public function create(Supplier $supplier, array $items, array $data = []): PurchaseOrder
    {
        return DB::transaction(function () use ($supplier, $items, $data) {
            $order = PurchaseOrder::create([
                'supplier_id' => $supplier->id,
                'branch_id' => $data['branch_id'] ?? null,
                'number' => NumberSequence::next('purchase_order'),
                'status' => PurchaseOrder::ORDERED,
                'order_date' => $data['order_date'] ?? today(),
                'expected_on' => $data['expected_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::guard('web')->id(),
            ]);

            $total = '0';
            foreach ($items as $item) {
                $line = Money::mul($item['unit_cost'], (float) $item['quantity']);
                $total = Money::add($total, $line);
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => Money::normalize($item['unit_cost']),
                    'line_total' => $line,
                ]);
            }

            $order->forceFill(['total' => $total])->save();

            return $order;
        });
    }

    /** Goods arrived: stock goes up at the purchase cost (weighted average). */
    public function receive(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order = PurchaseOrder::with('items.product')->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== PurchaseOrder::ORDERED) {
                throw new BusinessRuleException('purchase.status', 'لا يمكن استلام أمر شراء بهذه الحالة.');
            }

            foreach ($order->items as $item) {
                $this->stock->move($item->product, (float) $item->quantity, 'purchase', $order, 'استلام '.$order->number, (float) $item->unit_cost);
            }

            $order->forceFill([
                'status' => PurchaseOrder::RECEIVED,
                'received_at' => now(),
                'received_by' => Auth::guard('web')->id(),
            ])->save();

            return $order;
        });
    }

    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status === PurchaseOrder::RECEIVED) {
            throw new BusinessRuleException('purchase.received', 'تم استلام الأمر؛ لا يمكن إلغاؤه.');
        }
        $order->forceFill(['status' => PurchaseOrder::CANCELLED])->save();

        return $order;
    }
}
