<?php

namespace App\Modules\Documents;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Documents\Console\RaiseDocumentAlerts;

class DocumentsServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RaiseDocumentAlerts::class]);
        }
    }
}
