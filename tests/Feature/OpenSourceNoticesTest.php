<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * libheif-js (LGPL-3.0) goes to every browser that opens the club panel, so its licence, notice and Corresponding
 * Source are served beside it (public/licenses) and the sign-in page links them. These stay in step with the version
 * installed: upgrading the library without its notice and source fails here.
 */
class OpenSourceNoticesTest extends TestCase
{
    public function test_the_licence_texts_are_the_upstream_ones_byte_for_byte(): void
    {
        // The SHA-256 of the LICENSE asset of libheif-emscripten v1.23.5 (GNU LGPL-3.0 + GNU GPL-3.0 + MIT texts).
        $this->assertSame('fa81ce652315b013359d6e8e4744335f31a50c7c192907176d3632f78a3b4596', hash_file('sha256', public_path('licenses/libheif-js/LICENSE.txt')));
    }

    public function test_every_source_archive_is_kept_here_with_its_checksum_and_listed_on_the_page(): void
    {
        $page = (string) file_get_contents(public_path('licenses/index.html'));
        $lines = array_filter(explode("\n", (string) file_get_contents(public_path('licenses/libheif-js/source/SHA256SUMS.txt'))));
        $this->assertCount(4, $lines);
        foreach ($lines as $line) {
            [$sha, $file] = preg_split('/\s+/', trim($line), 2);
            $this->assertSame($sha, hash_file('sha256', public_path('licenses/libheif-js/source/'.$file)), $file);
            $this->assertStringContainsString('href="libheif-js/source/'.$file.'"', $page);
            $this->assertStringContainsString($sha, $page);
        }
        $this->assertStringContainsString('href="libheif-js/LICENSE.txt"', $page);
        $this->assertStringContainsString('href="libheif-js/NOTICE.txt"', $page);
    }

    public function test_the_notice_names_the_version_that_is_installed(): void
    {
        $version = json_decode((string) file_get_contents(base_path('package.json')), true)['dependencies']['libheif-js'] ?? null;
        $this->assertSame('1.23.5', $version, 'pinned exactly; a new version needs its own notice and source here');
        foreach (['licenses/index.html', 'licenses/libheif-js/NOTICE.txt'] as $file) {
            $this->assertStringContainsString('libheif-js '.$version, (string) file_get_contents(public_path($file)));
        }
        $this->assertFileExists(public_path('licenses/libheif-js/source/libheif-js-'.$version.'.tar.gz'));
    }

    public function test_the_sign_in_page_links_the_notices(): void
    {
        $this->assertStringContainsString('href="/licenses/index.html"', (string) file_get_contents(resource_path('js/Layouts/AuthLayout.tsx')));
    }

    public function test_every_page_of_the_club_panel_links_the_notices_without_touching_the_sign_in_page(): void
    {
        // The panel is where libheif-js is loaded (image fields): its sidebar links the notices on every page.
        $this->assertStringContainsString('href="/licenses/index.html"', (string) file_get_contents(resource_path('js/Components/PanelShell.tsx')));
        $this->assertStringContainsString('تراخيص البرامج مفتوحة المصدر', (string) file_get_contents(resource_path('js/Components/PanelShell.tsx')));
        // The library's own chunk names its licence and where its source is (vite.config.js, libheif-notice).
        $this->assertStringContainsString('/licenses/index.html', (string) file_get_contents(base_path('vite.config.js')));
    }
}
