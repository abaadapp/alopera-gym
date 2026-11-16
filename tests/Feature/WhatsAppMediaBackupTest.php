<?php

namespace Tests\Feature;

use App\Core\Backup\TenantBackupService;
use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Modules\Gym\Models\Member;
use App\Modules\Messaging\Models\Attachment;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Services\MediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;
use ZipArchive;

/**
 * A chat attachment's file is named by its row's path. Whatever a restored archive says, a club's attachments open only
 * files inside its own chat folder (whatsapp/{club}/…): an archive whose attachment rows name another club's files, an
 * archive or anything else on the disk is refused before anything is replaced, and MediaStore never opens such a path.
 * Archives as every version writes them (paths inside the club's folder) restore as before.
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
}
