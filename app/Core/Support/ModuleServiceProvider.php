<?php

namespace App\Core\Support;

use Illuminate\Support\ServiceProvider;

/**
 * Base provider for a self-contained module under app/Modules/<Name>.
 *
 * Each module ships its own migrations and route file so it can be lifted into
 * another Laravel application (ABAAD) by copying the folder and registering
 * its provider.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    abstract protected function modulePath(): string;

    public function boot(): void
    {
        $migrations = $this->modulePath().'/Database/migrations';
        if (is_dir($migrations)) {
            $this->loadMigrationsFrom($migrations);
        }

        $this->bootModule();
    }

    protected function bootModule(): void {}
}
