<?php

namespace Tests\Feature;

use App\Core\Backup\TenantBackupService;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\Expense;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\PosService;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The screens' own files (members, expenses, sales, a backup archive): each carries its club only, the user's branch
 * only when tied to one, refuses whoever lacks the permission, and never leaves rows out without saying so.
 */
class ScreenExportsIsolationTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array{0: Tenant, 1: User, 2: Branch} */
    private function marked(string $mark): array
    {
        $club = $this->club('نادي '.$mark);
        $owner = $this->owner($club);
        $second = $this->asTenant($club, fn () => Branch::create(['name' => 'فرع '.$mark, 'is_active' => true]));
        $this->member($club, ['full_name' => 'عضو '.$mark]);
        $this->asTenant($club, function () use ($mark, $second) {
            Expense::create(['number' => 'EXP-M-'.$mark, 'description' => 'مصروف رئيسي '.$mark, 'amount' => '10.000', 'expense_date' => today(), 'payment_method' => 'cash']);
            Expense::create(['number' => 'EXP-B-'.$mark, 'description' => 'مصروف الفرع '.$mark, 'amount' => '20.000', 'expense_date' => today(), 'payment_method' => 'cash', 'branch_id' => $second->id]);
            $product = Product::create(['name' => 'منتج '.$mark, 'price' => 2, 'track_stock' => true]);
            app(StockService::class)->move($product, 50, 'adjustment', null, 'opening', 1);
        });

        return [$club, $owner, $second];
    }

    private function sell(Tenant $club, User $owner, string $mark, int $times = 1): void
    {
        $this->actingAs($owner);
        $product = $this->asTenant($club, fn () => Product::where('name', 'منتج '.$mark)->value('id'));
        for ($i = 0; $i < $times; $i++) {
            $this->asTenant($club, fn () => app(PosService::class)->checkout([['product_id' => $product, 'quantity' => 1]], 'cash'));
        }
    }

    private function file(User $user, string $route, array $query = []): string
    {
        return $this->actingAs($user)->get(route($route, $query + ['format' => 'csv']))->assertOk()->streamedContent();
    }

    public function test_the_members_expenses_and_sales_files_carry_their_club_only(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$a, $ownerA] = $this->marked('ألف');
        [$b, $ownerB] = $this->marked('باء');
        $this->sell($a, $ownerA, 'ألف');
        $this->sell($b, $ownerB, 'باء');

        foreach (['club.members.export' => 'عضو ألف', 'club.expenses.export' => 'مصروف رئيسي ألف', 'club.sales.export' => 'منتج ألف'] as $route => $ours) {
            $csv = $this->file($ownerA, $route);
            $this->assertStringContainsString($ours, $csv, $route);
            $this->assertStringNotContainsString('باء', $csv, "{$route} carries the other club");
        }
    }

    public function test_a_branch_users_expenses_file_is_their_branch_only(): void
    {
        [$club, , $second] = $this->marked('ألف');
        $finance = $this->staff($club, 'finance');
        $finance->forceFill(['branch_id' => $second->id])->save();

        $csv = $this->file($finance->fresh(), 'club.expenses.export');
        $this->assertStringContainsString('مصروف الفرع ألف', $csv);
        $this->assertStringNotContainsString('مصروف رئيسي ألف', $csv, 'never the main branch\'s');
    }

    public function test_the_files_refuse_whoever_lacks_the_permission(): void
    {
        [$club] = $this->marked('ألف');
        $trainer = $this->staff($club, 'trainer');
        foreach (['club.members.export', 'club.expenses.export', 'club.sales.export'] as $route) {
            $this->actingAs($trainer)->get(route($route, ['format' => 'csv']))->assertForbidden();
        }
        $this->actingAs($trainer)->get(route('club.backups.download', 'backup-20261116-030000.zip'))->assertForbidden();
    }

    public function test_a_club_downloads_its_own_backup_archives_never_another_clubs(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$a, $ownerA] = $this->marked('ألف');
        [$b] = $this->marked('باء');
        $theirs = app(TenantBackupService::class)->create($b);

        $this->actingAs($ownerA)->get(route('club.backups.download', $theirs))->assertNotFound();
        $ours = app(TenantBackupService::class)->create($a);
        $this->actingAs($ownerA)->get(route('club.backups.download', $ours))->assertOk();
    }

    public function test_a_sales_file_cut_at_its_limit_says_so(): void
    {
        [$club, $owner] = $this->marked('ألف');
        config(['sales.export_limit' => 3]);
        $this->sell($club, $owner, 'ألف', 4);

        $response = $this->actingAs($owner)->get(route('club.sales.export', ['format' => 'csv']))->assertOk();
        $csv = $response->streamedContent();
        $this->assertSame(3, substr_count($csv, 'منتج ألف'), 'the newest three');
        $this->assertStringContainsString('أحدث 3 عملية من 4', $csv, 'and the file says some are left out');
        $response->assertHeader('x-export-truncated', '1');
    }
}
