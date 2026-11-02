<?php

namespace Tests\Feature;

use App\Core\Papers\Paper;
use App\Core\Papers\PaperIssuer;
use App\Core\Papers\PaperTypes;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The thermal strip as a PDF: one page as long as the receipt at 58 or 80 mm, pages of two metres beyond that (fed as
 * one strip by the club's app), in the strip's own palette. With PRINT_FIXTURES=1 it also writes the receipts the apps'
 * long-receipt tests print end to end (mobile/shared/fixtures/receipts).
 */
class ThermalStripPdfTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const MM = 72 / 25.4;

    /** @return list<array{width: float, height: float}> the pages' sizes in millimetres */
    private function pages(string $pdf): array
    {
        // Each page object carries its MediaBox; the page tree repeats the first one, so pages are counted by their type.
        $count = preg_match_all('#/Type\s*/Page[^s]#', $pdf);
        preg_match_all('#/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]#', $pdf, $m, PREG_SET_ORDER);
        $boxes = array_slice($m, count($m) - $count);

        return array_map(fn ($b) => ['width' => (float) $b[1] / self::MM, 'height' => (float) $b[2] / self::MM], $boxes);
    }

    private function receipt(Tenant $club, int $mm, int $items): string
    {
        return app(TenantContext::class)->run($club, function () use ($club, $mm, $items) {
            $rows = [];
            for ($i = 1; $i <= $items; $i++) {
                $rows[] = ['name' => "Item {$i} Premium Protein Bar", 'notes' => ['إضافة: شوفان ومكسرات'], 'qty' => '2', 'unit' => '1.250', 'total' => '2.500'];
            }
            $doc = [
                'title' => 'فاتورة بيع', 'number' => "POS-{$mm}-{$items}", 'status' => null,
                'meta' => [['label' => 'رقم العملية', 'value' => "POS-{$mm}-{$items}", 'ltr' => true], ['label' => 'الكاشير', 'value' => 'موظف الاستقبال']],
                'parties' => [], 'table' => ['rows' => $rows, 'empty' => '', 'count' => true],
                'totals' => [['label' => 'المجموع الفرعي', 'value' => number_format($items * 2.5, 3, '.', '')], ['label' => 'الإجمالي', 'value' => number_format($items * 2.5, 3, '.', ''), 'currency' => 'ر.ع', 'grand' => true]],
                'panels' => [], 'signatures' => [],
            ];
            $tpl = ['footer' => "شكرًا لزيارتكم\nEND OF RECEIPT"] + PaperTypes::settings($club, 'sale');

            return (new Paper($club, 'sale', $doc, $tpl, PaperIssuer::resolve($club), "{$mm}mm", "receipt-{$mm}mm-{$items}"))->pdf()->getContent();
        });
    }

    public function test_a_short_receipt_is_one_page_exactly_as_wide_as_its_paper_and_as_tall_as_its_content(): void
    {
        $club = $this->club();
        foreach ([58, 80] as $mm) {
            $pdf = $this->receipt($club, $mm, 3);
            $this->assertStringStartsWith('%PDF', $pdf);
            $pages = $this->pages($pdf);
            $this->assertCount(1, $pages, "{$mm}mm");
            $this->assertEqualsWithDelta($mm, $pages[0]['width'], 0.2);
            $this->assertGreaterThanOrEqual(60, $pages[0]['height'], 'never shorter than a strip one can hold');
            $this->assertLessThan(200, $pages[0]['height'], 'as tall as its content, not an A4 page');
        }
        // More lines, a longer strip.
        $this->assertGreaterThan($this->pages($this->receipt($club, 80, 3))[0]['height'] + 50, $this->pages($this->receipt($club, 80, 20))[0]['height']);
    }

    public function test_a_receipt_longer_than_two_metres_is_pages_of_two_metres_at_most(): void
    {
        $club = $this->club();
        $pages = $this->pages($this->receipt($club, 58, 1000));
        $this->assertGreaterThanOrEqual(2, count($pages));
        foreach ($pages as $page) {
            $this->assertEqualsWithDelta(58, $page['width'], 0.2);
            $this->assertLessThanOrEqual(2000.5, $page['height']);
        }
    }

    public function test_the_strip_is_printed_in_a_palette_a_thermal_printer_keeps(): void
    {
        $club = $this->club();
        $html = app(TenantContext::class)->run($club, fn () => (new Paper($club, 'sale', ['title' => 'x', 'number' => '1', 'meta' => [], 'parties' => [], 'table' => null, 'totals' => [], 'panels' => []], PaperTypes::settings($club, 'sale'), PaperIssuer::resolve($club), '80mm', 'x'))->html());
        // Labels and dashed rules dark enough to stay black at the app's threshold (brightness under 160).
        $this->assertStringContainsString('#4b5563', $html);
        $this->assertStringContainsString('#6b7280', $html);
        $this->assertStringNotContainsString('#cbd5e1', $html);
    }

    public function test_it_writes_the_apps_long_receipt_fixtures_when_asked(): void
    {
        if (! getenv('PRINT_FIXTURES')) {
            $this->markTestSkipped('PRINT_FIXTURES=1 writes mobile/shared/fixtures/receipts.');
        }
        $club = $this->club();
        $dir = base_path('mobile/shared/fixtures/receipts');
        foreach ([58, 80] as $mm) {
            foreach ([1, 50, 100, 200, 1000] as $items) {
                file_put_contents("{$dir}/receipt-{$mm}mm-{$items}.pdf", $this->receipt($club, $mm, $items));
            }
        }
        $this->assertFileExists("{$dir}/receipt-80mm-1000.pdf");
    }
}
