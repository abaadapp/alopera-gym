<?php

namespace Tests\Feature;

use App\Modules\Website\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** The owner arranges the home page sections and the club identity; the public site follows. */
class WebsiteDesignTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_by_default_the_home_page_shows_every_section_in_its_usual_order(): void
    {
        $club = $this->club();

        $this->get(route('site.home', ['club' => $club->slug]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('sections', array_keys(SiteSetting::SECTIONS)));
    }

    public function test_the_owner_reorders_and_hides_sections_and_the_public_page_follows(): void
    {
        $club = $this->club();
        $layout = [['key' => 'plans', 'visible' => true], ['key' => 'trainers', 'visible' => true], ['key' => 'gallery', 'visible' => false]];

        $this->actingAs($this->owner($club))->put(route('club.website.sections.update'), ['sections' => $layout])->assertSessionHasNoErrors();

        $this->get(route('site.home', ['club' => $club->slug]))->assertInertia(fn ($page) => $page->where('sections', fn ($sections) => collect($sections)->take(2)->all() === ['plans', 'trainers']
            && ! collect($sections)->contains('gallery') && collect($sections)->contains('news')));
    }

    public function test_the_owner_sets_logo_colours_and_no_one_else_can(): void
    {
        $club = $this->club();

        $this->actingAs($this->staff($club, 'manager'))->post(route('club.website.branding.update'), ['primary_color' => '#000000', 'secondary_color' => '#ffffff'])->assertForbidden();
        $this->actingAs($this->staff($club, 'manager'))->put(route('club.website.sections.update'), ['sections' => []])->assertForbidden();

        $this->actingAs($this->owner($club))->post(route('club.website.branding.update'), ['primary_color' => '#0f766e', 'secondary_color' => '#99f6e4'])->assertSessionHasNoErrors();
        $this->assertSame('#0f766e', $club->fresh()->primary_color);
        $this->actingAs($this->owner($club))->post(route('club.website.branding.update'), ['primary_color' => 'red', 'secondary_color' => '#99f6e4'])->assertSessionHasErrors('primary_color');
    }
}
