<?php

namespace Tests\Feature;

use App\Core\Backup\TenantBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Production's two users, for real: this process plays the nightly (the deploy user, cron) and `sudo -u www-data` plays
 * the panel (PHP-FPM). The backup folder sits in a folder of group www-data with setgid, as on the server
 * (shared/storage/app/private is 2775 deploy:www-data). Runs where a passwordless sudo and a www-data user exist (CI);
 * skipped elsewhere, saying why.
 */
class BackupTwoUsersTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private string $root;

    private string $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $sudo = function_exists('posix_geteuid') && posix_geteuid() !== 0 && function_exists('posix_getpwnam') && posix_getpwnam('www-data')
            && trim((string) shell_exec('sudo -n -u www-data true 2>/dev/null && echo ok')) === 'ok';
        if (! $sudo) {
            $this->markTestSkipped('needs a passwordless sudo to the www-data user (CI): two real users');
        }
        Storage::fake('local');
        Storage::fake('public');
        $this->root = sys_get_temp_dir().'/bk2u-'.Str::random(8);
        mkdir($this->root);
        exec('sudo -n chgrp www-data '.escapeshellarg($this->root), $o, $code);
        $this->assertSame(0, $code, 'the shared group is set');
        chmod($this->root, 02775);
        config(['filesystems.disks.bk2u' => ['driver' => 'local', 'root' => $this->root, 'throw' => false] + array_intersect_key(config('filesystems.disks.local'), ['permissions' => 1])]);
        config(['gym.backup_disk' => 'bk2u']);
        $this->probe = sys_get_temp_dir().'/bk2u-probe-'.Str::random(8).'.php';
        file_put_contents($this->probe, <<<'PHP'
        <?php
        // The panel's part, as the web user: list the archives, read one through, and build one beside them.
        [$dir, $make] = [$argv[1], $argv[2] === 'make'];
        $out = ['enter' => is_dir($dir) && is_executable($dir), 'list' => [], 'read' => null, 'made' => null, 'zip_open' => null];
        $names = @scandir($dir);
        $out['list'] = $names === false ? null : array_values(array_filter($names, fn ($n) => str_ends_with($n, '.zip')));
        if ($out['list']) {
            $z = new ZipArchive;
            $out['read'] = $z->open($dir.'/'.$out['list'][0], ZipArchive::CHECKCONS | ZipArchive::RDONLY) === true && $z->getFromName('manifest.json') !== false;
        }
        if ($make) {
            $part = $dir.'/.building-probe.part';
            $z = new ZipArchive;
            $out['zip_open'] = $z->open($part, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($out['zip_open'] === true) {
                $z->addFromString('manifest.json', '{}');
                $name = 'backup-20000101-000000.zip';
                $out['made'] = $z->close() && rename($part, $dir.'/'.$name) ? $name : false;
            }
        }
        echo json_encode($out);
        PHP);
        chmod($this->probe, 0644);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            exec('sudo -n rm -rf '.escapeshellarg($this->root).' '.escapeshellarg($this->probe));
        }
        parent::tearDown();
    }

    /** @return array{enter: bool, list: ?list<string>, read: ?bool, made: string|false|null, zip_open: mixed} */
    private function asWeb(string $dir, bool $make = false): array
    {
        $json = shell_exec('sudo -n -u www-data '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->probe).' '.escapeshellarg($dir).' '.($make ? 'make' : 'look').' 2>&1');

        return json_decode((string) $json, true) ?? $this->fail('probe said: '.$json);
    }

    public function test_the_panel_user_sees_and_adds_to_what_the_nightly_user_made_and_the_nightly_goes_on(): void
    {
        $club = $this->club();
        $service = app(TenantBackupService::class);
        $dir = $this->root.'/'.$service->directory($club);

        // Production before the fix: the folders as Laravel's default made them. The web user is shut out, for real.
        $first = $service->create($club);
        chmod($this->root.'/backups', 02700);
        chmod($this->root.'/backups/tenants', 02700);
        chmod($dir, 0700);
        $shut = $this->asWeb($dir, true);
        $this->assertFalse($shut['enter'], 'reproduced: the panel cannot enter the folder');
        $this->assertNull($shut['list'], 'reproduced: the panel lists nothing');
        $this->assertNotSame(true, $shut['zip_open'], 'reproduced: «the archive could not be started»');

        // The nightly's next run (this user, the folders' owner) opens them; nothing is lost.
        $this->travel(1)->seconds();
        $second = $service->create($club);
        foreach (['backups', 'backups/tenants', $service->directory($club)] as $level) {
            $stat = stat($this->root.'/'.$level);
            $this->assertSame('www-data', posix_getgrgid($stat['gid'])['name'], "$level is the shared group's");
            $this->assertSame(02070, $stat['mode'] & 02070, "$level: group rwx, passed on");
            $this->assertSame(0, $stat['mode'] & 0007, "$level: others nothing");
        }

        // The panel user now lists both, reads one through, and builds an archive beside them.
        $web = $this->asWeb($dir, true);
        $this->assertTrue($web['enter']);
        $this->assertEqualsCanonicalizing([$first, $second], $web['list']);
        $this->assertTrue($web['read'], 'the panel reads a nightly archive whole');
        $this->assertTrue($web['zip_open']);
        $this->assertSame('backup-20000101-000000.zip', $web['made'], 'the panel can start and keep an archive');

        // And the nightly goes on after the panel's archive: built, proven, kept; the panel's file is no obstacle.
        $this->travel(1)->seconds();
        $third = $service->create($club);
        $service->verifyArchive($dir.'/'.$third);
        $this->assertContains($third, array_column($service->list($club), 'name'));
    }
}
