<?php

namespace Tests\Feature;

use App\Core\Branding\OfficialLogo;
use App\Core\Papers\Paper;
use App\Core\Papers\PaperIssuer;
use App\Core\Papers\Papers;
use App\Core\Papers\PaperSize;
use App\Core\Papers\PaperTypes;
use App\Models\Tenant;
use App\Modules\Reports\Support\ReportPdf;
use App\Modules\Reports\Support\ReportSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The owner's logo file is the only logo there is: unchanged in public/brand, at its own proportions everywhere, once on
 * a paper however long, black on a receipt, and out of reach of an old upload. A paper reads the same on the print page
 * and in the PDF: the same sides, and the closing lines at the foot of one page.
 */
class OfficialLogoTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const ORIGINAL_SHA256 = '68ea2cb5a7a781c29003025312df03733f4f6b4aee0b8356d019d5920d319cc8';

    /** @return list<int> how many times the PDF's pages draw an image */
    private function drawn(string $pdf): array
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $pages = [];
        foreach ($streams[1] as $stream) {
            $text = @gzuncompress($stream);
            if ($text !== false && str_contains($text, ' Tf')) {
                $pages[] = preg_match_all('#/I\d+ Do#', $text);
            }
        }

        return $pages;
    }

    /** @param  array<string, string>  $attributes */
    private function gym(array $attributes): Tenant
    {
        $club = $this->club();
        $club->forceFill($attributes)->save();

        return $club;
    }

    private function paper(Tenant $club, string $size, int $copies = 1): Paper
    {
        return $this->asTenant($club, function () use ($club, $size, $copies) {
            $tpl = PaperTypes::settings($club, 'invoice');
            $doc = Papers::builder('invoice')::sample($club, $tpl);
            $doc['table']['rows'] = array_merge(...array_fill(0, $copies, $doc['table']['rows']));

            return new Paper($club, 'invoice', $doc, $tpl, PaperIssuer::resolve($club), $size, 'sample');
        });
    }

    public function test_the_file_is_the_one_the_owner_approved_with_its_transparency(): void
    {
        $path = public_path(OfficialLogo::ORIGINAL);

        $this->assertSame(self::ORIGINAL_SHA256, hash_file('sha256', $path));
        $this->assertSame([1448, 1086], array_slice(getimagesize($path), 0, 2));
        $image = imagecreatefrompng($path);
        $this->assertSame(127, imagecolorat($image, 0, 0) >> 24 & 0x7F);
    }

    public function test_every_size_made_from_it_keeps_its_proportions(): void
    {
        foreach ([OfficialLogo::SCREEN, ...array_values(OfficialLogo::STRIPS)] as $file) {
            [$width, $height] = getimagesize(public_path($file));
            $this->assertEqualsWithDelta(1448 / 1086, $width / $height, 0.01, $file);
        }
        foreach (PaperSize::PRESETS as $size => $preset) {
            $box = OfficialLogo::box($size);
            $this->assertEqualsWithDelta(1448 / 1086, $box['width'] / $box['height'], 0.01, $size);
        }
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));
    }

    public function test_an_old_upload_reaches_neither_a_screen_nor_a_paper(): void
    {
        $club = $this->gym(['logo_path' => 'tenants/1/branding/old-logo.webp']);

        $this->assertStringEndsWith(OfficialLogo::SCREEN, $club->logoUrl());
        $html = $this->paper($club, PaperSize::A4)->html();
        $this->assertStringNotContainsString('old-logo', $html);
        $this->assertStringContainsString(OfficialLogo::ORIGINAL, $html);
    }

    /** @return array<string, array{string, string}> */
    public static function papers(): array
    {
        return [
            'A4' => [PaperSize::A4, OfficialLogo::ORIGINAL],
            'A5' => [PaperSize::A5, OfficialLogo::ORIGINAL],
            '80mm' => [PaperSize::T80, OfficialLogo::STRIPS[PaperSize::T80]],
            '58mm' => [PaperSize::T58, OfficialLogo::STRIPS[PaperSize::T58]],
        ];
    }

    #[DataProvider('papers')]
    public function test_a_paper_draws_the_right_file_once(string $size, string $file): void
    {
        $paper = $this->paper($this->club(), $size);
        $html = $paper->html();

        $this->assertSame(1, substr_count($html, '<img'));
        $this->assertStringContainsString($file, $html);
        $box = OfficialLogo::box($size);
        $this->assertStringContainsString("width:{$box['width']}pt; height:{$box['height']}pt", $html);
        $this->assertSame([1], array_values(array_filter($this->drawn($paper->pdf()->getContent()))));
    }

    public function test_a_long_paper_keeps_the_logo_on_its_first_page_only(): void
    {
        $pages = $this->drawn($this->paper($this->club(), PaperSize::A4, 25)->pdf()->getContent());

        $this->assertGreaterThan(1, count($pages));
        $this->assertSame(1, $pages[0]);
        $this->assertSame(1, array_sum($pages));
    }

    public function test_a_paper_can_leave_the_logo_out(): void
    {
        $club = $this->club();
        $paper = $this->paper($club, PaperSize::A4);
        $bare = new Paper($club, 'invoice', $paper->doc, ['show_logo' => false] + $paper->tpl, $paper->issuer, PaperSize::A4, 'sample');

        $this->assertStringNotContainsString('<img', $bare->html());
    }

    public function test_a_paper_is_drawn_in_the_logos_gold_whatever_colour_the_website_has(): void
    {
        $html = $this->paper($this->gym(['primary_color' => '#9d174d']), PaperSize::A4)->html();

        $this->assertStringContainsString('background: '.OfficialLogo::COLOR, $html);
        $this->assertStringNotContainsString('#9d174d', $html);
    }

    public function test_a_report_pdf_carries_the_logo_once_on_its_first_page(): void
    {
        $rows = array_fill(0, 200, ['سطر', 1.5]);
        $sheet = new ReportSheet('الأرقام', [['البيان', ReportSheet::TEXT], ['المبلغ', ReportSheet::MONEY]], $rows);
        $pages = $this->drawn((new ReportPdf('تقرير', [['الفترة', 'من 2026-11-01 إلى 2026-11-04']], [$sheet], 'OMR', 'Alopera Gym'))->bytes());

        $this->assertGreaterThan(1, count($pages));
        $this->assertSame(1, $pages[0]);
        $this->assertSame(1, array_sum($pages));
    }

    public function test_the_website_page_does_not_take_a_logo(): void
    {
        $club = $this->gym(['logo_path' => 'tenants/1/branding/kept.webp']);
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.website.branding.update'), [
            'primary_color' => '#111111', 'secondary_color' => '#222222', 'logo' => UploadedFile::fake()->image('new.png'),
        ])->assertRedirect();

        $club->refresh();
        $this->assertSame('#111111', $club->primary_color);
        $this->assertNull($club->setting('logo_file'));
    }

    /** As the reference's: the owner replaces the logo in the club's settings, and can go back to the approved one. */
    public function test_the_owner_replaces_the_logo_in_the_settings_and_restores_the_official_one(): void
    {
        Storage::fake('public');
        $club = $this->gym(['logo_path' => 'tenants/1/branding/old-logo.webp']);
        $owner = $this->owner($club);
        $form = fn (array $extra) => ['name' => $club->name, 'primary_color' => '#9d174d', 'secondary_color' => '#f9a8d4', '_method' => 'put'] + $extra;

        $this->actingAs($owner)->post(route('club.settings.update'), $form(['logo' => UploadedFile::fake()->image('wide.png', 800, 200)]))->assertSessionHasNoErrors();
        $club->refresh();
        $file = $club->setting('logo_file');
        $this->assertNotNull($file);
        Storage::disk('public')->assertExists($file);
        $this->assertStringEndsWith('.png', $file);
        $this->assertStringContainsString($file, $club->logoUrl());

        $paper = $this->paper($club, PaperSize::A4);
        $html = $paper->html();
        $this->assertSame(1, substr_count($html, '<img'));
        $this->assertStringContainsString($file, $html);
        $this->assertStringNotContainsString(OfficialLogo::ORIGINAL, $html);
        // a wide logo keeps its proportions inside the room it has
        $box = OfficialLogo::box(PaperSize::A4, $club);
        $this->assertEqualsWithDelta(4.0, $box['width'] / $box['height'], 0.02);
        $this->assertLessThanOrEqual(140.0, $box['width']);
        $this->assertSame([1], array_values(array_filter($this->drawn($paper->pdf()->getContent()))));

        $this->actingAs($owner)->put(route('club.settings.update'), $form(['remove_logo' => true]))->assertSessionHasNoErrors();
        $club->refresh();
        $this->assertNull($club->setting('logo_file'));
        Storage::disk('public')->assertMissing($file);
        $this->assertStringEndsWith(OfficialLogo::SCREEN, $club->logoUrl());
    }

    public function test_the_settings_refuse_a_file_that_is_not_an_image(): void
    {
        $club = $this->club();

        $this->actingAs($this->owner($club))->post(route('club.settings.update'), [
            'name' => $club->name, 'primary_color' => '#9d174d', 'secondary_color' => '#f9a8d4', '_method' => 'put', 'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('logo');
        $this->assertNull($club->refresh()->setting('logo_file'));
    }

    public function test_the_doors_carry_the_logo_and_its_icons(): void
    {
        $response = $this->get(route('login'))->assertOk();

        $response->assertSee('brand/alopera-gym-icon-64.png', false)->assertSee('brand/alopera-gym-icon-180.png', false);
        $response->assertInertia(fn ($page) => $page->where('logo', OfficialLogo::url())->has('year'));
    }

    /** The fields in the middle of a sheet read from the paper's start; only the club and the figure sit at its end. */
    public function test_each_block_of_a_sheet_keeps_its_side(): void
    {
        $html = $this->paper($this->club(), PaperSize::A4)->html();

        $this->assertSame(2, preg_match_all('/<table class="fields fieldsend[ "]/', $html));
        $this->assertStringNotContainsString('class="fields end', $html);
    }

    /** The bug: a browser saving ink greyed the text and dropped the gold line, and the print left the PDF. */
    public function test_the_print_page_keeps_its_colours_and_a_page_of_room(): void
    {
        $html = $this->paper($this->club(), PaperSize::A4)->html();

        $this->assertStringContainsString('print-color-adjust: exact', $html);
        $this->assertStringContainsString('@page { size: 210mm 297mm; margin: 14mm 13mm 11mm; }', $html);
        $this->assertStringContainsString('min-height: 270mm', $html);
    }

    public function test_a_short_paper_is_one_page_with_its_close_at_the_foot(): void
    {
        $club = $this->club();
        $paper = $this->paper($club, PaperSize::A4);
        $short = new Paper($club, 'invoice', ['panels' => []] + $paper->doc, $paper->tpl, $paper->issuer, PaperSize::A4, 'sample');

        $this->assertSame(1, preg_match_all('#/Type /Page\b(?!s)#', $short->pdf()->getContent()));
    }
}
