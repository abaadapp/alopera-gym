<?php

namespace Tests\Feature;

use App\Modules\Gym\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The sitemap lists the site's pages by the rule its menu uses: a page with nothing to show yet (no plans, programs,
 * trainers, classes or news published) is left out, as the menu leaves it out; it appears once it has content.
 */
class SitemapPagesTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function locs($club): array
    {
        preg_match_all('#<loc>([^<]+)</loc>#', $this->get(route('site.sitemap', ['club' => $club->slug]))->assertOk()->getContent(), $m);

        return array_map(fn ($u) => parse_url($u, PHP_URL_PATH), $m[1]);
    }

    public function test_pages_with_nothing_to_show_are_left_out_and_appear_once_they_have_content(): void
    {
        $club = $this->club();
        $base = '/c/'.$club->slug;

        $empty = $this->locs($club);
        $this->assertContains($base, $empty);
        $this->assertContains($base.'/contact', $empty);
        foreach (['/programs', '/trainers', '/schedule', '/news', '/plans', '/join'] as $page) {
            $this->assertNotContains($base.$page, $empty, $page.' has nothing to show');
            $this->assertNotContains($base.'/en'.$page, $empty, '/en'.$page.' has nothing to show');
        }

        $this->asTenant($club, fn () => Program::create(['name' => 'Yoga', 'slug' => 'yoga', 'is_public' => true, 'is_active' => true]));
        $this->plan($club, ['is_public' => true, 'is_active' => true]);

        $filled = $this->locs($club);
        foreach (['/programs', '/plans', '/join', '/en/programs', '/en/plans'] as $page) {
            $this->assertContains($base.$page, $filled, $page.' has content now');
        }
    }
}
