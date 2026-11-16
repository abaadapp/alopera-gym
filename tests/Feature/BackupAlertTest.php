<?php

namespace Tests\Feature;

use App\Core\Backup\BackupHealth;
use App\Core\Backup\TenantBackupService;
use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Messaging\Models\Attachment;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The nightly backup can fail, or stop running, without a word (its output goes nowhere): whoever manages the club's
 * backups sees it on every panel page — an incomplete newest backup, or no sound one in more than 26 hours — with the
 * date of the last sound archive, until a sound one is made again. Never which files, nor their contents.
 */
class BackupAlertTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const PATH_MARK = 'secret-scan.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->travelTo(now()->setTime(3, 0)); // the nightly's time, Muscat
    }

    private function service(): TenantBackupService
    {
        return app(TenantBackupService::class);
    }

    /** The alert as the panel's pages get it (null when there is none). */
    private function alertSeenBy(User $user): ?array
    {
        $response = $this->actingAs($user)->get(route('club.dashboard'))->assertOk();

        return $response->viewData('page')['props']['backupAlert'] ?? null;
    }

    /** @return array{0: Tenant, 1: Attachment} */
    private function clubWithChatFile(): array
    {
        $club = $this->club();
        $path = 'whatsapp/'.$club->id.'/2026/11/'.self::PATH_MARK;
        Storage::disk('local')->put($path, 'PRIVATE-BYTES');

        return [$club, $this->asTenant($club, function () use ($path) {
            $conversation = Conversation::create(['phone' => '96891112233', 'status' => 'open']);
            $message = Message::create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'type' => 'image', 'status' => 'received']);

            return Attachment::create(['message_id' => $message->id, 'kind' => 'image', 'mime' => 'image/jpeg', 'size' => 13, 'path' => $path, 'status' => Attachment::STORED]);
        })];
    }

    public function test_a_sound_nightly_backup_shows_no_alert_for_a_day_and_two_hours(): void
    {
        [$club] = $this->clubWithChatFile();
        $this->service()->create($club);

        $this->assertNull($this->alertSeenBy($this->owner($club)));
        $this->travel(BackupHealth::STALE_HOURS)->hours();
        $this->assertNull($this->alertSeenBy($this->owner($club)), 'the next nightly is due, not late');
    }

    public function test_no_sound_backup_in_more_than_26_hours_alerts_with_the_last_ones_date_and_goes_once_one_is_made(): void
    {
        [$club] = $this->clubWithChatFile();
        $this->service()->create($club);
        $made = now()->format('Y-m-d H:i');

        $this->travel(BackupHealth::STALE_HOURS + 1)->hours(); // the nightly failed, or did not run
        $alert = $this->alertSeenBy($this->owner($club));
        $this->assertSame(['stale', $made, BackupHealth::STALE_HOURS + 1], [$alert['kind'], $alert['last_sound_at'], $alert['hours']]);
        $this->assertStringContainsString('آخر نسخة سليمة: '.$made, $alert['message']);

        $this->service()->create($club);
        $this->assertNull($this->alertSeenBy($this->owner($club)), 'gone with the next sound backup');
    }

    public function test_an_incomplete_backup_alerts_that_it_cannot_bring_every_file_back_and_goes_once_a_sound_one_is_made(): void
    {
        [$club, $attachment] = $this->clubWithChatFile();
        $this->service()->create($club);
        $sound = now()->format('Y-m-d H:i');
        Storage::disk('local')->delete($attachment->path);

        $this->travel(1)->days();
        try {
            $this->service()->create($club);
            $this->fail('an incomplete backup went through');
        } catch (BusinessRuleException $e) {
            $this->assertSame('backup.incomplete', $e->rule);
        }

        $alert = $this->alertSeenBy($this->owner($club));
        $this->assertSame(['incomplete', $sound, 1], [$alert['kind'], $alert['last_sound_at'], $alert['missing_attachments']]);
        $this->assertStringContainsString('النسخة الناقصة لا يُعتمد عليها لاستعادة جميع الملفات', $alert['message']);
        $this->assertStringContainsString('آخر نسخة سليمة: '.$sound, $alert['message']);
        $this->assertStringNotContainsString(self::PATH_MARK, json_encode($alert, JSON_UNESCAPED_UNICODE), 'never which file');
        $this->assertStringNotContainsString('PRIVATE-BYTES', json_encode($alert, JSON_UNESCAPED_UNICODE));

        // Within the 26 hours an incomplete one still alerts; the file back, the next backup is sound and the alert goes.
        Storage::disk('local')->put($attachment->path, 'PRIVATE-BYTES');
        $this->travel(1)->hours();
        $this->service()->create($club);
        $this->assertNull($this->alertSeenBy($this->owner($club)));
        $this->assertCount(2, $this->service()->list($club));
    }

    public function test_a_club_without_any_sound_backup_is_alerted_after_26_hours_not_before(): void
    {
        [$club] = $this->clubWithChatFile();

        $this->assertNull($this->alertSeenBy($this->owner($club)), 'a new club: its first nightly is not due yet');
        $this->travel(BackupHealth::STALE_HOURS + 1)->hours();
        $alert = $this->alertSeenBy($this->owner($club));
        $this->assertSame(['none', null], [$alert['kind'], $alert['last_sound_at']]);
    }

    public function test_only_whoever_manages_the_backups_sees_the_alert(): void
    {
        [$club] = $this->clubWithChatFile();
        $finance = $this->staff($club, 'finance');
        $this->assertFalse($finance->hasPermission('backups.manage'));
        $this->travel(BackupHealth::STALE_HOURS + 1)->hours();

        $this->assertSame('none', $this->alertSeenBy($this->owner($club))['kind']);
        $this->assertNull($this->alertSeenBy($finance));
        $other = $this->club('Other');
        $this->service()->create($other);
        $this->assertNull($this->alertSeenBy($this->owner($other)), 'another club\'s backups are its own');
    }

    public function test_the_panels_backup_button_never_says_success_for_an_incomplete_backup(): void
    {
        [$club, $attachment] = $this->clubWithChatFile();
        Storage::disk('local')->delete($attachment->path);

        $response = $this->actingAs($this->owner($club))->from(route('club.backups.index'))->post(route('club.backups.store'));

        $response->assertRedirect(route('club.backups.index'))->assertSessionMissing('success')->assertSessionHasErrors('error');
        $error = session('errors')->first('error');
        $this->assertStringContainsString('النسخة الاحتياطية ناقصة', $error);
        $this->assertStringContainsString('لا تُحسب ضمن النسخ السليمة', $error);
        $this->assertStringNotContainsString(self::PATH_MARK, $error);
        $this->assertSame([], $this->service()->list($club), 'no sound archive listed');
        $this->assertSame('incomplete', $this->alertSeenBy($this->owner($club))['kind']);
    }
}
