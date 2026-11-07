<?php

namespace Tests\Feature;

use App\Core\Papers\Paper;
use App\Core\Papers\PaperIssuer;
use App\Core\Papers\Papers;
use App\Core\Papers\PaperSize;
use App\Core\Papers\PaperTypes;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A short paper on a cut sheet keeps its last lines on its page: the room between sections is closer on A4 and closest
 * on A5 (the type, its line height and the rows stay as they were). One that still does not fit runs onto more pages,
 * whole, never cut. The thermal strips keep their own spacing.
 */
class PaperFitTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function invoice(Tenant $club, string $size, int $lines): Paper
    {
        return $this->asTenant($club, function () use ($club, $size, $lines) {
            $tpl = PaperTypes::settings($club, 'invoice');
            $doc = Papers::builder('invoice')::sample($club, $tpl);
            $doc['table']['rows'] = array_slice(array_merge(...array_fill(0, $lines, $doc['table']['rows'])), 0, $lines);

            return new Paper($club, 'invoice', $doc, $tpl, PaperIssuer::resolve($club), $size, 'sample');
        });
    }

    private function pages(Paper $paper): int
    {
        return preg_match_all('#/Type /Page\b(?!s)#', $paper->pdf()->getContent());
    }

    public function test_a_short_a4_invoice_with_all_its_panels_keeps_its_close_on_its_one_page(): void
    {
        // One line, the totals, payments and instructions: before, the thanks went alone onto a second page.
        $this->assertSame(1, $this->pages($this->invoice($this->club(), PaperSize::A4, 1)));
    }

    public function test_an_a5_invoice_takes_no_more_pages_than_it_needs_and_a_long_one_still_runs_on(): void
    {
        $club = $this->club();
        // Five lines on A5 took three pages; it now takes two. Twelve lines still need three: they run on, whole.
        $this->assertSame(2, $this->pages($this->invoice($club, PaperSize::A5, 5)));
        $this->assertSame(3, $this->pages($this->invoice($club, PaperSize::A5, 12)));
    }

    public function test_the_closer_room_is_for_cut_sheets_only(): void
    {
        $club = $this->club();
        $css = fn (string $size) => $this->invoice($club, $size, 1)->html();

        $this->assertStringContainsString('table.sign { width: 100%; margin-top: 16.5pt;', $css(PaperSize::A4));
        $this->assertStringContainsString('table.sign { width: 100%; margin-top: 11pt;', $css(PaperSize::A5));
        foreach ([PaperSize::T80, PaperSize::T58] as $strip) {
            $this->assertStringContainsString('table.sign { width: 100%; margin-top: 22pt;', $css($strip), $strip);
            $this->assertStringContainsString('table.figure { margin-top: 13pt; margin-bottom: 11pt;', $css($strip), $strip);
        }
        // The type is the same size on every sheet.
        $this->assertSame(substr_count($css(PaperSize::A4), 'font-size: 12pt'), substr_count($css(PaperSize::A5), 'font-size: 12pt'));
    }
}
