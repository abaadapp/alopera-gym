<?php

namespace Tests\Feature;

use App\Core\Backup\DiskSpace;
use App\Core\Backup\TenantBackupService;
use App\Core\Support\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MemberDocument;
use App\Modules\Messaging\Models\Attachment;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\Support\FailingDisk;
use Tests\TestCase;
use ZipArchive;

/**
 * The club archive (TenantBackupService) under failure, in an isolated test database and faked disks only: nothing
 * here touches a real archive, the nightly job or production.
 *
 * Two kinds of test:
 *  - what already holds and must keep holding (a sound archive is complete; an archive restores, files readable after;
 *    an older archive, without the newer tables, still restores; a cut or foreign archive is refused, nothing replaced);
 *  - creating an archive under failure: a refused or cut write, a full disk — an error and backup.failed, never a
 *    success, and none of the fourteen archives pushed out; the safety copy before a restore reused when nothing
 *    changed. (A save during the backup and a process killed mid-way: BackupConcurrencyTest; the restore under
 *    failure: RestoreAllOrNothingTest.)
 *  - test_today_whatsapp_…: chat files are not in the club archive (a separate media archive is planned, #165).
 */
class BackupRestoreRisksTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function service(): TenantBackupService
    {
        return app(TenantBackupService::class);
    }

    /** A club with a member and her contract on the private disk; returns [club, owner, member, document, sha256]. */
    private function clubWithDocument(): array
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club, ['full_name' => 'سارة']);
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\nMARK-".bin2hex(random_bytes(2048))."-MARK\n%%EOF\n");
        $this->actingAs($owner)->post(route('club.members.documents.store', $member), ['type' => 'contract', 'title' => 'العقد', 'file' => new UploadedFile($path, 'عقد.pdf', 'application/pdf', null, true)])->assertSessionHasNoErrors();
        $document = $this->asTenant($club, fn () => MemberDocument::sole());

        return [$club, $owner, $member, $document, hash_file('sha256', $path)];
    }

    private function archivePath(Tenant $club, string $name): string
    {
        return $this->service()->disk()->path($this->service()->directory($club).'/'.$name);
    }

    /** @return array{sound: bool, entries: list<string>} every entry read back and checked against its CRC */
    private function inspect(string $file): array
    {
        $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::CHECKCONS | ZipArchive::RDONLY) !== true) {
            return ['sound' => false, 'entries' => []];
        }
        $sound = true;
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $bytes = $zip->getFromIndex($i);
            $sound = $sound && $bytes !== false && (crc32($bytes) & 0xFFFFFFFF) === ($stat['crc'] & 0xFFFFFFFF);
            $entries[] = $stat['name'];
        }
        $zip->close();

        return ['sound' => $sound, 'entries' => $entries];
    }

    /** Fourteen sound archives of the club as it is (copies of one real backup), oldest first. @return list<string> */
    private function fourteenSound(Tenant $club): array
    {
        $dir = $this->service()->directory($club);
        $first = $this->service()->create($club);
        $sound = Storage::disk('local')->get($dir.'/'.$first);
        Storage::disk('local')->delete($dir.'/'.$first);
        foreach (range(1, TenantBackupService::KEEP) as $day) {
            Storage::disk('local')->put($dir.sprintf('/backup-202611%02d-030000.zip', $day), $sound);
        }

        return $this->kept($club);
    }

    /** @return list<string> the archives kept, oldest first */
    private function kept(Tenant $club): array
    {
        return collect(Storage::disk('local')->files($this->service()->directory($club)))->map(fn ($f) => basename($f))->sort()->values()->all();
    }

    // ── What holds today and must keep holding ─────────────────────────────────────────────────────────────────

    public function test_a_sound_archive_holds_every_row_and_file_of_the_club_whole(): void
    {
        [$club, , , $document, $sha] = $this->clubWithDocument();
        $name = $this->service()->create($club);
        $file = $this->archivePath($club, $name);

        $check = $this->inspect($file);
        $this->assertTrue($check['sound']);
        $this->assertContains('files/private/'.$document->path, $check['entries']);
        $zip = new ZipArchive;
        $zip->open($file);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $data = json_decode($zip->getFromName('data.json'), true);
        $this->assertSame($manifest['counts'], array_map('count', $data), 'the manifest counts what data.json holds');
        $this->assertSame($sha, hash('sha256', $zip->getFromName('files/private/'.$document->path)), 'the contract as it was uploaded');
        $zip->close();
        $created = AuditLog::where('event', 'backup.created')->latest('id')->value('properties');
        $this->assertSame($name, $created['file']);
        $this->assertSame(hash_file('sha256', $file), $created['sha256'], 'recorded with the stored file\'s own fingerprint');
        $this->assertSame([$sha, 'files/private/'.$document->path], [$manifest['files']['files/private/'.$document->path]['sha256'], array_key_first($manifest['files'])]);
        $this->assertSame(['entries' => 3, 'files' => 1], array_intersect_key($this->service()->verifyArchive($file), ['entries' => 0, 'files' => 0]));
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->allFiles('backups'), fn ($f) => ! str_ends_with($f, '.zip'))), 'nothing half-built left');
    }

    public function test_an_archive_restores_the_rows_and_the_files_and_the_document_downloads_after(): void
    {
        [$club, $owner, $member, $document, $sha] = $this->clubWithDocument();
        $name = $this->service()->create($club);

        // Lost after the archive: the member's name changed, her contract's row and file gone.
        $this->asTenant($club, fn () => $member->forceFill(['full_name' => 'تغيّر'])->save());
        $this->asTenant($club, fn () => MemberDocument::query()->delete());
        Storage::disk('local')->delete($document->path);

        $this->service()->restore($club, $this->archivePath($club, $name));
        $this->assertSame('سارة', $this->asTenant($club, fn () => $member->fresh()->full_name));
        $download = $this->actingAs($owner)->get(route('club.documents.download', $document->id))->assertOk();
        $this->assertSame($sha, hash('sha256', $download->streamedContent()), 'readable by the app, byte for byte');
    }

    public function test_an_older_archive_without_the_newer_tables_still_restores(): void
    {
        [$club, , $member] = $this->clubWithDocument();
        $name = $this->service()->create($club);
        $old = tempnam(sys_get_temp_dir(), 'old').'.zip';
        copy($this->archivePath($club, $name), $old);

        // As an older release wrote it: format 1, without tables added since (here the latest few).
        $zip = new ZipArchive;
        $zip->open($old);
        $data = json_decode($zip->getFromName('data.json'), true);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        foreach (['payment_intents', 'gym_document_alerts', 'gym_document_renewals', 'whatsapp_contacts'] as $table) {
            unset($data[$table], $manifest['counts'][$table]);
        }
        unset($manifest['files'], $manifest['data_sha256'], $manifest['content_sha256']); // older releases wrote none of these
        $zip->addFromString('data.json', json_encode($data, JSON_UNESCAPED_UNICODE));
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
        $zip->close();

        $this->asTenant($club, fn () => $member->forceFill(['full_name' => 'تغيّر'])->save());
        $counts = $this->service()->restore($club, $old);
        $this->assertSame(0, $counts['payment_intents']);
        $this->assertSame('سارة', $this->asTenant($club, fn () => $member->fresh()->full_name));
    }

    public function test_a_cut_or_incomplete_archive_is_refused_and_nothing_is_replaced(): void
    {
        [$club, , $member] = $this->clubWithDocument();
        $source = $this->archivePath($club, $this->service()->create($club));
        $this->asTenant($club, fn () => $member->forceFill(['full_name' => 'بعد النسخة'])->save());

        $cut = tempnam(sys_get_temp_dir(), 'cut');
        file_put_contents($cut, substr((string) file_get_contents($source), 0, (int) (filesize($source) * 0.6)));
        $noData = tempnam(sys_get_temp_dir(), 'nodata').'.zip';
        copy($source, $noData);
        $zip = new ZipArchive;
        $zip->open($noData);
        $zip->deleteName('data.json');
        $zip->close();

        foreach (['cut' => $cut, 'without data.json' => $noData] as $what => $file) {
            try {
                $this->service()->restore($club, $file);
                $this->fail("$what: restored");
            } catch (BusinessRuleException $e) {
                $this->assertSame('backup.invalid', $e->rule);
            }
            $this->assertSame('بعد النسخة', $this->asTenant($club, fn () => $member->fresh()->full_name), "$what: nothing replaced");
        }
    }

    // ── What happens today: evidence for the plan awaiting approval ────────────────────────────────────────────

    /** A disk that refuses the archive: an error, backup.failed, no backup.created, and the archive kept before stays sound. */
    public function test_a_refused_archive_write_is_an_error_and_keeps_every_archive(): void
    {
        [$club, $owner] = $this->clubWithDocument();
        $first = $this->service()->create($club);
        FailingDisk::install('local', 'refuse');

        $this->actingAs($owner)->post(route('club.backups.store'))->assertSessionHasErrors('error')->assertSessionMissing('success');
        $this->assertSame(1, AuditLog::where('event', 'backup.created')->count(), 'only the first one');
        $this->assertSame(1, AuditLog::where('event', 'backup.failed')->count());
        $this->assertSame([$first], array_map('basename', Storage::disk('local')->files($this->service()->directory($club))), 'nothing else left behind');
        $this->service()->verifyArchive($this->archivePath($club, $first));
    }

    /** A disk that keeps only part of the archive: refused, and none of the fourteen sound archives is pushed out. */
    public function test_a_cut_archive_is_refused_and_never_pushes_a_sound_one_out(): void
    {
        [$club] = $this->clubWithDocument();
        $kept = $this->fourteenSound($club);

        FailingDisk::install('local', 'short');
        try {
            $this->service()->create($club);
            $this->fail('created');
        } catch (BusinessRuleException $e) {
            $this->assertSame('backup.failed', $e->rule);
        }
        $this->assertSame($kept, $this->kept($club), 'the same fourteen');
        foreach ($kept as $name) {
            $this->service()->verifyArchive($this->archivePath($club, $name));
        }
    }

    /** A disk without room: refused before anything is written, for a backup and for a restore. */
    public function test_a_full_disk_refuses_the_backup_and_the_restore_before_anything_is_written(): void
    {
        [$club, , $member] = $this->clubWithDocument();
        $kept = $this->fourteenSound($club);
        $this->app->instance(DiskSpace::class, new class extends DiskSpace
        {
            public function free(string $path): ?int
            {
                return 1024 * 1024;
            }
        });
        $this->asTenant($club, fn () => $member->forceFill(['full_name' => 'بعد النسخة'])->save());

        foreach (['create' => fn () => $this->service()->create($club), 'restore' => fn () => $this->service()->restore($club, $this->archivePath($club, $kept[13]))] as $what => $work) {
            try {
                $work();
                $this->fail("$what went through");
            } catch (BusinessRuleException $e) {
                $this->assertSame('backup.no_space', $e->rule, $what);
            }
        }
        $this->assertSame($kept, $this->kept($club));
        $this->assertSame('بعد النسخة', $this->asTenant($club, fn () => $member->fresh()->full_name), 'nothing replaced');
        $this->assertSame([], Storage::disk('local')->allFiles(TenantBackupService::STAGING));
    }

    /** Two backups in the same second keep two names: neither replaces the other. */
    public function test_two_backups_in_the_same_second_are_both_kept(): void
    {
        [$club] = $this->clubWithDocument();
        $this->travelTo(now()->setTime(10, 0, 0));
        $a = $this->service()->create($club);
        $b = $this->service()->create($club);
        $this->assertNotSame($a, $b);
        $this->assertCount(2, $this->kept($club));
    }

    /**
     * The safety copy before a restore, when the newest archive already holds the club exactly as it is (a retry after
     * a refused restore, nothing changed since): that archive is reused, so no copy is doubled and none pushed out.
     */
    public function test_a_restore_from_the_panel_reuses_the_newest_archive_when_it_holds_the_club_as_it_is(): void
    {
        [$club, $owner] = $this->clubWithDocument();
        $kept = $this->fourteenSound($club);
        $upload = tempnam(sys_get_temp_dir(), 'up').'.zip';
        copy($this->archivePath($club, $kept[13]), $upload);

        $this->actingAs($owner)->post(route('club.backups.restore'), ['archive' => new UploadedFile($upload, 'backup.zip', 'application/zip', null, true), 'confirm' => $club->slug])->assertSessionHas('success');
        $this->assertSame($kept, $this->kept($club), 'the same fourteen, none doubled, none pushed out');
        $this->assertSame($kept[13], AuditLog::where('event', 'backup.reused')->latest('id')->value('properties')['file']);
    }

    /** Plan (phase 5): the club's chat files go into its archive (files/whatsapp/…) and come back with it. */
    public function test_today_whatsapp_files_are_left_out_of_the_club_archive(): void
    {
        [$club] = $this->clubWithDocument();
        $path = 'whatsapp/'.$club->id.'/2026/11/'.'photo.jpg';
        Storage::disk('local')->put($path, 'JPEG-BYTES');
        $this->asTenant($club, function () use ($path) {
            $conversation = Conversation::create(['phone' => '96891112233', 'status' => 'open']);
            $message = Message::create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'type' => 'image', 'status' => 'received']);
            Attachment::create(['message_id' => $message->id, 'kind' => 'image', 'mime' => 'image/jpeg', 'size' => 10, 'path' => $path, 'status' => Attachment::STORED]);
        });

        $file = $this->archivePath($club, $this->service()->create($club));
        $zip = new ZipArchive;
        $zip->open($file);
        $rows = json_decode($zip->getFromName('data.json'), true)['whatsapp_attachments'];
        $zip->close();

        $this->assertSame([$path], array_column($rows, 'path'), 'the row is in the archive');
        $this->assertSame([], array_values(array_filter($this->inspect($file)['entries'], fn ($e) => str_contains($e, 'whatsapp'))), 'its file is not');
    }
}
