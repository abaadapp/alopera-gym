<?php

namespace Tests\Feature;

use App\Core\Backup\BackupDailyReport;
use App\Core\Backup\TenantBackupService;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The nightly backup tells the club's owner, in the bell, how it went: once a day per club, success only for an archive
 * built and proven whole, a failure in plain words, «did not run» when it never ran, and never both a failure and a
 * success for the same day.
 */
class BackupDailyNotificationTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->travelTo(now()->setTime(3, 0));
    }

    protected function tearDown(): void
    {
        foreach (glob(Storage::disk('local')->path('backups/tenants/*')) ?: [] as $dir) {
            @chmod($dir, 0755);
        }
        parent::tearDown();
    }

    /** @return list<array{key: string, kind: string, text: string, url: ?string, read: bool}> */
    private function bell(User $user): array
    {
        return StaffNotification::withoutGlobalScopes()->where('user_id', $user->id)->orderBy('id')->get()
            ->map(fn ($n) => ['key' => $n->key, 'kind' => $n->kind, 'text' => $n->text, 'url' => $n->url, 'read' => $n->read_at !== null])->all();
    }

    private function shut(Tenant $club): void
    {
        // Open to the group already, so not the run's to change, and not writable: the nightly fails here.
        $dir = Storage::disk('local')->path(app(TenantBackupService::class)->directory($club));
        @mkdir($dir, 0755, true);
        chmod($dir, 0575);
    }

    private function open(Tenant $club): void
    {
        chmod(Storage::disk('local')->path(app(TenantBackupService::class)->directory($club)), 0755);
    }

    public function test_a_sound_nightly_tells_the_owner_once_with_its_date_and_time_and_leads_to_the_backups_page(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $manager = $this->staff($club, 'manager');

        $this->artisan('backup:tenants')->assertExitCode(0);
        $this->artisan('backup:tenants')->assertExitCode(0); // run again the same day: still one notice

        $file = app(TenantBackupService::class)->list($club)[0]['name'];
        preg_match('/backup-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})/', $file, $m);
        $bell = $this->bell($owner);
        $this->assertCount(1, $bell);
        $this->assertSame('backup', $bell[0]['kind']);
        $this->assertSame(BackupDailyReport::key(), $bell[0]['key']);
        $this->assertSame("تم إنشاء النسخة الاحتياطية اليومية بنجاح — التاريخ والوقت: {$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}", $bell[0]['text']);
        $this->assertSame(route('club.backups.index', absolute: false), $bell[0]['url']);
        $this->assertSame([], $this->bell($manager), 'the owner only');

        // In the bell's list, under its own kind, and opening it leads to the backups page.
        $this->actingAs($owner)->get(route('club.notifications.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('list.data.0.kind', 'backup')->where('list.data.0.kind_label', 'النسخ الاحتياطي')->where('kinds.backup', 'النسخ الاحتياطي'));
        // The bell in every panel page (shared props) carries it too.
        $shared = $this->actingAs($owner)->get(route('club.dashboard'))->assertOk()->viewData('page')['props']['notifications'] ?? [];
        $this->assertStringContainsString('النسخة الاحتياطية اليومية بنجاح', json_encode($shared, JSON_UNESCAPED_UNICODE));
        $this->actingAs($owner)->get(route('club.backups.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('daily.result', 'succeeded')->where('daily.file', $file)->where('keep', 14)->has('backups', 1));
    }

    public function test_a_failed_nightly_says_so_safely_never_success_and_a_later_success_replaces_it(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->shut($club);

        $this->artisan('backup:tenants')->assertExitCode(1);
        $this->artisan('backup:tenants')->assertExitCode(1); // retried, failing again: no second notice

        $bell = $this->bell($owner);
        $this->assertCount(1, $bell);
        $this->assertStringStartsWith('فشلت النسخة الاحتياطية اليومية — يرجى مراجعة إعدادات النسخ الاحتياطي', $bell[0]['text']);
        $this->assertStringContainsString('صلاحيات المجلد', $bell[0]['text']);
        $this->assertStringNotContainsString('/', $bell[0]['text'], 'no path');
        $this->assertStringNotContainsString('تم إنشاء', $bell[0]['text']);
        $this->assertSame([], app(TenantBackupService::class)->list($club), 'no archive, so no success');
        $this->actingAs($owner)->get(route('club.backups.index'))->assertOk()->assertInertia(fn ($page) => $page->where('daily.result', 'failed'));

        // Read, then the retry succeeds: the same notice turns to success, unread again — never both.
        StaffNotification::withoutGlobalScopes()->where('user_id', $owner->id)->update(['read_at' => now()]);
        $this->open($club);
        $this->travel(5)->minutes();
        $this->artisan('backup:tenants')->assertExitCode(0);
        $bell = $this->bell($owner);
        $this->assertCount(1, $bell);
        $this->assertStringStartsWith('تم إنشاء النسخة الاحتياطية اليومية بنجاح', $bell[0]['text']);
        $this->assertFalse($bell[0]['read']);

        // Run again that day: today's archive is made, so none again and nothing told.
        $this->artisan('backup:tenants')->assertExitCode(0);
        $this->assertCount(1, app(TenantBackupService::class)->list($club));
        $this->assertCount(1, $this->bell($owner));

        // And a failure told after that success does not take it back.
        $this->asTenant($club, fn () => app(BackupDailyReport::class)->failed($club, 'سبب'));
        $this->assertStringStartsWith('تم إنشاء', $this->bell($owner)[0]['text']);
    }

    public function test_each_club_tells_only_its_own_active_owner(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $ownerA = $this->owner($a);
        $ownerB = $this->owner($b);
        $formerOwner = $this->staff($b, 'owner', ['is_active' => false]);
        $this->shut($a);

        $this->artisan('backup:tenants')->assertExitCode(1); // A fails, B goes through

        $this->assertCount(1, $this->bell($ownerA));
        $this->assertStringStartsWith('فشلت', $this->bell($ownerA)[0]['text']);
        $this->assertCount(1, $this->bell($ownerB));
        $this->assertStringStartsWith('تم إنشاء', $this->bell($ownerB)[0]['text']);
        $this->assertSame([], $this->bell($formerOwner), 'an inactive owner gets nothing');
        $this->assertSame(0, StaffNotification::withoutGlobalScopes()->where('tenant_id', $a->id)->whereIn('user_id', [$ownerB->id])->count());
        $this->actingAs($ownerA)->get(route('club.notifications.index'))->assertOk()->assertInertia(fn ($page) => $page->has('list.data', 1));
    }

    public function test_a_nightly_that_never_ran_is_told_once_and_a_late_success_replaces_it(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->travelTo(now()->setTime(4, 30));

        $this->artisan('backup:check-daily')->assertExitCode(0);
        $this->artisan('backup:check-daily')->assertExitCode(0);
        $bell = $this->bell($owner);
        $this->assertCount(1, $bell);
        $this->assertStringStartsWith('لم تعمل النسخة الاحتياطية اليومية اليوم', $bell[0]['text']);
        $this->assertSame([], app(TenantBackupService::class)->list($club), 'the check makes no archive');
        $this->actingAs($owner)->get(route('club.backups.index'))->assertOk()->assertInertia(fn ($page) => $page->where('daily.result', 'missing'));

        $this->artisan('backup:tenants')->assertExitCode(0);
        $this->assertCount(1, $this->bell($owner));
        $this->assertStringStartsWith('تم إنشاء', $this->bell($owner)[0]['text']);
        $this->artisan('backup:check-daily')->assertExitCode(0);
        $this->assertStringStartsWith('تم إنشاء', $this->bell($owner)[0]['text'], 'the check does not undo a success');
    }

    public function test_the_check_says_nothing_when_today_s_archive_is_there_or_the_club_is_new(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        app(TenantBackupService::class)->create($club); // made today (by hand): the nightly's day is covered
        $this->travelTo(now()->setTime(4, 30));
        $this->artisan('backup:check-daily')->assertExitCode(0);
        $this->assertSame([], $this->bell($owner));

        $this->travelTo(now()->setTime(10, 0));
        $new = $this->club('New');
        $newOwner = $this->owner($new);
        $this->artisan('backup:check-daily')->assertExitCode(0);
        $this->assertSame([], $this->bell($newOwner), 'a club made after 03:00 was not due');
    }

    public function test_the_nightly_and_the_check_are_scheduled_at_three_and_half_past_four_muscat_time(): void
    {
        $events = collect(app(Schedule::class)->events());
        $find = fn (string $cmd) => $events->first(fn ($e) => str_contains((string) $e->command, $cmd));

        $this->assertSame('0 3 * * *', $find('backup:tenants')->expression);
        $this->assertSame('30 4 * * *', $find('backup:check-daily')->expression);
        $this->assertSame('Asia/Muscat', config('app.timezone'));
    }
}
