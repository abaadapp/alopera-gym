<?php

namespace App\Modules\Website;

use App\Core\Support\ModuleServiceProvider;

class WebsiteServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }
}
