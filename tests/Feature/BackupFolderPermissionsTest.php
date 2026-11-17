<?php

namespace Tests\Feature;

use App\Core\Backup\TenantBackupService;
use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Production, 2026-11-17: the nightly backup (cron, the deploy user) made the backup folders with Laravel's default
 * mode, 0700, inside a storage folder the panel (PHP-FPM, www-data) shares through its group. The panel could then
 * neither list the archives (an empty list over ten sound ones) nor start one: ZipArchive::open fails on a folder it
 * cannot enter («the archive could not be started»). The folders are now made open to the group, the ones made shut
 * before are opened by their owner's next run, and a folder that still cannot be used fails in plain words.
 */
class BackupFolderPermissionsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Event::listen(MessageLogged::class, function (MessageLogged $m) {
            $this->logged[] = $m->message.' '.json_encode($m->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        });
    }

    protected function tearDown(): void
    {
        // Leave nothing shut for the next test's cleanup.
        $root = Storage::disk('local')->path('');
        @chmod($root, 0755);
        foreach (['backups', 'backups/tenants'] as $dir) {
            @chmod($root.$dir, 0755);
        }
        foreach (glob($root.'backups/tenants/*') ?: [] as $dir) {
            @chmod($dir, 0755);
        }
        Str::createUlidsNormally();
        parent::tearDown();
    }

    private function service(): TenantBackupService
    {
        return app(TenantBackupService::class);
    }

    /** @return array<string, int> each backup folder level => its permission bits */
    private function modes(Tenant $club): array
    {
        $out = [];
        foreach (['backups', 'backups/tenants', 'backups/tenants/'.$club->id] as $dir) {
            $path = Storage::disk('local')->path($dir);
            clearstatcache(true, $path);
            $out[$dir] = fileperms($path) & 07777;
        }

        return $out;
    }

    public function test_the_backup_folders_are_made_open_to_the_shared_group_and_shut_to_others(): void
    {
        $club = $this->club();
        $name = $this->service()->create($club);

        foreach ($this->modes($club) as $dir => $mode) {
            $this->assertSame(0070, $mode & 0070, "$dir: the group (the panel's user) can enter, read and write");
            $this->assertSame(0, $mode & 0007, "$dir: others nothing");
            $this->assertSame(02000, $mode & 02000, "$dir: the group is passed on to what is made inside");
        }
        $this->service()->verifyArchive(Storage::disk('local')->path($this->service()->directory($club).'/'.$name));
        $this->assertSame([$name], array_column($this->service()->list($club), 'name'));
    }

    public function test_folders_an_earlier_run_made_shut_are_opened_by_the_next_backup_and_nothing_is_lost(): void
    {
        $club = $this->club();
        $first = $this->service()->create($club);
        // Production's state: the folders as Laravel's default made them (owner only).
        $root = Storage::disk('local')->path('');
        foreach (['backups' => 02700, 'backups/tenants' => 02700, 'backups/tenants/'.$club->id => 0700] as $dir => $mode) {
            chmod($root.$dir, $mode);
        }

        $this->travel(1)->seconds();
        $second = $this->service()->create($club);

        foreach ($this->modes($club) as $dir => $mode) {
            $this->assertSame(0070, $mode & 0070, "$dir is opened to the group by its owner's run");
        }
        $this->assertEqualsCanonicalizing([$first, $second], array_column($this->service()->list($club), 'name'));
        foreach ([$first, $second] as $name) {
            $this->service()->verifyArchive(Storage::disk('local')->path($this->service()->directory($club).'/'.$name));
        }
    }

    public function test_a_folder_that_cannot_be_written_fails_in_plain_words_keeps_every_archive_and_logs_where(): void
    {
        $club = $this->club();
        $kept = $this->service()->create($club);
        $dir = Storage::disk('local')->path($this->service()->directory($club));
        chmod($dir, 0575); // open to the group already, so not this process's to change: as a folder another user owns

        try {
            $this->travel(1)->seconds();
            $this->service()->create($club);
            $this->fail('a backup into a folder it cannot write must fail');
        } catch (BusinessRuleException $e) {
            $this->assertSame('backup.folder', $e->rule);
            $this->assertStringContainsString('مجلد النسخ الاحتياطية', $e->getMessage());
            $this->assertStringNotContainsString('/', $e->getMessage(), 'no path on the screen');
        }
        chmod($dir, 0755);

        $this->assertSame([$kept], array_column($this->service()->list($club), 'name'), 'the sound archive is kept');
        $this->service()->verifyArchive($dir.'/'.$kept);
        $this->assertStringContainsString('backup folder unreachable', implode("\n", $this->logged));
        $this->assertStringContainsString($dir, implode("\n", $this->logged), 'the server log says which folder');
    }

    public function test_an_archive_that_cannot_be_started_names_libzips_reason_in_the_log_and_removes_nothing(): void
    {
        $club = $this->club();
        $kept = $this->service()->create($club);
        $ulid = (string) Str::ulid();
        Str::createUlidsUsing(fn () => Str::of($ulid)); // the next build's name, taken by a folder: libzip refuses it
        $dir = Storage::disk('local')->path($this->service()->directory($club));
        mkdir($dir.'/'.TenantBackupService::BUILDING.$ulid.'.part');

        try {
            $this->travel(1)->seconds();
            $this->service()->create($club);
            $this->fail('the build must fail');
        } catch (BusinessRuleException $e) {
            $this->assertSame('backup.failed', $e->rule);
            $this->assertStringContainsString('the archive could not be started (zip error', $e->getMessage());
            $this->assertStringNotContainsString($dir, $e->getMessage());
        }
        @rmdir($dir.'/'.TenantBackupService::BUILDING.$ulid.'.part');

        $this->assertSame([$kept], array_column($this->service()->list($club), 'name'));
        $this->assertMatchesRegularExpression('/backup archive could not be started .*"zip_error":\d+/', implode("\n", $this->logged));
    }

    public function test_the_nightly_runs_at_three_muscat_time_and_a_killed_run_does_not_shut_out_the_next_night(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'backup:tenants'));

        $this->assertNotNull($event, 'backup:tenants is scheduled');
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame('Asia/Muscat', (string) ($event->timezone ?? config('app.timezone')));
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(120, $event->expiresAt, 'the lock of a killed run ends long before the next night');
    }

    public function test_every_folder_the_private_disk_makes_is_open_to_the_shared_group_and_shut_to_others(): void
    {
        // The configured disk itself (Storage::fake would use Flysystem's defaults): an upload's folder, as the panel makes it.
        $root = sys_get_temp_dir().'/perm-'.Str::random(8);
        $disk = Storage::build(['root' => $root] + config('filesystems.disks.local'));
        $old = umask(0002); // the deploy user's; the web's 022 leaves the group read and enter, which reading needs
        try {
            $disk->put('tenants/7/documents/2026/11/scan.jpg', 'x');
            foreach (['tenants', 'tenants/7', 'tenants/7/documents/2026/11'] as $dir) {
                $mode = fileperms($root.'/'.$dir) & 07777;
                $this->assertSame(0070, $mode & 0070, "$dir: the other user (through the group) can enter, read and write");
                $this->assertSame(0, $mode & 0007, "$dir: others nothing");
            }
            // A folder that is there and made shut is set back to group-open by the disk, not to 0700 as before.
            chmod($root.'/tenants/7', 0700);
            $disk->makeDirectory('tenants/7');
            $this->assertSame(0070, fileperms($root.'/tenants/7') & 0070);
        } finally {
            umask($old);
            (new Filesystem)->deleteDirectory($root);
        }
    }

    public function test_the_page_says_the_folder_cannot_be_read_instead_of_no_backups(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->service()->create($club);
        $this->actingAs($owner)->get(route('club.backups.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('folderUnreadable', false)->has('backups', 1));

        // As the panel's user found it in production: the backups folder shut (and, below it, the club's own).
        foreach (['backups', $this->service()->directory($club)] as $shut) {
            chmod(Storage::disk('local')->path($shut), 0);
            $page = $this->actingAs($owner)->get(route('club.backups.index'))->assertOk()
                ->assertInertia(fn ($page) => $page->where('folderUnreadable', true)->has('backups', 0));
            $alert = $page->viewData('page')['props']['backupAlert'] ?? null;
            $this->assertSame('unreadable', $alert['kind'] ?? null, 'not «no backup»: the nightly may be making them');
            $this->actingAs($owner)->get(route('club.dashboard'))->assertOk(); // every panel page still opens
            chmod(Storage::disk('local')->path($shut), 0755);
        }
    }
}
