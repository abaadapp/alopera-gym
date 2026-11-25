<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Website\Content\ClubContact;
use App\Modules\Website\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The website and the panel say the same thing (owner's decision, 2026-11-24): the club's phone, email and address
 * are one set of facts whichever of the two places the owner edits them in (ClubContact).
 */
class SiteAndPanelInStepTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    private function editor(Tenant $club): array
    {
        $props = $this->actingAs($this->owner($club))->get(route('club.website.edit'))->assertOk()->viewData('page')['props'];

        return [$props['doc'], $props['version']];
    }

    private function publishDoc(Tenant $club, array $doc, int $version): void
    {
        $this->actingAs($this->owner($club))->put(route('club.website.draft.save'), ['doc' => $doc, 'version' => $version])->assertSessionHasNoErrors();
        $this->actingAs($this->owner($club))->post(route('club.website.publish'), ['version' => $version + 1])->assertSessionHasNoErrors();
    }

    /** @param array<string, mixed> $extra */
    private function saveSettings(Tenant $club, array $extra): TestResponse
    {
        return $this->actingAs($this->owner($club))->put(route('club.settings.update'), [
            'name' => $club->name, 'primary_color' => '#9d174d', 'secondary_color' => '#f9a8d4', 'rules' => [],
        ] + $extra);
    }

    public function test_the_club_settings_reach_the_site_at_once(): void
    {
        $club = $this->club();
        [$doc, $v] = $this->editor($club);
        $doc['contact']['phone'] = '96811111111';
        $this->publishDoc($club, $doc, $v);

        $this->saveSettings($club, ['phone' => '+968 2400 9999', 'email' => 'Hello@Club.om', 'address' => 'مسقط، الخوير'])->assertSessionHasNoErrors();

        auth()->logout();
        $this->get(route('site.contact', ['club' => $club->slug]))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('site.contact_phone', '96824009999')
            ->where('site.contact_email', 'Hello@Club.om')
            ->where('site.address', 'مسقط، الخوير'));
        // The editor opens on the same values, and an editor left open on the old draft is told to reload.
        [$draft, $after] = $this->editor($club);
        $this->assertSame('96824009999', $draft['contact']['phone']);
        $this->assertSame($v + 2, $after);
        $this->actingAs($this->owner($club))->put(route('club.website.draft.save'), ['doc' => $doc, 'version' => $v + 1])->assertSessionHasErrors('version');
        $this->asTenant($club, fn () => $this->assertFalse(SiteSetting::first()->published_draft_version !== SiteSetting::first()->draft_version, 'Nothing unpublished appears.'));
    }

    public function test_publishing_the_site_updates_the_club_settings(): void
    {
        $club = $this->club();
        [$doc, $v] = $this->editor($club);
        $doc['contact']['phone'] = '+968 9123 4567';
        $doc['contact']['email'] = 'site@club.om';
        $doc['contact']['address'] = 'صحار';
        $this->publishDoc($club, $doc, $v);

        $club->refresh();
        $this->assertSame('96891234567', $club->phone);
        $this->assertSame('site@club.om', $club->email);
        $this->assertSame('صحار', $club->address);
    }

    public function test_an_empty_side_is_filled_never_emptied_and_the_settings_win_a_difference(): void
    {
        $club = $this->club();
        [$doc, $v] = $this->editor($club);
        $doc['contact']['phone'] = '96822222222';
        $doc['contact']['email'] = '';
        $doc['contact']['address'] = 'عنوان الموقع';
        $this->publishDoc($club, $doc, $v);
        // As it was before the two were kept equal: the settings say something else, and have no address.
        $club->forceFill(['phone' => '96833333333', 'email' => 'club@club.om', 'address' => null])->save();

        $this->asTenant($club, fn () => ClubContact::sync($club->fresh()));

        $club->refresh();
        $this->assertSame('96833333333', $club->phone);
        $this->assertSame('عنوان الموقع', $club->address);
        $published = $this->asTenant($club, fn () => SiteSetting::first()->published);
        $this->assertSame('96833333333', $published['contact']['phone']);
        $this->assertSame('club@club.om', $published['contact']['email']);
        $this->assertSame('عنوان الموقع', $published['contact']['address']);
    }

    public function test_the_one_time_migration_agrees_the_two_as_the_sync_does(): void
    {
        $club = $this->club();
        [$doc, $v] = $this->editor($club);
        $doc['contact']['phone'] = '96822222222';
        $doc['contact']['email'] = '';
        $doc['contact']['address'] = 'عنوان الموقع';
        $this->publishDoc($club, $doc, $v);
        $club->forceFill(['phone' => '+968 3333 3333', 'email' => 'club@club.om', 'address' => null])->save();

        (require base_path('app/Modules/Website/Database/migrations/2026_11_24_000200_sync_club_contact_with_site.php'))->up();

        $club->refresh();
        $this->assertSame('+968 3333 3333', $club->phone);
        $this->assertSame('عنوان الموقع', $club->address);
        $site = $this->asTenant($club, fn () => SiteSetting::first());
        $this->assertSame(['96833333333', 'club@club.om', 'عنوان الموقع'], [$site->published['contact']['phone'], $site->published['contact']['email'], $site->published['contact']['address']]);
        $this->assertSame('96833333333', $site->draft['contact']['phone']);
        $this->assertSame($site->draft_version, $site->published_draft_version);
    }

    public function test_the_map_falls_back_to_the_clubs_location_and_the_featured_tag_is_the_owners(): void
    {
        $club = $this->club();
        $club->forceFill(['latitude' => 23.5880, 'longitude' => 58.3829])->save();
        $this->plan($club, ['is_public' => true, 'is_featured' => true]);
        [$doc, $v] = $this->editor($club);
        $doc['contact']['map_url'] = '';
        foreach ($doc['sections'] as $i => $section) {
            if ($section['type'] === 'plans') {
                $doc['sections'][$i]['data']['featured_label'] = 'الأفضل قيمة';
            }
        }
        $this->publishDoc($club, $doc, $v);

        auth()->logout();
        $this->get(route('site.plans', ['club' => $club->slug]))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('site.map_url', 'https://www.google.com/maps?q=23.588,58.3829')
            ->where('chrome.featured_label', 'الأفضل قيمة'));
    }
}
