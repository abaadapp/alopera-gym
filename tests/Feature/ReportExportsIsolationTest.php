<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\PosService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Reports\Services\ReportCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Every report's file, in every format, carries its own club only: two clubs with the same kinds of records (members,
 * invoices, payments, expenses, products, sales, check-ins), each named so it can be told apart, and not one of the
 * other club's names or amounts in any file. A large file is written a row at a time, in bounded memory.
 */
class ReportExportsIsolationTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** A club with one of everything, its texts and amounts marked by $mark (an amount no other record has). */
    private function busy(string $mark, string $amount): array
    {
        $club = $this->club('نادي '.$mark);
        $owner = $this->owner($club);
        $this->fundAccounts($club);
        $this->asTenant($club, fn () => Branch::create(['name' => 'فرع '.$mark, 'is_active' => true])); // every report (the branches one needs two)
        $member = $this->member($club, ['full_name' => 'عضو '.$mark]);
        $this->activeMembership($club, $member, $this->plan($club, ['name' => 'باقة '.$mark, 'price' => $amount]));
        $this->asTenant($club, function () use ($member, $mark, $amount) {
            $invoice = app(InvoiceService::class)->issue($member, [['description' => 'خدمة '.$mark, 'quantity' => 1, 'unit_price' => $amount]], ['type' => 'other']);
            app(PaymentService::class)->record($invoice, $amount, 'cash');
            Expense::create(['number' => 'EXP-'.$mark, 'description' => 'مصروف '.$mark, 'amount' => $amount, 'expense_date' => today(), 'payment_method' => 'cash']);
            CheckIn::create(['member_id' => $member->id, 'method' => 'qr', 'direction' => 'in', 'result' => 'allowed', 'checked_in_at' => now()->startOfDay()->addHours(8)]);
            $product = Product::create(['name' => 'منتج '.$mark, 'price' => 2, 'track_stock' => true]);
            app(StockService::class)->move($product, 10, 'adjustment', null, 'opening', 1);
        });
        $this->actingAs($owner);
        $this->asTenant($club, fn () => app(PosService::class)->checkout([['product_id' => $this->asTenant($club, fn () => Product::where('name', 'منتج '.$mark)->value('id')), 'quantity' => 1]], 'cash'));

        return [$club, $owner];
    }

    public function test_no_report_file_of_one_club_carries_anything_of_another(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        [$a, $ownerA] = $this->busy('ألف', '111.111');
        [$b] = $this->busy('باء', '777.777');

        $checked = 0;
        foreach (ReportCatalog::keys() as $report) {
            foreach (['csv', 'xlsx'] as $format) {
                $response = $this->actingAs($ownerA)->get(route('club.reports.export', ['report' => $report, 'format' => $format]));
                $this->assertSame(200, $response->getStatusCode(), "{$report}.{$format}");
                $content = $format === 'csv' ? $response->streamedContent() : $this->xlsxText($response->streamedContent());
                foreach (['باء', '777.777', '777.78', '1555.554'] as $theirs) {
                    $this->assertStringNotContainsString($theirs, $content, "{$report}.{$format} carries the other club's «{$theirs}»");
                }
                $checked++;
            }
        }
        $this->assertSame(count(ReportCatalog::keys()) * 2, $checked);
        // And the files do carry this club's own records (the check above is not passing on empty files).
        $finance = $this->actingAs($ownerA)->get(route('club.reports.export', ['report' => 'finance', 'format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('111.111', $finance);
        $this->assertStringContainsString('عضو ألف', $this->actingAs($ownerA)->get(route('club.reports.export', ['report' => 'members', 'format' => 'csv']))->streamedContent());
        $this->assertNotNull($b);
    }

    public function test_a_large_report_file_is_written_in_bounded_memory(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);
        $rows = [];
        $start = now()->startOfMonth();
        for ($i = 0; $i < 20000; $i++) {
            $rows[] = ['tenant_id' => $club->id, 'member_id' => $member->id, 'method' => 'qr', 'direction' => 'in', 'result' => 'allowed', 'checked_in_at' => $start->copy()->addMinutes($i % 40000), 'created_at' => now(), 'updated_at' => now()];
            if (count($rows) === 1000) {
                DB::table('gym_check_ins')->insert($rows);
                $rows = [];
            }
        }

        $base = memory_get_usage();
        memory_reset_peak_usage();
        $csv = $this->actingAs($owner)->get(route('club.reports.export', ['report' => 'checkins', 'format' => 'csv', 'preset' => 'month']))->assertOk()->streamedContent();
        $peak = memory_get_peak_usage() - $base - strlen($csv);

        $this->assertGreaterThan(20000, substr_count($csv, "\n"), 'every check-in is in the file');
        fwrite(STDERR, sprintf("\n[memory] checkins csv of 20000 rows: %.1f MB beyond the file itself\n", $peak / 1048576));
        $this->assertLessThan(64 * 1048576, $peak, 'written a row at a time');
    }

    /** The text of an xlsx file (its shared strings and sheets), to search it as the CSV is searched. */
    private function xlsxText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'a real xlsx file');
        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_ends_with((string) $zip->getNameIndex($i), '.xml')) {
                $text .= $zip->getFromIndex($i);
            }
        }
        $zip->close();
        @unlink($path);

        return $text;
    }
}
