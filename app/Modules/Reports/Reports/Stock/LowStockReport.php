<?php

namespace App\Modules\Reports\Reports\Stock;

/** المنتجات منخفضة المخزون: the balances report, only what is at or below its reorder level, with a suggested quantity. */
class LowStockReport extends StockBalanceReport
{
    public function __construct()
    {
        parent::__construct(true);
    }
}
