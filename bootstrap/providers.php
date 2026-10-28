<?php

use App\Modules\Accounting\AccountingServiceProvider;
use App\Modules\Finance\FinanceServiceProvider;
use App\Modules\Gym\GymServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Messaging\MessagingServiceProvider;
use App\Modules\Payments\PaymentsServiceProvider;
use App\Modules\Payroll\PayrollServiceProvider;
use App\Modules\Website\WebsiteServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AccountingServiceProvider::class,
    FinanceServiceProvider::class,
    GymServiceProvider::class,
    InventoryServiceProvider::class,
    MessagingServiceProvider::class,
    PaymentsServiceProvider::class,
    PayrollServiceProvider::class,
    WebsiteServiceProvider::class,
];
