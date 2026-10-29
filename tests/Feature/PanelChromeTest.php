<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Documents\Models\DocumentAlert;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Website\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** The top bar's bell and user section: what each role is shown, read from the club's own screens. */
class PanelChromeTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function document(Tenant $club, User $owner, string $name, bool $confidential = false): void
    {
        $this->actingAs($owner)->post(route('club.business-documents.store'), [
            'name' => $name, 'expiry_date' => today()->addDays(5)->toDateString(), 'is_confidential' => $confidential,
            'file' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();
    }

    /** @return array{count: int, items: list<array<string, mixed>>}|null */
    private function bell(User $user, string $route = 'club.checkins.index'): ?array
    {
        $bell = null;
        $this->actingAs($user)->get(route($route))->assertOk()->assertInertia(function ($page) use (&$bell) {
            $bell = $page->toArray()['props']['notifications'];
        });

        return $bell;
    }

    public function test_the_bell_lists_what_each_role_may_act_on(): void
    {
        Storage::fake('local');
        $club = $this->club();
        $owner = $this->owner($club);
        $this->document($club, $owner, 'السجل التجاري');
        Artisan::call('documents:alerts');
        $this->asTenant($club, function () {
            Conversation::create(['phone' => '96891234567', 'status' => 'open', 'unread_count' => 3]);
            ContactMessage::create(['name' => 'زائر', 'message' => 'استفسار عن الباقات']);
        });

        $bell = $this->bell($owner);
        $this->assertSame(1 + 3 + 1, $bell['count']);
        $this->assertSame(['document-alert-', 'inbox', 'contact'], array_map(fn ($i) => preg_replace('/\d+$/', '', $i['key']), $bell['items']));
        $this->assertStringContainsString('السجل التجاري', $bell['items'][0]['text']);
        $this->assertNotNull($bell['items'][0]['dismiss']);

        // Reception answers the chats; documents and the website's messages are not theirs.
        $reception = $this->bell($this->staff($club, 'reception'));
        $this->assertSame(['inbox'], array_column($reception['items'], 'key'));
        $this->assertSame(3, $reception['count']);

        // The trainer has none of these.
        $this->assertSame(['count' => 0, 'items' => []], $this->bell($this->staff($club, 'trainer'), 'club.trainer'));
    }

    public function test_a_document_alert_can_be_marked_seen_from_the_bell(): void
    {
        Storage::fake('local');
        $club = $this->club();
        $owner = $this->owner($club);
        $this->document($club, $owner, 'الترخيص البلدي');
        Artisan::call('documents:alerts');

        $item = $this->bell($owner)['items'][0];
        $this->actingAs($owner)->post($item['dismiss'])->assertRedirect();
        $this->asTenant($club, fn () => $this->assertNotNull(DocumentAlert::firstOrFail()->acknowledged_at));
        $this->assertSame(0, $this->bell($owner)['count']);
    }

    public function test_confidential_document_alerts_and_other_clubs_stay_out_of_the_bell(): void
    {
        Storage::fake('local');
        $club = $this->club('A');
        $owner = $this->owner($club);
        $this->document($club, $owner, 'عقد الإيجار', confidential: true);
        Artisan::call('documents:alerts');

        $this->assertSame(1, $this->bell($owner)['count']);
        $this->assertSame(0, $this->bell($this->staff($club, 'manager'))['count'], 'the manager does not see confidential documents');

        $other = $this->club('B');
        $this->assertSame(0, $this->bell($this->owner($other))['count']);
    }

    public function test_the_user_section_carries_the_branch(): void
    {
        $club = $this->club();
        $branch = $this->asTenant($club, fn () => Branch::create(['name' => 'فرع الخوض', 'is_active' => true]));
        $user = $this->staff($club, 'reception', ['branch_id' => $branch->id]);

        $this->actingAs($user)->get(route('club.reception'))->assertInertia(fn ($page) => $page
            ->where('auth.user.branch', 'فرع الخوض')->where('auth.user.name', $user->name));
    }

    public function test_the_bell_is_only_in_the_club_panel(): void
    {
        $club = $this->club();
        $this->actingAs($this->owner($club))->get(route('site.home', ['club' => $club->slug]))
            ->assertInertia(fn ($page) => $page->where('notifications', null));
    }
}
