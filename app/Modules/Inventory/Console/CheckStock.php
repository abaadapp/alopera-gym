<?php

namespace App\Modules\Inventory\Console;

use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verifies the stock per branch, read-only: every product's branches add up to its club total, and each branch's
 * quantity equals the sum of its movements (a movement without a branch is the main branch's). Lists what differs;
 * fixes nothing.
 */
class CheckStock extends Command
{
    protected $signature = 'inventory:check {--club= : one club slug}';

    protected $description = 'Check that branch stock adds up to product totals and matches the stock movements';

    public function handle(TenantContext $tenants, BranchContext $branches): int
    {
        $problems = 0;
        $clubs = Tenant::query()->when($this->option('club'), fn ($q, $slug) => $q->where('slug', $slug))->orderBy('id')->get();

        foreach ($clubs as $club) {
            $tenants->run($club, function () use ($club, $branches, &$problems) {
                $main = $branches->mainId();
                $totals = DB::table('products')->where('tenant_id', $club->id)->pluck('stock_quantity', 'id');
                $byBranch = DB::table('product_stocks')->where('tenant_id', $club->id)->get(['product_id', 'branch_id', 'quantity']);
                $main = (int) $main; // an integer, written into the SQL so select and group by are the same expression
                $moved = DB::table('stock_movements')->where('tenant_id', $club->id)
                    ->selectRaw("product_id, coalesce(branch_id, {$main}) as branch_id, sum(quantity) as quantity")
                    ->groupByRaw("product_id, coalesce(branch_id, {$main})")->get()
                    ->keyBy(fn ($r) => $r->product_id.':'.$r->branch_id);

                $sum = [];
                foreach ($byBranch as $row) {
                    $sum[$row->product_id] = round(($sum[$row->product_id] ?? 0) + (float) $row->quantity, 3);
                    $movements = round((float) ($moved[$row->product_id.':'.$row->branch_id]->quantity ?? 0), 3);
                    if (abs($movements - (float) $row->quantity) > 0.0005) {
                        $problems++;
                        $this->warn("{$club->slug}: product {$row->product_id} branch {$row->branch_id}: shelf {$row->quantity}, movements {$movements}");
                    }
                }
                foreach ($totals as $id => $total) {
                    if (abs(($sum[$id] ?? 0) - (float) $total) > 0.0005) {
                        $problems++;
                        $this->warn("{$club->slug}: product {$id}: total {$total}, branches ".($sum[$id] ?? 0));
                    }
                }
                $this->line("{$club->slug}: ".count($totals).' products, '.count($byBranch).' branch rows checked');
            });
        }

        $problems ? $this->error("{$problems} difference(s) found") : $this->info('Stock per branch adds up and matches the movements.');

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
