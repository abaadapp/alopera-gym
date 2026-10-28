<?php

namespace Tests\Feature;

use App\Core\Backup\TenantBackupService;
use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Spec 7.3: periodic backup and a restore that is actually tested. */
class BackupRestoreTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_backup_then_restore_brings_the_club_back_exactly(): void
    {
        $club = $this->club();
        $other = $this->club('Other');
        $member = $this->member($club, ['full_name' => 'Original Name']);
        $this->activeMembership($club, $member);
        $this->activeMembership($other, $this->member($other));

        $service = app(TenantBackupService::class);
        $file = $service->create($club);
        $before = $this->asTenant($club, fn () => [Member::count(), Membership::count(), Invoice::count(), Invoice::first()->number]);

        // Damage: rename, add a member, cancel the membership.
        $this->asTenant($club, function () use ($member) {
            $member->update(['full_name' => 'Changed']);
            $this->member(app(TenantContext::class)->require());
            Membership::query()->update(['status' => 'cancelled']);
        });

        $counts = $service->restore($club, Storage::disk('local')->path($service->path($club, $file)));

        $this->assertGreaterThan(0, $counts['gym_members']);
        $this->asTenant($club, function () use ($before, $member) {
            $this->assertSame($before, [Member::count(), Membership::count(), Invoice::count(), Invoice::first()->number]);
            $this->assertSame('Original Name', $member->fresh()->full_name);
            $this->assertSame('active', Membership::first()->status);
            // Sequences continue after restore (no duplicate key on the next insert).
            $this->member(app(TenantContext::class)->require());
        });
        $this->assertSame(1, $this->asTenant($other, fn () => Member::count()), 'Other clubs are untouched.');
    }

    public function test_an_archive_from_another_club_is_refused(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $this->member($a);
        $service = app(TenantBackupService::class);
        $file = $service->create($a);

        $this->expectException(BusinessRuleException::class);
        $service->restore($b, Storage::disk('local')->path($service->path($a, $file)));
    }

    public function test_owner_can_create_and_download_backups_and_restore_requires_typed_confirmation(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->member($club);

        $this->actingAs($owner)->post(route('club.backups.store'))->assertSessionHas('success');
        $name = app(TenantBackupService::class)->list($club)[0]['name'];
        $this->actingAs($owner)->get(route('club.backups.download', $name))->assertOk();
        $this->actingAs($owner)->get(route('club.backups.download', '../../.env'))->assertNotFound();

        $path = Storage::disk('local')->path(app(TenantBackupService::class)->path($club, $name));
        $upload = new UploadedFile($path, $name, 'application/zip', null, true);
        $this->actingAs($owner)->post(route('club.backups.restore'), ['archive' => $upload, 'confirm' => 'wrong'])->assertSessionHasErrors('confirm');
    }

    public function test_scheduled_backup_command_covers_every_club(): void
    {
        $this->club('A');
        $this->club('B');
        $this->artisan('backup:tenants')->assertSuccessful();
        $this->assertCount(2, Storage::disk('local')->directories('backups/tenants'));
    }
}
