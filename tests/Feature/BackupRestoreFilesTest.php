<?php

namespace Tests\Feature;

use App\Core\Backup\TenantBackupService;
use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Modules\Gym\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;
use ZipArchive;

/**
 * What a restore writes from the archive's files. The public disk is served as it is under /storage/ on both the
 * website's and the panel's domains, so only images that really are images go back there; anything else from the
 * public part is kept on the private disk, a hidden file is not brought back, and an archive with a file of another
 * club, a path that climbs out or a symbolic link is refused before anything is replaced. Archives are synthetic.
 */
class BackupRestoreFilesTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    /**
     * A real backup of the club, plus the given entries (name => bytes, or [bytes, unix mode] for a link), as older
     * releases wrote archives (every archive kept before the manifest listed its files): no file list, so these entries
     * reach the path and content filters that such archives rely on. A newer archive with an added entry is refused
     * before that (test_an_entry_added_to_a_newer_archive_is_refused).
     */
    private function archive(Tenant $club, array $entries): string
    {
        $service = app(TenantBackupService::class);
        $copy = tempnam(sys_get_temp_dir(), 'restore-test');
        copy(Storage::disk('local')->path($service->path($club, $service->create($club))), $copy);
        $zip = new ZipArchive;
        $zip->open($copy);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        unset($manifest['files'], $manifest['data_sha256'], $manifest['content_sha256']);
        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_UNESCAPED_UNICODE));
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, is_array($bytes) ? $bytes[0] : $bytes);
            if (is_array($bytes)) {
                $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $bytes[1] << 16);
            }
        }
        $zip->close();

        return $copy;
    }

    private function image(string $type): string
    {
        $gd = imagecreatetruecolor(4, 4);
        ob_start();
        match ($type) {
            'png' => imagepng($gd),
            'jpeg' => imagejpeg($gd),
            'webp' => imagewebp($gd),
            'gif' => imagegif($gd),
        };
        imagedestroy($gd);

        return (string) ob_get_clean();
    }

    private const HTML = '<html><body><script>fetch("/app/account")</script></body></html>';

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(document.domain)"><script>alert(1)</script></svg>';

    /** Where a restore keeps, on the private disk, what may not be public (the service's ISOLATED). */
    private const ISOLATED = 'restore-isolated';

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    public function test_images_go_back_to_the_public_disk_and_active_or_unknown_files_never_do(): void
    {
        $club = $this->club();
        $t = 'tenants/'.$club->id;
        $png = $this->image('png');
        $files = [
            // Images, by extension and by what the bytes are: served as they were.
            "$t/website/a.webp" => $this->image('webp'),
            "$t/brand/logo-x.png" => $png,
            "$t/trainers/b.jpg" => $this->image('jpeg'),
            "$t/trainers/c.jpeg" => $this->image('jpeg'),
            "$t/posts/d.gif" => $this->image('gif'),
            "$t/posts/Photo.PNG" => $png,
        ];
        $active = [
            "$t/website/page.html" => self::HTML,
            "$t/website/page.HTML" => self::HTML,
            "$t/website/logo.svg" => self::SVG,
            "$t/website/logo.SvG" => self::SVG,
            "$t/website/page.xhtml" => self::HTML,
            "$t/website/feed.xml" => '<?xml version="1.0"?><x xmlns:h="http://www.w3.org/1999/xhtml"><h:script>alert(1)</h:script></x>',
            "$t/website/page.htm" => self::HTML,
            // Double extensions: the last one an image, or not; and bytes that are not what the name says.
            "$t/website/x.png.html" => self::HTML,
            "$t/website/x.html.png" => self::HTML,
            "$t/website/x.php.png" => $png,
            "$t/website/fake.png" => self::HTML,
            "$t/website/fake.webp" => self::SVG,
            // Kinds the public disk never holds.
            "$t/website/doc.pdf" => self::PDF,
            "$t/website/x.phtml" => '<?php echo 1;',
            "$t/website/noext" => self::HTML,
        ];
        $entries = [];
        foreach ($files + $active as $path => $bytes) {
            $entries['files/public/'.$path] = $bytes;
        }

        app(TenantBackupService::class)->restore($club, $this->archive($club, $entries));

        foreach ($files as $path => $bytes) {
            $this->assertSame($bytes, Storage::disk('public')->get($path), "$path is an image: restored as it was");
        }
        foreach ($active as $path => $bytes) {
            $this->assertFalse(Storage::disk('public')->exists($path), "$path must not be on the public disk");
            $kept = str_replace($t.'/', $t.'/'.self::ISOLATED.'/', $path);
            $this->assertSame($bytes, Storage::disk('local')->get($kept), "$path is kept, on the private disk");
        }
    }

    public function test_private_files_come_back_as_they_were_whatever_their_kind(): void
    {
        $club = $this->club();
        $t = 'tenants/'.$club->id;
        $private = ["$t/documents/contract.pdf" => self::PDF, "$t/whatsapp/a.html" => self::HTML, "$t/x/Report.PDF" => self::PDF];
        $entries = [];
        foreach ($private as $path => $bytes) {
            $entries['files/private/'.$path] = $bytes;
        }

        app(TenantBackupService::class)->restore($club, $this->archive($club, $entries));

        foreach ($private as $path => $bytes) {
            $this->assertSame($bytes, Storage::disk('local')->get($path));
            $this->assertFalse(Storage::disk('public')->exists($path));
        }
    }

    public function test_hidden_files_are_not_brought_back_on_either_disk(): void
    {
        $club = $this->club();
        $t = 'tenants/'.$club->id;
        $hidden = ["$t/.htaccess", "$t/website/.env", "$t/.git/config", "$t/website/.hidden.png"];
        $entries = [];
        foreach ($hidden as $path) {
            $entries['files/public/'.$path] = 'AddHandler application/x-httpd-php .png';
            $entries['files/private/'.$path] = 'APP_KEY=base64:secret';
        }

        app(TenantBackupService::class)->restore($club, $this->archive($club, $entries));

        foreach ($hidden as $path) {
            $this->assertFalse(Storage::disk('public')->exists($path), "$path (public)");
            $this->assertFalse(Storage::disk('local')->exists($path), "$path (private)");
            $this->assertFalse(Storage::disk('local')->exists(str_replace($t.'/', $t.'/'.self::ISOLATED.'/', $path)));
        }
    }

    /** @return array<string, array{0: callable(int, int): array<string, mixed>}> */
    public static function tamperedArchives(): array
    {
        return [
            'a path that climbs out of the club folder' => [fn (int $id, int $other) => ["files/public/tenants/$id/../../evil.html" => self::HTML]],
            'a path that climbs into another club' => [fn (int $id, int $other) => ["files/public/tenants/$id/website/../../$other/website/a.png" => 'x']],
            'a file of another club' => [fn (int $id, int $other) => ["files/public/tenants/$other/website/a.html" => self::HTML]],
            'a file outside the clubs folders' => [fn (int $id, int $other) => ['files/public/index.html' => self::HTML]],
            'a backslash in the path' => [fn (int $id, int $other) => ["files/public/tenants/$id/..\\..\\evil.html" => self::HTML]],
            'a control character in the path' => [fn (int $id, int $other) => ["files/public/tenants/$id/a\n.png" => 'x']],
            'an empty path segment' => [fn (int $id, int $other) => ["files/private/tenants/$id//a.pdf" => self::PDF]],
            'a name that is not UTF-8' => [fn (int $id, int $other) => ["files/public/tenants/$id/\xff\xfe/../../evil.html" => self::HTML]],
            'a symbolic link' => [fn (int $id, int $other) => ["files/public/tenants/$id/website/link.png" => ['/etc/passwd', 0120777]]],
        ];
    }

    #[DataProvider('tamperedArchives')]
    public function test_a_tampered_archive_is_refused_before_anything_is_replaced_and_again_on_a_retry(callable $entries): void
    {
        $club = $this->club();
        $other = $this->club('Other');
        $member = $this->member($club, ['full_name' => 'Before the backup']);
        $archive = $this->archive($club, $entries($club->id, $other->id));
        $this->asTenant($club, fn () => $member->update(['full_name' => 'Changed after']));
        Storage::disk('public')->put('tenants/'.$other->id.'/website/keep.png', 'other club');
        $filesBefore = [Storage::disk('public')->allFiles(), Storage::disk('local')->allFiles('tenants')];

        foreach ([1, 2] as $attempt) {
            try {
                app(TenantBackupService::class)->restore($club, $archive);
                $this->fail("attempt $attempt: the archive was restored");
            } catch (BusinessRuleException $e) {
                $this->assertSame('backup.invalid', $e->rule);
            }
            // Nothing replaced: the club's rows and every file stay as they were before the attempt.
            $this->assertSame('Changed after', $this->asTenant($club, fn () => Member::find($member->id)->full_name));
            $this->assertSame($filesBefore, [Storage::disk('public')->allFiles(), Storage::disk('local')->allFiles('tenants')]);
            $this->assertSame('other club', Storage::disk('public')->get('tenants/'.$other->id.'/website/keep.png'));
        }
        $this->assertFileDoesNotExist(Storage::disk('public')->path('evil.html'));
        $this->assertFileDoesNotExist(dirname(rtrim(Storage::disk('public')->path(''), '/')).'/evil.html');

        // The club's own clean backup still restores after the refusals.
        $this->assertGreaterThan(0, app(TenantBackupService::class)->restore($club, $this->archive($club, []))['gym_members']);
        $this->assertSame('other club', Storage::disk('public')->get('tenants/'.$other->id.'/website/keep.png'));
    }

    public function test_a_symbolic_link_entry_never_becomes_a_link_on_disk(): void
    {
        $club = $this->club();
        $path = 'tenants/'.$club->id.'/website/link.png';
        try {
            app(TenantBackupService::class)->restore($club, $this->archive($club, ['files/public/'.$path => ['/etc/passwd', 0120777]]));
        } catch (BusinessRuleException) {
        }
        $this->assertFalse(is_link(Storage::disk('public')->path($path)));
    }

    public function test_the_owner_restoring_from_the_panel_gets_the_same_filter(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $t = 'tenants/'.$club->id;
        $good = $this->archive($club, ["files/public/$t/website/page.html" => self::HTML, "files/public/$t/website/a.webp" => $webp = $this->image('webp')]);
        $bad = $this->archive($club, ["files/public/$t/../../evil.html" => self::HTML]);
        $upload = fn (string $path) => new UploadedFile($path, 'backup.zip', 'application/zip', null, true);

        $this->actingAs($owner)->post(route('club.backups.restore'), ['archive' => $upload($good), 'confirm' => $club->slug])->assertSessionHas('success');
        $this->assertFalse(Storage::disk('public')->exists("$t/website/page.html"));
        $this->assertSame(self::HTML, Storage::disk('local')->get("$t/".self::ISOLATED.'/website/page.html'));
        $this->assertSame($webp, Storage::disk('public')->get("$t/website/a.webp"));

        $this->actingAs($owner)->post(route('club.backups.restore'), ['archive' => $upload($bad), 'confirm' => $club->slug])
            ->assertRedirect()->assertSessionMissing('success');
        $this->assertFileDoesNotExist(dirname(rtrim(Storage::disk('public')->path(''), '/')).'/evil.html');
    }

    public function test_a_normal_backup_with_its_images_and_documents_restores_exactly(): void
    {
        $club = $this->club();
        $t = 'tenants/'.$club->id;
        Storage::disk('public')->put("$t/website/hero.webp", $hero = $this->image('webp'));
        Storage::disk('public')->put("$t/brand/logo-abc.png", $logo = $this->image('png'));
        Storage::disk('local')->put("$t/documents/id.pdf", self::PDF);
        $member = $this->member($club, ['full_name' => 'Original']);
        $service = app(TenantBackupService::class);
        $archive = Storage::disk('local')->path($service->path($club, $service->create($club)));

        Storage::disk('public')->delete(["$t/website/hero.webp", "$t/brand/logo-abc.png"]);
        Storage::disk('local')->delete("$t/documents/id.pdf");
        $this->asTenant($club, fn () => $member->update(['full_name' => 'Changed']));

        $service->restore($club, $archive);

        $this->assertSame($hero, Storage::disk('public')->get("$t/website/hero.webp"));
        $this->assertSame($logo, Storage::disk('public')->get("$t/brand/logo-abc.png"));
        $this->assertSame(self::PDF, Storage::disk('local')->get("$t/documents/id.pdf"));
        $this->assertSame('Original', $this->asTenant($club, fn () => $member->fresh()->full_name));
        $this->assertSame([], Storage::disk('local')->allFiles($t.'/'.self::ISOLATED));
    }

    public function test_an_entry_added_to_a_newer_archive_is_refused_before_anything_is_replaced(): void
    {
        $club = $this->club();
        $service = app(TenantBackupService::class);
        $member = $this->member($club, ['full_name' => 'قبل']);
        $copy = tempnam(sys_get_temp_dir(), 'restore-test');
        copy(Storage::disk('local')->path($service->path($club, $service->create($club))), $copy);
        $zip = new ZipArchive;
        $zip->open($copy);
        $zip->addFromString('files/private/tenants/'.$club->id.'/members/1/extra.pdf', '%PDF-1.4 added later');
        $zip->close();
        $this->asTenant($club, fn () => $member->forceFill(['full_name' => 'بعد'])->save());

        try {
            $service->restore($club, $copy);
            $this->fail('restored');
        } catch (BusinessRuleException $e) {
            $this->assertSame('backup.invalid', $e->rule);
        }
        $this->assertSame('بعد', $this->asTenant($club, fn () => $member->fresh()->full_name));
        Storage::disk('local')->assertMissing('tenants/'.$club->id.'/members/1/extra.pdf');
    }
}
