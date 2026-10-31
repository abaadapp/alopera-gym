<?php

namespace App\Modules\Inventory;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Finance\Events\InvoicePaid;
use App\Modules\Finance\Events\InvoiceVoided;
use App\Modules\Inventory\Console\CheckStock;
use App\Modules\Inventory\Console\ExpireOrders;
use App\Modules\Inventory\Listeners\SettleWebsiteOrders;
use Illuminate\Support\Facades\Event;

class InventoryServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        Event::listen(InvoicePaid::class, [SettleWebsiteOrders::class, 'handlePaid']);
        Event::listen(InvoiceVoided::class, [SettleWebsiteOrders::class, 'handleVoided']);

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireOrders::class, CheckStock::class]);
        }
    }
}
