<?php

namespace Tests\Feature;

use App\Core\Backup\TenantBackupService;
use App\Core\Support\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MemberDocument;
use App\Modules\Messaging\Models\Attachment;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
 *  - test_today_…: what happens TODAY, written down as evidence (phases 4 and 5 of the save-integrity task). The backup
 *    and restore code is not changed until the owner approves the plan; the approved fix turns each of these around
 *    (the docblock of each says into what).
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
        $this->assertSame($name, AuditLog::where('event', 'backup.created')->latest('id')->value('properties')['file']);
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

    /** Plan: the stored archive is read back and checked before backup.created and before any old one is pruned. */
    public function test_today_a_refused_archive_write_is_still_reported_as_created(): void
    {
        [$club, $owner] = $this->clubWithDocument();
        FailingDisk::install('local', 'refuse');

        $this->actingAs($owner)->post(route('club.backups.store'))->assertSessionHas('success');
        $name = AuditLog::where('event', 'backup.created')->latest('id')->value('properties')['file'];
        $this->assertFalse(Storage::disk('local')->exists($this->service()->directory($club).'/'.$name), 'no archive on the disk');
    }

    /** Plan: the same check keeps a cut archive out, so it can never push a sound one out of the fourteen kept. */
    public function test_today_a_cut_archive_pushes_a_sound_one_out_of_the_fourteen_kept(): void
    {
        [$club] = $this->clubWithDocument();
        $dir = $this->service()->directory($club);
        $sound = $this->service()->disk()->get($dir.'/'.$this->service()->create($club));
        Storage::disk('local')->delete($dir.'/'.basename((string) collect(Storage::disk('local')->files($dir))->first()));
        foreach (range(1, TenantBackupService::KEEP) as $day) {
            Storage::disk('local')->put($dir.sprintf('/backup-202611%02d-030000.zip', $day), $sound);
        }

        FailingDisk::install('local', 'short');
        $new = $this->service()->create($club);
        $kept = collect(Storage::disk('local')->files($dir))->map(fn ($f) => basename($f))->sort()->values();

        $this->assertCount(TenantBackupService::KEEP, $kept);
        $this->assertNotContains('backup-20261101-030000.zip', $kept, 'the oldest sound archive was removed');
        $this->assertFalse($this->inspect($this->archivePath($club, $new))['sound'], 'for one that cannot be opened');
    }

    /** Plan: the rows are read in one snapshot (REPEATABLE READ, read only), so an archive is always restorable. */
    public function test_today_a_save_during_the_backup_makes_an_archive_that_cannot_be_restored(): void
    {
        [$club, $owner, $member] = $this->clubWithDocument();
        // A member and her document saved while the backup runs: after it read the members, before the documents.
        $done = false;
        DB::listen(function ($query) use (&$done, $club, $owner) {
            if (! $done && str_starts_with($query->sql, 'select * from "gym_members"')) {
                $done = true;
                $late = $this->member($club, ['full_name' => 'عضوة جديدة']);
                $this->asTenant($club, function () use ($owner, $late) {
                    MemberDocument::create(['member_id' => $late->id, 'type' => 'id', 'title' => 'هوية', 'path' => 'x', 'original_name' => 'x', 'mime' => 'application/pdf', 'size' => 1, 'uploaded_by' => $owner->id]);
                });
            }
        });
        $name = $this->service()->create($club);
        $this->assertTrue($done);

        $this->asTenant($club, fn () => $member->forceFill(['full_name' => 'بعد النسخة'])->save());
        try {
            $this->service()->restore($club, $this->archivePath($club, $name));
            $this->fail('restored');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key', $e->getMessage(), 'a document whose member is not in the archive');
        }
        $this->assertSame('بعد النسخة', $this->asTenant($club, fn () => $member->fresh()->full_name), 'nothing replaced: the transaction rolled back');
    }

    /** Plan: files are written and checked before the rows are replaced; a failure leaves rows and files as they were. */
    public function test_today_a_restore_whose_files_cannot_be_written_replaces_the_rows_and_says_it_worked(): void
    {
        [$club, $owner, $member, $document] = $this->clubWithDocument();
        $archive = $this->archivePath($club, $this->service()->create($club));
        $upload = new UploadedFile(copy($archive, $tmp = tempnam(sys_get_temp_dir(), 'up').'.zip') ? $tmp : $archive, 'backup.zip', 'application/zip', null, true);
        $archivesBefore = count(Storage::disk('local')->allFiles('backups'));
        $this->asTenant($club, fn () => $member->forceFill(['full_name' => 'بعد النسخة'])->save());
        Storage::disk('local')->delete($document->path);

        // The disk refuses every write: the safety copy before the restore, then every file of the archive.
        FailingDisk::install('local', 'refuse');
        $this->actingAs($owner)->post(route('club.backups.restore'), ['archive' => $upload, 'confirm' => $club->slug])->assertSessionHas('success');

        $this->assertSame('سارة', $this->asTenant($club, fn () => $member->fresh()->full_name), 'the rows were replaced');
        $this->assertTrue($this->asTenant($club, fn () => MemberDocument::whereKey($document->id)->exists()), 'the document row is back');
        Storage::disk('local')->assertMissing($document->path);
        $this->actingAs($owner)->get(route('club.documents.download', $document->id))->assertNotFound();
        $this->assertCount($archivesBefore, Storage::disk('local')->allFiles('backups'), 'and no safety copy was kept before it');
    }

    /**
     * ZipArchive hands back an entry's bytes without checking them against the CRC the archive keeps for it.
     * Plan: every entry is checked (crc32 against statIndex) before anything is replaced; a damaged one refuses the restore.
     */
    public function test_today_a_damaged_file_inside_the_archive_comes_back_damaged_without_a_word(): void
    {
        [$club, , , $document, $sha] = $this->clubWithDocument();
        $source = $this->archivePath($club, $this->service()->create($club));

        // The contract stored uncompressed, then one byte of it changed on the way (a bad copy, a bad sector).
        $copy = tempnam(sys_get_temp_dir(), 'dmg').'.zip';
        copy($source, $copy);
        $zip = new ZipArchive;
        $zip->open($copy);
        $entry = 'files/private/'.$document->path;
        $zip->addFromString($entry, (string) $zip->getFromName($entry));
        $zip->setCompressionName($entry, ZipArchive::CM_STORE);
        $zip->close();
        $raw = (string) file_get_contents($copy);
        $at = strpos($raw, 'MARK-') + 10;
        $raw[$at] = $raw[$at] === 'a' ? 'b' : 'a';
        file_put_contents($copy, $raw);

        $this->service()->restore($club, $copy);
        Storage::disk('local')->assertExists($document->path);
        $this->assertNotSame($sha, hash('sha256', Storage::disk('local')->get($document->path)), 'not the contract that was archived');
        $this->assertSame(1, AuditLog::where('event', 'backup.restored')->count(), 'and the restore is recorded as done');
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
