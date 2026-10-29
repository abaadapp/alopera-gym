<?php

namespace App\Modules\Documents\Console;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Documents\Services\DocumentService;
use Illuminate\Console\Command;

/** Raises today's document expiry alerts for every club. Safe to run again: an alert is never raised twice. */
class RaiseDocumentAlerts extends Command
{
    protected $signature = 'documents:alerts {--club= : only this club (slug)}';

    protected $description = 'Raise expiry alerts for business documents (once per threshold)';

    public function handle(TenantContext $tenants, DocumentService $documents): int
    {
        $clubs = Tenant::query()->when($this->option('club'), fn ($q, $slug) => $q->where('slug', $slug))->orderBy('id')->get();
        foreach ($clubs as $club) {
            $count = $tenants->run($club, fn () => $documents->raiseAlerts());
            if ($count > 0) {
                $this->line("{$club->slug}: {$count} alert(s)");
            }
        }

        return self::SUCCESS;
    }
}
