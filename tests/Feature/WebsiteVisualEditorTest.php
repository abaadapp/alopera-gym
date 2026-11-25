<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Website\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * «التعديل المرئي» (owner's decision, 2026-11-24): the site itself in the editor, drawn from the edits as they are
 * made — nothing is stored by drawing it, and visitors still see only what was published.
 */
class WebsiteVisualEditorTest extends TestCase
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

    public function test_the_page_is_drawn_from_unsaved_edits_and_nothing_is_stored(): void
    {
        $club = $this->club();
        [$doc, $version] = $this->editor($club);
        $hero = collect($doc['sections'])->search(fn ($s) => $s['type'] === 'hero');
        $doc['sections'][$hero]['data']['title'] = 'عنوان لم يُحفظ';

        $this->actingAs($this->owner($club))->postJson(route('club.website.render'), ['doc' => $doc])->assertOk()
            ->assertJsonPath('page.sections.0.data.title', 'عنوان لم يُحفظ')
            ->assertJsonStructure(['page' => ['sections'], 'chrome' => ['header', 'footer', 'theme'], 'site', 'waiting']);

        $this->asTenant($club, fn () => $this->assertSame($version, SiteSetting::first()->draft_version ?? 0));
        auth()->logout();
        $this->get(route('site.home', ['club' => $club->slug]))->assertInertia(fn (AssertableInertia $p) => $p->where('page.sections.0.data.title', fn ($t) => $t !== 'عنوان لم يُحفظ'));
    }

    public function test_a_document_that_would_not_be_saved_answers_with_its_errors(): void
    {
        $club = $this->club();
        [$doc] = $this->editor($club);
        $doc['contact']['email'] = 'not-an-email';

        $this->actingAs($this->owner($club))->postJson(route('club.website.render'), ['doc' => $doc])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['doc.contact.email']]);
    }

    public function test_only_who_manages_the_website_draws_it_and_the_canvas_is_the_staff_preview(): void
    {
        $club = $this->club();
        [$doc] = $this->editor($club);

        $this->actingAs($this->staff($club, 'reception'))->postJson(route('club.website.render'), ['doc' => $doc])->assertForbidden();
        auth()->logout();
        $this->postJson(route('club.website.render'), ['doc' => $doc])->assertUnauthorized();

        $this->actingAs($this->owner($club))->get(route('club.website.preview', ['canvas' => 1]))->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Site/Home')->where('canvas', true));
    }

    public function test_the_editors_own_saving_is_quiet_and_a_save_asked_for_says_so(): void
    {
        $club = $this->club();
        [$doc, $version] = $this->editor($club);

        $this->actingAs($this->owner($club))->put(route('club.website.draft.save'), ['doc' => $doc, 'version' => $version, 'quiet' => true])
            ->assertSessionHasNoErrors()->assertSessionMissing('success');
        $this->actingAs($this->owner($club))->put(route('club.website.draft.save'), ['doc' => $doc, 'version' => $version + 1])
            ->assertSessionHas('success');
    }
}
