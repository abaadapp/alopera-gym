<?php

namespace App\Console\Commands;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/** Runs a scheduled job once per club record, isolating failures. */
abstract class ForEachTenant extends Command
{
    abstract protected function handleTenant(Tenant $tenant): string;

    public function handle(TenantContext $tenants): int
    {
        $failed = 0;
        $query = Tenant::query();
        if ($slug = $this->option('tenant')) {
            $query->where('slug', $slug);
        }

        foreach ($query->cursor() as $tenant) {
            try {
                $summary = $tenants->run($tenant, fn () => $this->handleTenant($tenant));
                $this->line("[{$tenant->slug}] {$summary}");
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("[{$tenant->slug}] ".$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
