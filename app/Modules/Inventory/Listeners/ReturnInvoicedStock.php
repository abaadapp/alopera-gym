<?php

namespace App\Modules\Inventory\Listeners;

use App\Modules\Finance\Events\InvoiceVoided;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;

/**
 * A manual invoice that sold catalogue products took them off the shelf when it was issued; voided (it was never paid),
 * they go back to the branch they left, as its movements recorded. Runs inside the void's own transaction. A credit
 * note is not a return: it changes what is owed, not the shelf (a returned item is put back by a stock adjustment).
 */
final class ReturnInvoicedStock
{
    public function __construct(private readonly StockService $stock) {}

    public function handle(InvoiceVoided $event): void
    {
        $invoice = $event->invoice;
        $taken = StockMovement::where('reference_type', $invoice->getMorphClass())->where('reference_id', $invoice->id)
            ->selectRaw('product_id, branch_id, sum(quantity) as quantity')->groupBy('product_id', 'branch_id')->orderBy('product_id')->get();

        foreach ($taken as $row) {
            if ((float) $row->quantity < 0) {
                $this->stock->move(Product::withTrashed()->findOrFail($row->product_id), -(float) $row->quantity, 'invoice_void', $invoice, 'إلغاء الفاتورة '.$invoice->number, branchId: $row->branch_id);
            }
        }
    }
}
