<?php

namespace App\Modules\Payroll;

use App\Core\Support\ModuleServiceProvider;

class PayrollServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }
}
