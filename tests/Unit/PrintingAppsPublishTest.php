<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The printing apps in mobile/ are published in this repository: they carry the club's identity only, no signing key,
 * password, certificate or real server address, and the docs say where they come from.
 */
class PrintingAppsPublishTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, string> the text files of mobile/, by their path */
    private function texts(): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root().'/mobile', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen($this->root()) + 1);
            if (str_contains($path, '/.build/') || str_contains($path, '/build/') || str_contains($path, '/.gradle/') || $file->getExtension() === 'pdf') {
                continue;
            }
            $out[$path] = (string) file_get_contents($file->getPathname());
        }

        return $out;
    }

    public function test_the_apps_carry_the_clubs_identity_and_no_secret(): void
    {
        $texts = $this->texts();
        $this->assertNotEmpty($texts);

        foreach ($texts as $path => $text) {
            // Only the club's own application id.
            preg_match_all('/\bom\.[a-z]+\.[a-z]+\b/', $text, $ids);
            $this->assertSame([], array_values(array_diff(array_unique($ids[0]), ['om.alopera.gym'])), $path);
            // No key, certificate, password or signing team written in.
            $this->assertDoesNotMatchRegularExpression('/BEGIN [A-Z ]*PRIVATE KEY|BEGIN CERTIFICATE/', $text, $path);
            $this->assertDoesNotMatchRegularExpression('/(storePassword|keyPassword)\s*=\s*["\'][^"\']+["\']/', $text, $path);
            // (the build-settings example shows the field with a placeholder team, ABCDE12345)
            $this->assertDoesNotMatchRegularExpression('/DEVELOPMENT_TEAM\s*[:=]\s*(?!ABCDE12345)[A-Z0-9]{10}\b/', $text, $path);
            // Servers only as examples (example.test, local addresses) or public documentation links.
            preg_match_all('#https?://([A-Za-z0-9.-]+)#', $text, $hosts);
            foreach ($hosts[1] as $host) {
                $this->assertMatchesRegularExpression('/(^|\.)(example\.test|evil\.test|x\.test|test)$|^(127\.0\.0\.1|10\.0\.2\.2|192\.168\.\d+\.\d+|localhost|user|host|bad|u|www\.apple\.com|schemas\.android\.com|github\.com)$/i', $host, "$path: $host");
            }
        }

        // Nothing that signs a build or ties it to an account is in the repository.
        $binaries = array_filter(array_keys($texts), fn ($p) => preg_match('/\.(jks|keystore|p12|mobileprovision|cer)$|google-services\.json$|GoogleService-Info\.plist$|local\.properties$|Alopera\.local\.xcconfig$/', $p));
        $this->assertSame([], array_values($binaries));
        $this->assertStringContainsString('<string name="app_name">Alopera Gym</string>', $texts['mobile/android/app/src/main/res/values/strings.xml']);
        $this->assertStringContainsString('window.AloperaPrinter', $texts['mobile/shared/js/alopera-printer-shim.js']);
    }

    public function test_the_docs_say_where_the_printing_apps_come_from(): void
    {
        $origin = (string) file_get_contents($this->root().'/docs/ABAAD.md');
        $this->assertStringContainsString('`mobile/`', $origin);
        $this->assertStringContainsString('mobile/shared/fixtures/receipts/', $origin);
        $this->assertStringNotContainsString('لم يُنسخ أي كود', $origin, 'the docs no longer say no code was taken');
        $this->assertStringContainsString('docs/ABAAD.md', (string) file_get_contents($this->root().'/mobile/README.md'));
    }
}
