<?php

namespace Tests\Feature;

use App\Core\Backup\DiskSpace;
use App\Core\Backup\RestoreFiles;
use App\Core\Backup\TenantBackupService;
use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Modules\Gym\Models\Member;
use App\Modules\Messaging\Models\Attachment;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Services\MediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;
use ZipArchive;

/**
 * A chat attachment's file is named by its row's path. Whatever a restored archive says, a club's attachments open only
 * files inside its own chat folder (whatsapp/{club}/…): an archive whose attachment rows name another club's files, an
 * archive or anything else on the disk is refused before anything is replaced, and MediaStore never opens such a path.
 *
 * From format 2 the club archive carries the chat files themselves (files/private/whatsapp/{club}/…, from the disk
 * services.whatsapp.media_disk names) through the same checks, staging and recovery as the club's other files; a
 * format 1 archive restores as before and leaves the chat files on disk as they are. An attachment recorded as stored
 * whose file is missing makes the backup incomplete: kept apart, never one of the fourteen, reported as a failure.
 */
class WhatsAppMediaBackupTest extends TestCase
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

    /** A club with a member and one stored chat image; returns [club, member, attachment]. */
    private function clubWithAttachment(): array
    {
        $club = $this->club();
        $member = $this->member($club, ['full_name' => 'سارة']);
        $path = 'whatsapp/'.$club->id.'/2026/11/photo.jpg';
        Storage::disk('local')->put($path, 'JPEG-BYTES');
        $attachment = $this->asTenant($club, function () use ($path) {
            $conversation = Conversation::create(['phone' => '96891112233', 'status' => 'open']);
            $message = Message::create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'type' => 'image', 'status' => 'received']);

            return Attachment::create(['message_id' => $message->id, 'kind' => 'image', 'mime' => 'image/jpeg', 'size' => 10, 'path' => $path, 'status' => Attachment::STORED]);
        });

        return [$club, $member, $attachment];
    }

    private function archivePath(Tenant $club, string $name): string
    {
        return $this->service()->disk()->path($this->service()->directory($club).'/'.$name);
    }

    /** The archive with its attachment row's path replaced, as a tampered archive would carry it. */
    private function withAttachmentPath(string $archive, string $from, string $to): string
    {
        $copy = dirname($archive).'/tampered-'.md5($to).'.zip';
        copy($archive, $copy);
        $zip = new ZipArchive;
        $zip->open($copy);
        $data = (string) $zip->getFromName('data.json');
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        unset($manifest['data_sha256'], $manifest['content_sha256']);
        $zip->addFromString('data.json', str_replace(json_encode($from), json_encode($to), $data));
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();

        return $copy;
    }

    public function test_an_attachment_row_naming_a_file_outside_its_club_folder_refuses_the_archive_before_anything_is_replaced(): void
    {
        [$club, $member, $attachment] = $this->clubWithAttachment();
        $other = $this->club();
        $archive = $this->archivePath($club, $this->service()->create($club));
        $this->asTenant($club, fn () => $member->forceFill(['full_name' => 'بعد النسخة'])->save());

        foreach ([
            'whatsapp/'.$other->id.'/2026/11/theirs.jpg', // another club's chat file
            'backups/tenants/'.$other->id.'/backup-20261116-030000.zip', // another club's archive
            'tenants/'.$other->id.'/documents/contract.pdf', // another club's documents
            'whatsapp/'.$club->id.'/../'.$other->id.'/theirs.jpg', // climbing out of its own folder
            'whatsapp/'.$club->id.'//photo.jpg', // an empty part
            '.env',
        ] as $path) {
            try {
                $this->service()->restore($club, $this->withAttachmentPath($archive, $attachment->path, $path));
                $this->fail("an attachment at {$path} was restored");
            } catch (BusinessRuleException $e) {
                $this->assertSame('backup.invalid', $e->rule, $path);
            }
            $this->asTenant($club, fn () => $this->assertSame(['بعد النسخة', $attachment->path], [Member::find($member->id)->full_name, Attachment::find($attachment->id)->path]), "nothing replaced for {$path}");
        }
    }

    public function test_an_archive_as_every_version_writes_it_still_restores_its_attachments(): void
    {
        [$club, $member, $attachment] = $this->clubWithAttachment();
        $archive = $this->archivePath($club, $this->service()->create($club));
        $this->asTenant($club, fn () => Attachment::query()->delete());

        $this->service()->restore($club, $archive);

        $this->asTenant($club, fn () => $this->assertSame([$attachment->path, Attachment::STORED], [Attachment::find($attachment->id)?->path, Attachment::find($attachment->id)?->status]));
    }

    public function test_media_store_never_opens_a_file_outside_the_attachments_club_folder(): void
    {
        [$club, , $attachment] = $this->clubWithAttachment();
        $other = $this->club();
        Storage::disk('local')->put('whatsapp/'.$other->id.'/2026/11/theirs.jpg', 'THEIRS');
        Storage::disk('local')->put('backups/tenants/'.$other->id.'/backup.zip', 'ARCHIVE');
        $store = app(MediaStore::class);

        $this->assertSame(Storage::disk('local')->path($attachment->path), $store->absolutePath($attachment), 'its own file');
        foreach (['whatsapp/'.$other->id.'/2026/11/theirs.jpg', 'backups/tenants/'.$other->id.'/backup.zip', 'whatsapp/'.$club->id.'/../'.$other->id.'/2026/11/theirs.jpg'] as $path) {
            $attachment->path = $path;
            $this->assertNull($store->absolutePath($attachment), $path);
        }
    }

    /** @return list<string> the archive's entries */
    private function entries(string $archive): array
    {
        $zip = new ZipArchive;
        $zip->open($archive);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);

        return $names;
    }

    private function manifest(string $archive): array
    {
        $zip = new ZipArchive;
        $zip->open($archive);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        return $manifest;
    }

    /** A copy of the archive changed by $change(ZipArchive, array &$manifest), its manifest written back after. */
    private function rewritten(string $archive, callable $change): string
    {
        $copy = dirname($archive).'/rewritten-'.Str::random(8).'.zip';
        copy($archive, $copy);
        $zip = new ZipArchive;
        $zip->open($copy);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $change($zip, $manifest);
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();

        return $copy;
    }

    /** As code before format 2 wrote it: format 1, no chat files. */
    private function asFormatOne(string $archive): string
    {
        return $this->rewritten($archive, function (ZipArchive $zip, array &$manifest) {
            foreach (array_keys($manifest['files']) as $entry) {
                if (str_starts_with($entry, 'files/private/whatsapp/')) {
                    $zip->deleteName($entry);
                    unset($manifest['files'][$entry]);
                }
            }
            unset($manifest['content_sha256']);
            $manifest['format'] = 1;
        });
    }

    private function png(int $shade): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, imagecolorallocate($image, $shade, 10, 10));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function chatFile(Tenant $club, string $path, string $bytes, string $disk = 'local'): Attachment
    {
        Storage::disk($disk)->put($path, $bytes);

        return $this->asTenant($club, function () use ($path, $bytes) {
            $conversation = Conversation::firstOrCreate(['phone' => '96891112233'], ['status' => 'open']);
            $message = Message::create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'type' => 'document', 'status' => 'received']);

            return Attachment::create(['message_id' => $message->id, 'kind' => 'document', 'mime' => 'application/pdf', 'size' => strlen($bytes), 'path' => $path, 'status' => Attachment::STORED]);
        });
    }

    /** @return list<array> each such audit entry's properties, oldest first */
    private function events(Tenant $club, string $event): array
    {
        return DB::table('audit_logs')->where('tenant_id', $club->id)->where('event', $event)->orderBy('id')->pluck('properties')->map(fn ($p) => json_decode((string) $p, true))->all();
    }

    private function refused(callable $work, string $rule, string $what): BusinessRuleException
    {
        try {
            $work();
        } catch (BusinessRuleException $e) {
            $this->assertSame($rule, $e->rule, $what.': '.$e->getMessage());

            return $e;
        }
        $this->fail("$what went through");
    }

    public function test_an_archive_holds_the_clubs_rows_images_documents_and_chat_files_whole(): void
    {
        [$club, , $attachment] = $this->clubWithAttachment();
        Storage::disk('local')->put('tenants/'.$club->id.'/documents/contract.pdf', '%PDF-1.4 CONTRACT %%EOF');
        Storage::disk('public')->put('tenants/'.$club->id.'/site/hero.png', $this->png(90));

        $archive = $this->archivePath($club, $this->service()->create($club));

        $this->assertSame(['data.json', 'files/private/tenants/'.$club->id.'/documents/contract.pdf', 'files/private/'.$attachment->path, 'files/public/tenants/'.$club->id.'/site/hero.png', 'manifest.json'], $this->entries($archive));
        $manifest = $this->manifest($archive);
        $this->assertSame([2, hash('sha256', 'JPEG-BYTES')], [$manifest['format'], $manifest['files']['files/private/'.$attachment->path]['sha256']]);
        $this->assertArrayNotHasKey('incomplete', $manifest);
        $this->assertSame(3, $this->service()->verifyArchive($archive)['files']);
        $this->assertCount(1, $this->events($club, 'backup.created'));
    }

    public function test_a_chat_file_lost_after_the_backup_comes_back_with_the_same_bytes(): void
    {
        [$club, , $attachment] = $this->clubWithAttachment();
        $bytes = random_bytes(300_000);
        Storage::disk('local')->put($attachment->path, $bytes);
        $archive = $this->archivePath($club, $this->service()->create($club));

        // Lost: the file gone, the row gone, then (a second time) the file gone and its row still recorded as stored.
        Storage::disk('local')->delete($attachment->path);
        $this->asTenant($club, fn () => Attachment::query()->delete());
        $this->service()->restore($club, $archive);
        $this->assertSame(hash('sha256', $bytes), hash('sha256', (string) Storage::disk('local')->get($attachment->path)), 'the same bytes');
        $this->asTenant($club, fn () => $this->assertSame(Attachment::STORED, Attachment::find($attachment->id)?->status));

        Storage::disk('local')->delete($attachment->path);
        $this->service()->restoreWithSafetyCopy($club, $archive); // as the panel does: its safety copy is incomplete, the restore goes on
        $this->assertSame($bytes, Storage::disk('local')->get($attachment->path));
        $this->assertCount(1, $this->events($club, 'backup.incomplete'), 'the safety copy says it is incomplete');
        $this->assertCount(1, $this->service()->list($club), 'and is not one of the sound archives');
    }

    public function test_an_older_archive_restores_and_leaves_the_chat_files_on_disk_as_they_are(): void
    {
        [$club, $member, $attachment] = $this->clubWithAttachment();
        Storage::disk('local')->put('tenants/'.$club->id.'/documents/contract.pdf', '%PDF-1.4 A %%EOF');
        $old = $this->asFormatOne($this->archivePath($club, $this->service()->create($club)));
        $this->assertSame(1, $this->manifest($old)['format']);
        $this->assertNotContains('files/private/'.$attachment->path, $this->entries($old));

        $this->asTenant($club, fn () => $member->forceFill(['full_name' => 'بعد النسخة'])->save());
        Storage::disk('local')->put('tenants/'.$club->id.'/documents/contract.pdf', '%PDF-1.4 B %%EOF');
        Storage::disk('local')->put($attachment->path, 'NEWER-BYTES');

        $this->service()->restore($club, $old);

        $this->asTenant($club, fn () => $this->assertSame(['سارة', $attachment->path], [Member::find($member->id)->full_name, Attachment::find($attachment->id)->path]));
        $this->assertSame('%PDF-1.4 A %%EOF', Storage::disk('local')->get('tenants/'.$club->id.'/documents/contract.pdf'));
        $this->assertSame('NEWER-BYTES', Storage::disk('local')->get($attachment->path), 'a format 1 archive has no chat files: the ones on disk stay');
    }

    public function test_a_chat_file_entry_of_another_club_or_in_a_format_one_archive_is_refused_before_anything_is_replaced(): void
    {
        [$club, $member, $attachment] = $this->clubWithAttachment();
        $other = $this->club();
        Storage::disk('local')->put('whatsapp/'.$other->id.'/2026/11/theirs.jpg', 'THEIRS');
        $archive = $this->archivePath($club, $this->service()->create($club));
        $this->asTenant($club, fn () => $member->forceFill(['full_name' => 'بعد النسخة'])->save());
        Storage::disk('local')->put($attachment->path, 'NOW');

        $add = fn (string $entry) => function (ZipArchive $zip, array &$manifest) use ($entry) {
            $zip->addFromString($entry, 'PLANTED');
            $manifest['files'][$entry] = ['size' => 7, 'sha256' => hash('sha256', 'PLANTED')];
        };
        foreach ([
            'another club' => $this->rewritten($archive, $add('files/private/whatsapp/'.$other->id.'/2026/11/theirs.jpg')),
            'climbing out' => $this->rewritten($archive, $add('files/private/whatsapp/'.$club->id.'/../'.$other->id.'/theirs.jpg')),
            'beside the chat folder' => $this->rewritten($archive, $add('files/private/whatsapp/x.jpg')),
            'a format 1 archive with chat files' => $this->rewritten($archive, function (ZipArchive $zip, array &$manifest) {
                $manifest['format'] = 1;
            }),
        ] as $what => $tampered) {
            $this->refused(fn () => $this->service()->restore($club, $tampered), 'backup.invalid', $what);
            $this->assertSame('THEIRS', Storage::disk('local')->get('whatsapp/'.$other->id.'/2026/11/theirs.jpg'), $what);
            $this->assertSame('NOW', Storage::disk('local')->get($attachment->path), $what);
            $this->asTenant($club, fn () => $this->assertSame('بعد النسخة', Member::find($member->id)->full_name, $what));
        }
    }

    public function test_a_stored_attachment_without_its_file_makes_the_backup_incomplete_never_a_sound_one(): void
    {
        [$club, , $attachment] = $this->clubWithAttachment();
        $sound = $this->service()->create($club);
        Storage::disk('local')->delete($attachment->path);

        foreach (range(1, 3) as $night) {
            $this->travel(1)->days();
            $e = $this->refused(fn () => $this->service()->create($club), 'backup.incomplete', "night $night");
            $this->assertStringContainsString((string) $attachment->id, $e->getMessage());
            $this->assertStringNotContainsString('JPEG', $e->getMessage());
        }

        $this->assertSame([$sound], array_column($this->service()->list($club), 'name'), 'the sound archive stays, alone among the fourteen');
        $this->assertCount(1, $this->events($club, 'backup.created'), 'never recorded as a sound backup');
        $incomplete = $this->events($club, 'backup.incomplete');
        $this->assertCount(3, $incomplete, 'every night says so');
        $this->assertSame([[$attachment->id], 1], [end($incomplete)['attachment_ids'] ?? null, end($incomplete)['missing_attachments'] ?? null]);
        $this->assertSame([], $this->events($club, 'backup.failed'), 'recorded once, as incomplete');
        $kept = $this->service()->disk()->files($this->service()->directory($club).'/'.TenantBackupService::INCOMPLETE);
        $this->assertCount(TenantBackupService::INCOMPLETE_KEEP, $kept, 'the newest two incomplete archives only: three nights never fill the disk');
        $manifest = $this->manifest($this->service()->disk()->path(end($kept)));
        $this->assertSame(['missing_attachments' => 1, 'attachment_ids' => [$attachment->id]], $manifest['incomplete']);
        $this->assertSame(2, $this->service()->verifyArchive($this->service()->disk()->path(end($kept)))['entries'], 'still whole: the rest of the club can be recovered from it');

        // The file back: the next backup is sound again.
        Storage::disk('local')->put($attachment->path, 'JPEG-BYTES');
        $this->travel(1)->days();
        $this->service()->create($club);
        $this->assertCount(2, $this->service()->list($club));
    }

    public function test_a_damaged_chat_file_inside_the_archive_is_refused_before_anything_is_replaced(): void
    {
        [$club, $member, $attachment] = $this->clubWithAttachment();
        $archive = $this->archivePath($club, $this->service()->create($club));
        $damaged = $this->rewritten($archive, fn (ZipArchive $zip) => $zip->addFromString('files/private/'.$attachment->path, 'JPEG-BYTEZ'));
        $this->asTenant($club, fn () => $member->forceFill(['full_name' => 'بعد النسخة'])->save());
        Storage::disk('local')->put($attachment->path, 'NOW');

        $this->refused(fn () => $this->service()->restore($club, $damaged), 'backup.invalid', 'damaged');

        $this->assertSame('NOW', Storage::disk('local')->get($attachment->path));
        $this->asTenant($club, fn () => $this->assertSame('بعد النسخة', Member::find($member->id)->full_name));
        $this->assertSame([], Storage::disk('local')->allFiles(TenantBackupService::STAGING));
    }

    public function test_a_full_disk_refuses_the_backup_and_the_restore_of_chat_files_before_anything_is_written(): void
    {
        [$club, , $attachment] = $this->clubWithAttachment();
        $archive = $this->archivePath($club, $this->service()->create($club));
        Storage::disk('local')->put($attachment->path, 'NOW');
        $this->app->instance(DiskSpace::class, new class extends DiskSpace
        {
            public function free(string $path): ?int
            {
                return 1024 * 1024;
            }
        });

        $this->refused(fn () => $this->service()->create($club), 'backup.no_space', 'create');
        $this->refused(fn () => $this->service()->restore($club, $archive), 'backup.no_space', 'restore');

        $this->assertCount(1, $this->service()->list($club), 'no archive lost');
        $this->assertSame('NOW', Storage::disk('local')->get($attachment->path));
        $this->assertSame([], Storage::disk('local')->allFiles(TenantBackupService::STAGING));
    }

    public function test_a_restore_that_fails_while_placing_files_puts_every_chat_file_back_as_it_was(): void
    {
        $club = $this->club();
        $attachments = [];
        foreach (range(1, 4) as $i) {
            $attachments[] = $this->chatFile($club, 'whatsapp/'.$club->id.'/2026/11/doc'.$i.'.pdf', 'A-'.$i);
        }
        $archive = $this->archivePath($club, $this->service()->create($club));
        foreach ($attachments as $i => $a) {
            Storage::disk('local')->put($a->path, 'B-'.$i);
        }
        $this->app->instance(RestoreFiles::class, new class extends RestoreFiles
        {
            private int $moves = 0;

            public function move(string $fromDisk, string $from, string $toDisk, string $to): void
            {
                if (str_contains($to, 'whatsapp/') && ++$this->moves === 3) {
                    throw new RuntimeException('the disk stopped answering');
                }
                parent::move($fromDisk, $from, $toDisk, $to);
            }
        });

        $this->refused(fn () => $this->service()->restore($club, $archive), 'backup.restore_failed', 'failed half-way');

        foreach ($attachments as $i => $a) {
            $this->assertSame('B-'.$i, Storage::disk('local')->get($a->path), 'each chat file as it was before the restore');
        }
        $this->assertSame([], Storage::disk('local')->allFiles(TenantBackupService::STAGING));
    }

    public function test_chat_files_on_another_disk_are_read_from_it_and_put_back_on_it(): void
    {
        config(['services.whatsapp.media_disk' => 'chat']);
        Storage::fake('chat');
        $club = $this->club();
        $attachment = $this->chatFile($club, 'whatsapp/'.$club->id.'/2026/11/voice.ogg', 'OGG-BYTES', 'chat');
        Storage::disk('local')->put('whatsapp/'.$club->id.'/2026/11/stray.ogg', 'NOT-THE-CHAT-DISK');

        $archive = $this->archivePath($club, $this->service()->create($club));
        $this->assertContains('files/private/'.$attachment->path, $this->entries($archive));
        $this->assertNotContains('files/private/whatsapp/'.$club->id.'/2026/11/stray.ogg', $this->entries($archive), 'the chat disk only');

        Storage::disk('chat')->delete($attachment->path);
        $this->service()->restore($club, $archive);

        $this->assertSame('OGG-BYTES', Storage::disk('chat')->get($attachment->path));
        $this->assertFalse(Storage::disk('local')->exists($attachment->path), 'put back on the chat disk, not the private one');
        $this->assertSame(Storage::disk('chat')->path($attachment->path), app(MediaStore::class)->absolutePath($attachment->fresh()));
    }

    public function test_a_symbolic_link_in_a_clubs_chat_folder_is_never_followed_by_the_backup_or_written_through_by_a_restore(): void
    {
        [$club, , $attachment] = $this->clubWithAttachment();
        $other = $this->club();
        $root = rtrim(Storage::disk('local')->path(''), '/');
        Storage::disk('local')->put('whatsapp/'.$other->id.'/2026/11/theirs.jpg', 'THEIRS');
        Storage::disk('local')->put('whatsapp/'.$other->id.'/2025/01/old.jpg', 'THEIRS-OLD');
        // A file link and a folder link in this club's folder, both to the other club's files; an attachment names the first.
        symlink($root.'/whatsapp/'.$other->id.'/2026/11/theirs.jpg', $root.'/whatsapp/'.$club->id.'/2026/11/link.jpg');
        symlink($root.'/whatsapp/'.$other->id.'/2025', $root.'/whatsapp/'.$club->id.'/2025');
        $linked = $this->chatFile($club, 'whatsapp/'.$club->id.'/2026/11/link.jpg', 'THEIRS');
        Storage::disk('local')->put('whatsapp/'.$club->id.'/2026/11/link.jpg', 'THEIRS'); // (put writes through the link: the other club's file again)

        $e = $this->refused(fn () => $this->service()->create($club), 'backup.incomplete', 'a link is not the club\'s file');
        $this->assertStringContainsString((string) $linked->id, $e->getMessage());
        $kept = $this->service()->disk()->files($this->service()->directory($club).'/'.TenantBackupService::INCOMPLETE);
        $entries = $this->entries($this->service()->disk()->path($kept[0]));
        $this->assertSame(['data.json', 'files/private/'.$attachment->path, 'manifest.json'], $entries, 'neither link followed');

        // A restore whose target folder is a link to another club's folder writes nothing through it.
        unlink($root.'/whatsapp/'.$club->id.'/2026/11/link.jpg');
        $this->asTenant($club, fn () => Attachment::whereKey($linked->id)->delete());
        $archive = $this->archivePath($club, $this->service()->create($club));
        Storage::disk('local')->deleteDirectory('whatsapp/'.$club->id.'/2026');
        symlink($root.'/whatsapp/'.$other->id.'/2026', $root.'/whatsapp/'.$club->id.'/2026');
        $this->refused(fn () => $this->service()->restore($club, $archive), 'backup.invalid', 'through a folder link');
        $this->assertSame(['whatsapp/'.$other->id.'/2025/01/old.jpg', 'whatsapp/'.$other->id.'/2026/11/theirs.jpg'], Storage::disk('local')->allFiles('whatsapp/'.$other->id), 'nothing written into the other club\'s folder');
    }

    public function test_thousands_of_chat_files_and_a_large_one_are_archived_and_restored_in_bounded_memory(): void
    {
        $club = $this->club();
        $conversation = $this->asTenant($club, fn () => Conversation::create(['phone' => '96891112233', 'status' => 'open']));
        $message = $this->asTenant($club, fn () => Message::create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'type' => 'document', 'status' => 'received']));
        $rows = [];
        foreach (range(1, 3000) as $i) {
            $path = sprintf('whatsapp/%d/2026/%02d/f%05d.pdf', $club->id, 1 + $i % 12, $i);
            Storage::disk('local')->put($path, 'PDF-'.$i.'-'.str_repeat('x', 1000));
            $rows[] = ['tenant_id' => $club->id, 'message_id' => $message->id, 'kind' => 'document', 'mime' => 'application/pdf', 'size' => 1000, 'path' => $path, 'status' => Attachment::STORED, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('whatsapp_attachments')->insert($chunk);
        }
        $large = 'whatsapp/'.$club->id.'/2026/11/video.mp4';
        Storage::disk('local')->put($large, '');
        $out = fopen(Storage::disk('local')->path($large), 'wb');
        foreach (range(1, 64) as $mb) {
            fwrite($out, random_bytes(1 << 20));
        }
        fclose($out);
        $sha = hash_file('sha256', Storage::disk('local')->path($large));
        DB::table('whatsapp_attachments')->insert(['tenant_id' => $club->id, 'message_id' => $message->id, 'kind' => 'video', 'mime' => 'video/mp4', 'size' => 64 << 20, 'path' => $large, 'status' => Attachment::STORED, 'created_at' => now(), 'updated_at' => now()]);

        $base = memory_get_usage();
        memory_reset_peak_usage();
        $archive = $this->archivePath($club, $this->service()->create($club));
        $backupPeak = memory_get_peak_usage() - $base;
        $this->assertCount(3001 + 2, $this->entries($archive));

        Storage::disk('local')->deleteDirectory('whatsapp/'.$club->id);
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $this->service()->restore($club, $archive);
        $restorePeak = memory_get_peak_usage() - $base;

        $this->assertCount(3001, Storage::disk('local')->allFiles('whatsapp/'.$club->id));
        $this->assertSame($sha, hash_file('sha256', Storage::disk('local')->path($large)), 'the 64 MB file whole');
        fwrite(STDERR, sprintf("\n[memory] backup +%.1f MB, restore +%.1f MB for 3001 chat files (one of 64 MB)\n", $backupPeak / 1048576, $restorePeak / 1048576));
        $this->assertLessThan(48 * 1048576, $backupPeak, 'the backup never holds the files in memory');
        $this->assertLessThan(48 * 1048576, $restorePeak, 'nor does the restore');
    }
}
