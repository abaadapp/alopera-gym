<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Modules\Website\Models\SiteRevision;
use App\Modules\Website\Models\SiteSetting;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/** Two «نشر التغييرات» clicks (or two windows) at the same moment, in separate processes on PostgreSQL. */
class WebsitePublishConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    public function test_two_publishes_at_once_make_one_version_and_two_saves_at_once_keep_one(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $props = $this->actingAs($owner)->get(route('club.website.edit'))->viewData('page')['props'];
        [$doc, $version] = [$props['doc'], $props['version']];
        $doc['sections'][0]['data']['title'] = 'نشر واحد';
        $this->actingAs($owner)->put(route('club.website.draft.save'), ['doc' => $doc, 'version' => $version])->assertSessionHasNoErrors();

        $publish = function () use ($owner, $version) {
            $this->actingAs($owner)->post(route('club.website.publish'), ['version' => $version + 1]);
            $errors = session('errors');

            return $errors instanceof ViewErrorBag ? $errors->all() : [];
        };
        $results = $this->concurrently([$publish, $publish]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame(1, $this->asTenant($club, fn () => SiteRevision::count()), 'one click or two, one published version');
        $this->assertSame('نشر واحد', $this->asTenant($club, fn () => SiteSetting::first()->published['sections'][0]['data']['title']));

        // Two windows saving different drafts from the same version at once: one is kept, the other refused
        // (the draft moves on by exactly one version and one save is recorded).
        $save = fn (string $title) => function () use ($owner, $doc, $version, $title) {
            $doc['sections'][0]['data']['title'] = $title;

            return $this->actingAs($owner)->put(route('club.website.draft.save'), ['doc' => $doc, 'version' => $version + 1])->getStatusCode();
        };
        $results = $this->concurrently([$save('النافذة الأولى'), $save('النافذة الثانية')]);

        $this->assertSame([302, 302], array_column($results, 'value'), json_encode($results));
        $site = $this->asTenant($club, fn () => SiteSetting::first());
        $this->assertSame($version + 2, $site->draft_version, 'one of the two saves was refused');
        $this->assertSame(2, AuditLog::withoutGlobalScopes()->where('event', 'website.draft_saved')->count());
        $this->assertContains($site->draft['sections'][0]['data']['title'], ['النافذة الأولى', 'النافذة الثانية']);
    }
}
