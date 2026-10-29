<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Documents\Models\BusinessDocument;
use App\Modules\Documents\Models\DocumentAlert;
use App\Modules\Documents\Models\DocumentRenewal;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Messaging\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** The business's documents: files, expiry alerts once per threshold, renewals with archive, access, isolation. */
class DocumentsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function addDocument(Tenant $club, User $user, array $data = []): BusinessDocument
    {
        $this->actingAs($user)->post(route('club.business-documents.store'), $data + [
            'name' => 'السجل التجاري', 'reference_number' => 'CR-1234', 'issuer' => 'وزارة التجارة',
            'issue_date' => today()->subYear()->toDateString(), 'expiry_date' => today()->addDays(10)->toDateString(), 'renewal_months' => 12,
            'file' => UploadedFile::fake()->create('cr.pdf', 120, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        return $this->asTenant($club, fn () => BusinessDocument::latest('id')->firstOrFail());
    }

    private function roleWith(Tenant $club, array $permissions): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.uniqid(), 'name' => 'Custom', 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception');
        $user->forceFill(['role_id' => $role->id])->save();

        return $user;
    }

    public function test_the_owner_adds_a_document_with_its_file_on_the_private_disk(): void
    {
        $club = $this->club();
        $doc = $this->addDocument($club, $this->owner($club));

        $this->assertSame('CR-1234', $doc->reference_number);
        Storage::disk('local')->assertExists($doc->file_path);
        $this->assertStringStartsWith('tenants/'.$club->id.'/documents/', $doc->file_path);
        $this->actingAs($this->owner($club))->get(route('club.business-documents.download', $doc))->assertOk();
    }

    public function test_alerts_are_raised_once_per_threshold_and_shown_on_the_dashboard(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $doc = $this->addDocument($club, $owner);

        Artisan::call('documents:alerts');
        Artisan::call('documents:alerts');
        $this->asTenant($club, function () use ($doc) {
            $this->assertSame(1, DocumentAlert::count(), 'the second run raises nothing');
            $this->assertSame(15, DocumentAlert::first()->threshold_days, 'ten days left: the 15-day alert');
            // Seven days left: the next threshold, once; expired: the last one.
            app(DocumentService::class)->raiseAlerts(CarbonImmutable::today()->addDays(3));
            app(DocumentService::class)->raiseAlerts(CarbonImmutable::today()->addDays(4));
            app(DocumentService::class)->raiseAlerts(CarbonImmutable::today()->addDays(11));
            $this->assertSame([15, 7, 0], DocumentAlert::orderBy('id')->pluck('threshold_days')->all());
            $this->assertSame($doc->id, DocumentAlert::first()->document_id);
        });

        $this->actingAs($owner)->get(route('club.dashboard'))->assertInertia(fn ($page) => $page->has('documents.alerts', 3)->where('documents.expiring', 1));
        $this->actingAs($owner)->get(route('club.business-documents.index', ['view' => 'expiring']))->assertInertia(fn ($page) => $page->has('documents.data', 1)->where('stats.alerts', 3));

        $alert = $this->asTenant($club, fn () => DocumentAlert::first());
        $this->actingAs($owner)->post(route('club.business-documents.alerts.acknowledge', $alert));
        $this->asTenant($club, fn () => $this->assertNotNull($alert->fresh()->acknowledged_at));
    }

    public function test_renewing_keeps_the_previous_version_and_closes_old_alerts(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $doc = $this->addDocument($club, $owner);
        $oldPath = $doc->file_path;
        Artisan::call('documents:alerts');

        $this->actingAs($owner)->post(route('club.business-documents.renew', $doc), ['expiry_date' => today()->subDay()->toDateString()])->assertSessionHasErrors('expiry_date');
        $this->actingAs($owner)->post(route('club.business-documents.renew', $doc), [
            'expiry_date' => today()->addYear()->toDateString(), 'reference_number' => 'CR-1234-B', 'notes' => 'Renewed online',
            'file' => UploadedFile::fake()->create('cr-2027.pdf', 90, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($doc, $oldPath, $owner) {
            $doc->refresh();
            $renewal = DocumentRenewal::firstOrFail();
            $this->assertSame(today()->addYear()->toDateString(), $doc->expiry_date->toDateString());
            $this->assertSame('CR-1234-B', $doc->reference_number);
            $this->assertNotSame($oldPath, $doc->file_path);
            $this->assertSame($oldPath, $renewal->previous_file_path);
            $this->assertSame(today()->addDays(10)->toDateString(), $renewal->previous_expiry_date->toDateString());
            $this->assertSame($owner->id, $renewal->renewed_by);
            $this->assertSame(0, DocumentAlert::whereNull('acknowledged_at')->count(), 'alerts of the old date are closed');
            Storage::disk('local')->assertExists($oldPath);
            Storage::disk('local')->assertExists($doc->file_path);
        });

        $renewal = $this->asTenant($club, fn () => DocumentRenewal::firstOrFail());
        $this->actingAs($owner)->get(route('club.business-documents.renewals'))->assertOk()->assertInertia(fn ($page) => $page->has('renewals.data', 1));
        $this->actingAs($owner)->get(route('club.business-documents.renewals.download', $renewal))->assertOk();
    }

    public function test_access_follows_permissions_and_confidentiality(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $viewer = $this->roleWith($club, ['documents.view']);
        $secret = $this->addDocument($club, $owner, ['name' => 'عقد الإيجار', 'is_confidential' => true]);
        $public = $this->addDocument($club, $owner, ['name' => 'الترخيص البلدي']);

        $this->actingAs($this->staff($club, 'reception'))->get(route('club.business-documents.index'))->assertForbidden();

        $this->actingAs($viewer)->get(route('club.business-documents.index'))->assertOk()->assertInertia(fn ($page) => $page->has('documents.data', 1)->where('documents.data.0.name', 'الترخيص البلدي'));
        $this->actingAs($viewer)->get(route('club.business-documents.download', $secret))->assertNotFound();
        $this->actingAs($viewer)->get(route('club.business-documents.download', $public))->assertOk();
        $this->actingAs($viewer)->post(route('club.business-documents.renew', $public), ['expiry_date' => today()->addYear()->toDateString()])->assertForbidden();
        $this->actingAs($viewer)->post(route('club.business-documents.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('club.business-documents.destroy', $public))->assertForbidden();

        // The manager runs documents but does not see confidential ones, unless responsible for one.
        $manager = $this->staff($club, 'manager');
        $this->actingAs($manager)->get(route('club.business-documents.download', $secret))->assertNotFound();
        $this->asTenant($club, fn () => $secret->forceFill(['responsible_user_id' => $manager->id])->save());
        $this->actingAs($manager)->get(route('club.business-documents.download', $secret))->assertOk();
        $this->actingAs($manager)->post(route('club.business-documents.renew', $secret), ['expiry_date' => today()->addYear()->toDateString()])->assertSessionHasNoErrors();

        // Someone without the confidential permission cannot make a document confidential.
        $this->actingAs($manager)->post(route('club.business-documents.store'), ['name' => 'Staff certificate', 'is_confidential' => true])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertFalse(BusinessDocument::where('name', 'Staff certificate')->firstOrFail()->is_confidential));
    }

    public function test_documents_of_two_clubs_stay_apart_and_member_documents_are_separate(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $doc = $this->addDocument($a, $this->owner($a));

        $ownerB = $this->owner($b);
        $this->actingAs($ownerB)->get(route('club.business-documents.download', $doc))->assertNotFound();
        $this->actingAs($ownerB)->post(route('club.business-documents.renew', $doc), ['expiry_date' => today()->addYear()->toDateString()])->assertNotFound();
        $this->actingAs($ownerB)->get(route('club.business-documents.index'))->assertInertia(fn ($page) => $page->has('documents.data', 0));

        // Members' documents keep their own address and screens.
        $this->assertStringEndsWith('/app/documents/1', route('club.documents.download', 1));
    }

    public function test_whatsapp_alerts_are_not_sent_without_a_real_integration(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $club->forceFill(['settings' => array_merge($club->settings ?? [], ['documents' => ['alert_days' => [30, 7], 'whatsapp' => true]])])->save();
        $this->addDocument($club, $owner, ['responsible_user_id' => $owner->id]);

        Artisan::call('documents:alerts');
        $this->asTenant($club, function () {
            $alert = DocumentAlert::firstOrFail();
            $this->assertSame(30, $alert->threshold_days);
            $this->assertSame('skipped:whatsapp_off', $alert->whatsapp_status);
            $this->assertSame(0, Message::count());
        });
    }

    public function test_the_documents_screens_open(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->addDocument($club, $owner);
        $this->addDocument($club, $owner, ['name' => 'وثيقة التأمين', 'expiry_date' => today()->subDay()->toDateString()]);
        Artisan::call('documents:alerts');
        $this->actingAs($owner)->post(route('club.business-documents.renew', BusinessDocument::withoutGlobalScopes()->firstOrFail()), ['expiry_date' => today()->addYears(2)->toDateString()]);
        $this->addDocument($club, $owner, ['name' => 'عقد الإيجار']);
        $this->actingAs($owner)->post(route('club.business-documents.renew', BusinessDocument::withoutGlobalScopes()->latest('id')->firstOrFail()), ['expiry_date' => today()->addYears(2)->toDateString()]);
        $this->actingAs($owner)->get(route('club.dashboard'))->assertOk();
        foreach ([['club.business-documents.index', []], ['club.business-documents.index', ['view' => 'expiring']], ['club.business-documents.index', ['view' => 'expired']], ['club.business-documents.renewals', []], ['club.document-types.index', []], ['club.settings.documents', []]] as [$name, $params]) {
            $this->actingAs($owner)->get(route($name, $params))->assertOk();
        }
        $this->actingAs($owner)->put(route('club.settings.documents.update'), ['alert_days' => [45, 10, 1], 'whatsapp' => false])->assertSessionHasNoErrors();
        $this->assertSame([45, 10, 1], DocumentService::settings($club->fresh())['alert_days']);
    }
}
