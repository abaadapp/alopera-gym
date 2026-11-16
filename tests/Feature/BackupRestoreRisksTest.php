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
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    // ── Creating an archive: an error, never a success, and no sound archive pushed out ────────────────────────

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

    /**
     * A file of the club that is there but cannot be read (its permissions, a disk error): the archive would lack it,
     * so there is no archive: an error naming it, backup.failed, no backup.created, nothing left behind, and the
     * archives kept before are all still there. A file removed while the backup runs is not this (see the next test).
     */
    public function test_a_file_that_cannot_be_read_fails_the_backup_instead_of_leaving_it_out(): void
    {
        [$club, $owner, , $document] = $this->clubWithDocument();
        $first = $this->service()->create($club);
        $real = Storage::disk('local')->path($document->path);
        chmod($real, 0);
        if (is_readable($real)) {
            chmod($real, 0644);
            $this->markTestSkipped('run as root: a file cannot be made unreadable');
        }

        try {
            $this->actingAs($owner)->post(route('club.backups.store'))->assertSessionHasErrors('error')->assertSessionMissing('success');
            try {
                $this->service()->create($club);
                $this->fail('an archive without the file was created');
            } catch (BusinessRuleException $e) {
                $this->assertSame('backup.failed', $e->rule);
                $this->assertStringContainsString(basename($document->path), $e->getMessage(), 'the file is named');
            }
        } finally {
            chmod($real, 0644);
        }
        $this->assertSame(1, AuditLog::where('event', 'backup.created')->count(), 'only the first one');
        $this->assertSame(2, AuditLog::where('event', 'backup.failed')->count());
        $this->assertSame([$first], array_map('basename', Storage::disk('local')->files($this->service()->directory($club))), 'nothing else left behind');
    }

    /** A file removed between the listing and its reading (deleted by someone meanwhile) is left out, and the backup goes through. */
    public function test_a_file_removed_after_it_was_listed_is_left_out_and_the_backup_goes_through(): void
    {
        [$club] = $this->clubWithDocument();
        $disk = Storage::disk('public');
        Storage::set('public', new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends FilesystemAdapter
        {
            public function allFiles($directory = null)
            {
                return [...parent::allFiles($directory), $directory.'/site/removed-meanwhile.png'];
            }
        });

        $name = $this->service()->create($club);
        $checked = $this->service()->verifyArchive($this->archivePath($club, $name));
        $this->assertSame(1, $checked['files'], 'the contract only');
        $this->assertSame(1, AuditLog::where('event', 'backup.created')->count());
    }

    /** $count more members of the club, written straight to the table (a large club, quickly). */
    private function manyMembers(Tenant $club, int $count): void
    {
        $row = (array) DB::table('gym_members')->where('tenant_id', $club->id)->first();
        unset($row['id']);
        for ($i = 0; $i < $count; $i += 1000) {
            $chunk = [];
            for ($j = $i; $j < min($i + 1000, $count); $j++) {
                $chunk[] = ['full_name' => 'عضوة رقم '.$j.' '.Str::random(12), 'phone' => '968'.(80000000 + $j), 'member_no' => 'L'.$j, 'qr_token' => Str::random(40), 'email' => null, 'notes' => 'ملاحظة '.Str::random(80)] + $row;
            }
            DB::table('gym_members')->insert($chunk);
        }
    }

    /**
     * A club of 10,000 members (data.json about 8 MB): the archive is checked and restored a row at a time, so neither
     * needs more memory as the club grows (decoded whole, this took about 110 MB more, too much for a 128 MB panel).
     */
    public function test_a_large_club_is_archived_and_restored_a_row_at_a_time(): void
    {
        [$club, , $member] = $this->clubWithDocument();
        $this->manyMembers($club, 10000);
        $before = $this->asTenant($club, fn () => Member::count());

        memory_reset_peak_usage();
        $base = memory_get_usage();
        $name = $this->service()->create($club);
        $created = memory_get_peak_usage() - $base;

        $this->asTenant($club, fn () => Member::whereKeyNot($member->id)->limit(5000)->delete());
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $this->service()->restore($club, $this->archivePath($club, $name));
        $restored = memory_get_peak_usage() - $base;

        $this->assertSame($before, $this->asTenant($club, fn () => Member::count()), 'every member back');
        $this->assertLessThan(32 * 1048576, $created, 'creating and checking the archive: '.round($created / 1048576).' MB more');
        $this->assertLessThan(32 * 1048576, $restored, 'restoring it: '.round($restored / 1048576).' MB more');
    }

    /** Rewrites data.json inside $archive with $change(lines), as an archive without the newer manifest keys. */
    private function withDataLines(string $archive, callable $change): void
    {
        $zip = new ZipArchive;
        $zip->open($archive);
        $lines = explode("\n", (string) $zip->getFromName('data.json'));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        unset($manifest['data_sha256'], $manifest['content_sha256'], $manifest['files']);
        $zip->addFromString('data.json', implode("\n", $change($lines)));
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
    }

    /**
     * data.json written a row per line is checked as the one-line one always was: a row of another club, a cut file,
     * an unknown table are refused before anything is replaced; tables in another order (an archive of a version with
     * other tables) restore whole.
     */
    public function test_a_data_json_a_row_per_line_is_checked_before_anything_is_replaced(): void
    {
        [$club, , $member] = $this->clubWithDocument();
        $name = $this->service()->create($club);
        $this->asTenant($club, fn () => $member->forceFill(['full_name' => 'بعد النسخة'])->save());
        $archive = $this->archivePath($club, $name);
        $copy = fn (string $what) => tap(dirname($archive).'/'.$what.'.zip', fn ($to) => copy($archive, $to));
        $memberLine = fn (array $lines) => array_key_first(array_filter($lines, fn ($l) => str_contains($l, '"full_name":"سارة"')));

        $other = $copy('other');
        $this->withDataLines($other, function ($lines) use ($memberLine) {
            $lines[$memberLine($lines)] = preg_replace('/"tenant_id":\d+/', '"tenant_id":999999', $lines[$memberLine($lines)]);

            return $lines;
        });
        $cut = $copy('cut');
        $this->withDataLines($cut, fn ($lines) => array_slice($lines, 0, $memberLine($lines) + 1));
        $unknown = $copy('unknown');
        $this->withDataLines($unknown, fn ($lines) => [$lines[0], '"no_such_table":[', '{"id":1}', '],', ...array_slice($lines, 1)]);

        foreach (['backup.other_tenant' => $other, 'backup.invalid' => $cut, 'backup.invalid ' => $unknown] as $rule => $file) {
            try {
                $this->service()->restore($club, $file);
                $this->fail(basename($file).' was restored');
            } catch (BusinessRuleException $e) {
                $this->assertSame(trim($rule), $e->rule, basename($file));
            }
            $this->assertSame('بعد النسخة', $this->asTenant($club, fn () => $member->fresh()->full_name), basename($file).': nothing replaced');
        }

        // The tables in the opposite order: each is found where it is, and restored in the order of their keys.
        $reversed = $copy('reversed');
        $this->withDataLines($reversed, function ($lines) {
            $blocks = [];
            foreach (array_slice($lines, 1, -2) as $line) {
                str_ends_with($line, ':[') ? $blocks[] = [$line] : $blocks[array_key_last($blocks)][] = $line;
            }
            $blocks = array_reverse($blocks);
            $out = ['{'];
            foreach ($blocks as $i => $block) {
                $last = array_pop($block); // "]," or "]"
                array_push($out, ...$block);
                $out[] = $i === count($blocks) - 1 ? ']' : '],';
            }

            return [...$out, '}', ''];
        });
        $this->service()->restore($club, $reversed);
        $this->assertSame('سارة', $this->asTenant($club, fn () => $member->fresh()->full_name));
    }

    /** Rewrites data.json inside $archive as $change(its text) and gives it the manifest of the code before (no newer keys). */
    private function withData(string $archive, callable $change): void
    {
        $zip = new ZipArchive;
        $zip->open($archive);
        $text = (string) $zip->getFromName('data.json');
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        unset($manifest['data_sha256'], $manifest['content_sha256'], $manifest['files']);
        $zip->addFromString('data.json', $change($text));
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
    }

    /**
     * An archive of the code before, data.json on one line (json_encode of every row at once), of a club of 10,000
     * members, one of whom has notes that look like JSON: read in pieces, every row back exactly, no more memory than a
     * newer one; and checked as before: a row of another club, a cut file, more after its end, all refused unchanged.
     */
    public function test_an_older_one_line_archive_is_read_in_pieces_and_checked_as_before(): void
    {
        [$club, , $member] = $this->clubWithDocument();
        $tricky = 'ملاحظة "مقتبسة" {"tenant_id":999},{ \\ \"} ]],[{ نهاية';
        $this->asTenant($club, fn () => $member->forceFill(['notes' => $tricky])->save());
        $this->manyMembers($club, 10000);
        $before = $this->asTenant($club, fn () => Member::count());
        $archive = $this->archivePath($club, $this->service()->create($club));
        $this->withData($archive, fn (string $text) => json_encode(json_decode($text, true), JSON_UNESCAPED_UNICODE));
        $copy = fn (string $what, callable $change) => tap(dirname($archive).'/'.$what.'.zip', function ($to) use ($archive, $change) {
            copy($archive, $to);
            $this->withData($to, $change);
        });
        $refused = [
            'backup.other_tenant' => $copy('other', fn ($t) => preg_replace('/"tenant_id":'.$club->id.',/', '"tenant_id":999999,', $t, 1)),
            'backup.invalid' => $copy('cut', fn ($t) => substr($t, 0, intdiv(strlen($t), 2))),
            'backup.invalid ' => $copy('more', fn ($t) => $t.'{"x":[]}'),
        ];

        $this->asTenant($club, fn () => Member::whereKeyNot($member->id)->limit(5000)->delete());
        $this->asTenant($club, fn () => $member->forceFill(['notes' => 'بعد النسخة'])->save());
        foreach ($refused as $rule => $file) {
            try {
                $this->service()->restore($club, $file);
                $this->fail(basename($file).' was restored');
            } catch (BusinessRuleException $e) {
                $this->assertSame(trim($rule), $e->rule, basename($file));
            }
            $this->assertSame('بعد النسخة', $this->asTenant($club, fn () => $member->fresh()->notes), basename($file).': nothing replaced');
        }

        memory_reset_peak_usage();
        $base = memory_get_usage();
        $this->service()->restore($club, $archive);
        $restored = memory_get_peak_usage() - $base;

        $this->assertSame($before, $this->asTenant($club, fn () => Member::count()), 'every member back');
        $this->assertSame($tricky, $this->asTenant($club, fn () => $member->fresh()->notes), 'her notes exactly');
        $this->assertLessThan(32 * 1048576, $restored, 'restoring it: '.round($restored / 1048576).' MB more');
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
    public function test_whatsapp_files_are_in_the_club_archive_beside_their_rows(): void
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
        $this->assertSame(['files/private/'.$path], array_values(array_filter($this->inspect($file)['entries'], fn ($e) => str_contains($e, 'whatsapp'))), 'and so is its file (format 2)');
    }
}
