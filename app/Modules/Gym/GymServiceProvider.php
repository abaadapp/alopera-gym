<?php

namespace App\Modules\Gym;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Finance\Events\InvoicePaid;
use App\Modules\Finance\Events\InvoiceVoided;
use App\Modules\Gym\Listeners\ActivatePaidItems;
use Illuminate\Support\Facades\Event;

class GymServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        Event::listen(InvoicePaid::class, [ActivatePaidItems::class, 'handlePaid']);
        Event::listen(InvoiceVoided::class, [ActivatePaidItems::class, 'handleVoided']);
    }
}
