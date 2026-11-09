<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** The owner arranges the home page sections and the club identity; the public site follows. */
class WebsiteDesignTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_by_default_the_home_page_opens_with_the_approved_design(): void
    {
        $club = $this->club();

        $this->get(route('site.home', ['club' => $club->slug]))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Site/Home')->where('page.sections.0.type', 'hero')
                ->where('page.sections', fn ($sections) => collect($sections)->last()['type'] === 'contact'));
    }

    public function test_the_old_sections_address_opens_the_website_editor(): void
    {
        $club = $this->club();

        $this->actingAs($this->owner($club))->get(route('club.website.sections'))->assertRedirect(route('club.website.edit'));
    }

    public function test_the_owner_sets_logo_colours_and_no_one_else_can(): void
    {
        $club = $this->club();

        $this->actingAs($this->staff($club, 'manager'))->post(route('club.website.branding.update'), ['primary_color' => '#000000', 'secondary_color' => '#ffffff'])->assertForbidden();
        $this->actingAs($this->staff($club, 'manager'))->put(route('club.website.draft.save'), ['doc' => [], 'version' => 0])->assertForbidden();

        $this->actingAs($this->owner($club))->post(route('club.website.branding.update'), ['primary_color' => '#0f766e', 'secondary_color' => '#99f6e4'])->assertSessionHasNoErrors();
        $this->assertSame('#0f766e', $club->fresh()->primary_color);
        $this->actingAs($this->owner($club))->post(route('club.website.branding.update'), ['primary_color' => 'red', 'secondary_color' => '#99f6e4'])->assertSessionHasErrors('primary_color');
    }
}
