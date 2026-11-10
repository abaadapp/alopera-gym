<?php

namespace Tests\Feature;

use App\Core\Tenancy\SiteDomains;
use App\Models\Tenant;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Website\Models\SitePost;
use App\Modules\Website\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The website and portal in Arabic (the default, right to left, plain addresses) and English (/en, left to right):
 * each page in both languages, links that stay in the language chosen, search engines told about both, the owner's
 * English words saved, previewed and published with the draft, and the catalogue's English names from the panel.
 */
class WebsiteBilingualTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function base(Tenant $club, string $path = ''): string
    {
        return '/c/'.$club->slug.$path;
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    private function editor(Tenant $club): array
    {
        $props = $this->actingAs($this->owner($club))->get(route('club.website.edit'))->assertOk()->viewData('page')['props'];

        return [$props['doc'], $props['version']];
    }

    private function save(Tenant $club, array $doc, int $version): TestResponse
    {
        return $this->actingAs($this->owner($club))->put(route('club.website.draft.save'), ['doc' => $doc, 'version' => $version]);
    }

    /** @return array<string, mixed> */
    private function visit(string $url): array
    {
        auth()->logout();

        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    private function hero(array $props): array
    {
        return collect($props['page']['sections'])->firstWhere('type', 'hero');
    }

    public function test_arabic_is_the_default_and_english_is_under_en_in_its_own_direction(): void
    {
        $club = $this->club();
        $this->plan($club, ['name' => 'باقة شهرية', 'name_en' => 'Monthly plan', 'description' => 'وصف', 'description_en' => 'All classes', 'features' => ['ميزة'], 'features_en' => ['Free towel'], 'is_public' => true]);
        $this->plan($club, ['name' => 'باقة بلا ترجمة', 'sort' => 2, 'is_public' => true]);

        $ar = $this->get($this->base($club, '/plans'))->assertOk()->assertSee('<html lang="ar" dir="rtl">', false);
        $ar->assertInertia(fn (AssertableInertia $p) => $p->where('locale', 'ar')->where('dir', 'rtl')
            ->where('plans.0.name', 'باقة شهرية')->where('plans.0.duration_label', 'شهر')->where('plans.0.type_label', 'شاملة'));

        $en = $this->get($this->base($club, '/en/plans'))->assertOk()->assertSee('<html lang="en" dir="ltr">', false);
        $en->assertInertia(fn (AssertableInertia $p) => $p->component('Site/Plans')->where('locale', 'en')->where('dir', 'ltr')
            ->where('plans.0.name', 'Monthly plan')->where('plans.0.description', 'All classes')->where('plans.0.features', ['Free towel'])
            ->where('plans.0.duration_label', '1 month')->where('plans.0.type_label', 'All-inclusive')
            // Not written in English yet: its Arabic, never an empty name.
            ->where('plans.1.name', 'باقة بلا ترجمة'));
        $this->assertSame($this->base($club, '/en/plans'), $en->viewData('page')['url'], 'the browser keeps the English address');

        // The next Arabic request is Arabic again (the language is per request).
        $this->get($this->base($club, '/plans'))->assertInertia(fn (AssertableInertia $p) => $p->where('locale', 'ar')->where('plans.0.name', 'باقة شهرية'));
    }

    public function test_links_made_on_an_english_page_stay_in_english(): void
    {
        $club = $this->club();
        $this->plan($club, ['is_public' => true]);

        $page = $this->get($this->base($club, '/en'))->assertOk();
        // The routes the page builds its links from (Ziggy): the site's with /en, the panel's untouched.
        $page->assertSee('c\/{club}\/en\/plans', false)->assertSee('c\/{club}\/en\/portal\/login', false)->assertDontSee('app\/en', false);
        $page->assertInertia(fn (AssertableInertia $p) => $p->where('chrome.home_url', url($this->base($club, '/en'))));
        $join = collect($page->viewData('page')['props']['page']['sections'])->firstWhere('type', 'plans')['feed']['plans'][0]['join_url'];
        $this->assertStringContainsString($this->base($club, '/en/join'), $join);

        // The Arabic page's links have no /en.
        $this->get($this->base($club, '/plans'))->assertDontSee('c\/{club}\/en\/plans', false);
    }

    public function test_on_the_club_domain_both_languages_have_clean_addresses_and_search_engines_see_both(): void
    {
        $club = $this->club();
        $club->update(['domain' => 'aloperagym.om']);
        config(['app.url' => 'https://app.aloperagym.om', 'gym.home_club' => $club->slug]);
        app(SiteDomains::class)->flush();

        $en = $this->get('https://aloperagym.om/en/plans')->assertOk();
        $this->assertSame('/en/plans', $en->viewData('page')['url']);
        $en->assertInertia(fn (AssertableInertia $p) => $p->where('seo.canonical', 'https://aloperagym.om/en/plans')
            ->where('seo.alternates', ['ar' => 'https://aloperagym.om/plans', 'en' => 'https://aloperagym.om/en/plans']));
        $en->assertSee('<link rel="alternate" hreflang="ar" href="https://aloperagym.om/plans">', false)
            ->assertSee('<link rel="alternate" hreflang="en" href="https://aloperagym.om/en/plans">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="https://aloperagym.om/plans">', false)
            ->assertSee('<meta property="og:locale" content="en_US">', false)
            ->assertSee('"uri":"en\/plans"', false);

        $this->get('https://aloperagym.om/plans')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('seo.canonical', 'https://aloperagym.om/plans')->where('locale', 'ar'))
            ->assertSee('<meta property="og:locale" content="ar_OM">', false)->assertSee('"uri":"plans"', false);
        $this->get('https://aloperagym.om/en')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Site/Home')->where('locale', 'en'));

        // The English address on the panel's domain or with the club in the path moves to the clean one.
        $this->get('https://app.aloperagym.om/c/'.$club->slug.'/en/plans')->assertStatus(301)->assertRedirect('https://aloperagym.om/en/plans');
        $this->get('https://aloperagym.om/c/'.$club->slug.'/en/plans')->assertStatus(301)->assertRedirect('https://aloperagym.om/en/plans');
        // The panel has no English side.
        $this->get('https://app.aloperagym.om/en/app')->assertNotFound();
    }

    public function test_the_sitemap_lists_each_page_in_both_languages(): void
    {
        $club = $this->club();
        $xml = $this->get($this->base($club, '/sitemap.xml'))->assertOk()->getContent();

        $this->assertStringContainsString('<loc>'.url($this->base($club, '/plans')).'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.url($this->base($club, '/en/plans')).'</loc>', $xml);
        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="en" href="'.url($this->base($club, '/en/plans')).'"/>', $xml);
        $this->assertStringContainsString('hreflang="x-default" href="'.url($this->base($club, '/plans')).'"', $xml);
    }

    public function test_the_starting_site_has_english_for_its_standard_words_and_the_owners_own_words_stay_arabic_until_written(): void
    {
        $club = $this->club();
        $this->asTenant($club, fn () => SiteSetting::firstOrCreate([])->update(['about_title' => 'من نحن', 'about_body' => 'نادٍ للسيدات']));

        $hero = $this->hero($this->visit($this->base($club, '/en')));
        $this->assertSame('Your strength', $hero['data']['title']);
        $this->assertSame('starts here.', $hero['data']['title_2']);
        $about = collect($this->visit($this->base($club, '/en'))['page']['sections'])->firstWhere('id', 'about');
        $this->assertSame('نادٍ للسيدات', $about['data']['body'], 'the owner\'s text shows in Arabic until written in English');

        $props = $this->visit($this->base($club, '/en/plans'));
        $this->assertSame('Plans and prices', $props['chrome']['pages']['plans']['title']);
        $this->assertSame('Plans and prices | '.$club->name, $props['seo']['title']);
        // The Arabic site is as it was.
        $this->assertSame('قوّتكِ', $this->hero($this->visit($this->base($club, '')))['data']['title']);
    }

    public function test_the_owners_english_words_are_saved_previewed_and_published_with_the_draft(): void
    {
        $club = $this->club();
        // A plan to sign up for, so the hero's join button shows.
        $this->plan($club, ['is_public' => true]);
        [$doc, $version] = $this->editor($club);
        $hero = collect($doc['sections'])->firstWhere('type', 'hero');
        // The menu link to the plans section (it shows: there is a plan); links to empty sections are left out.
        $link = collect($doc['header']['links'])->firstWhere('href', '#pricing');
        $doc['en'] = [
            'sections' => [$hero['id'] => ['title' => 'Strong starts', 'primary' => ['label' => 'Become a member']]],
            'header' => ['links' => [$link['id'] => ['label' => 'Memberships']]],
            'seo' => ['title' => 'Alopera Gym — women\'s gym in Muscat'],
            'pages' => ['plans' => ['title' => 'Memberships']],
        ];
        $this->save($club, $doc, $version)->assertSessionHasNoErrors();

        // Saved, not live: visitors of the English site still see the published (starting) words.
        $this->assertSame('Your strength', $this->hero($this->visit($this->base($club, '/en')))['data']['title']);

        // The preview shows the draft in either language, and its language switch stays in the preview.
        $this->actingAs($this->owner($club))->get(route('club.website.preview', ['lang' => 'en']))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('locale', 'en')->where('page.sections.0.data.title', 'Strong starts')
                ->where('page.sections.0.data.primary.label', 'Become a member')
                ->where('seo.alternates.ar', route('club.website.preview', ['lang' => 'ar'])));
        $this->actingAs($this->owner($club))->get(route('club.website.preview'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('locale', 'ar')->where('page.sections.0.data.title', 'قوّتكِ'));

        [$saved, $version] = $this->editor($club);
        $this->assertSame('Strong starts', $saved['en']['sections'][$hero['id']]['title']);
        $this->actingAs($this->owner($club))->post(route('club.website.publish'), ['version' => $version])->assertSessionHasNoErrors();

        $en = $this->visit($this->base($club, '/en'));
        $this->assertSame('Strong starts', $this->hero($en)['data']['title']);
        $this->assertContains('Memberships', array_column($en['chrome']['header']['links'], 'label'));
        $this->assertNotContains('Memberships', array_column($this->visit($this->base($club, ''))['chrome']['header']['links'], 'label'));
        $this->assertSame('Alopera Gym — women\'s gym in Muscat', $en['seo']['title']);
        $this->assertSame('Memberships', $this->visit($this->base($club, '/en/plans'))['chrome']['pages']['plans']['title']);
        // Arabic untouched.
        $this->assertSame('قوّتكِ', $this->hero($this->visit($this->base($club, '')))['data']['title']);
    }

    public function test_english_words_are_checked_like_the_arabic_and_only_texts_are_kept(): void
    {
        $club = $this->club();
        [$doc, $version] = $this->editor($club);
        $i = collect($doc['sections'])->search(fn ($s) => $s['type'] === 'hero');
        $hero = $doc['sections'][$i];

        $doc['en'] = ['sections' => [$hero['id'] => ['title' => str_repeat('a', 61)]]];
        $this->save($club, $doc, $version)->assertSessionHasErrors("doc.en.sections.$i.data.title");

        $doc['en'] = ['sections' => [
            $hero['id'] => ['title' => '<b>Hi</b>', 'image' => ['id' => 999], 'show_next_class' => false],
            'no-such-section' => ['title' => 'Lost'],
        ], 'theme' => ['accent' => '#000000']];
        $this->save($club, $doc, $version)->assertSessionHasNoErrors();
        [$saved] = $this->editor($club);
        $this->assertSame(['sections' => [$hero['id'] => ['title' => '<b>Hi</b>']]], $saved['en'], 'only text, of sections that exist; never images, switches or colours');

        // Printed as text on the page, never as markup.
        $this->actingAs($this->owner($club))->get(route('club.website.preview', ['lang' => 'en']))->assertDontSee('<b>Hi</b>', false);
    }

    public function test_a_text_emptied_in_arabic_is_gone_in_english_too(): void
    {
        $club = $this->club();
        [$doc, $version] = $this->editor($club);
        $i = collect($doc['sections'])->search(fn ($s) => $s['type'] === 'hero');
        $doc['sections'][$i]['data']['primary']['label'] = '';
        $doc['en'] = ['sections' => [$doc['sections'][$i]['id'] => ['primary' => ['label' => 'Join']]]];
        $this->save($club, $doc, $version)->assertSessionHasNoErrors();

        $this->actingAs($this->owner($club))->get(route('club.website.preview', ['lang' => 'en']))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('page.sections.0.data.primary', null));
    }

    public function test_the_catalogue_english_comes_from_its_own_panel_screens(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $plan = $this->plan($club, ['name' => 'شهري', 'is_public' => true]);

        $this->actingAs($owner)->put(route('club.plans.update', $plan), [
            'name' => 'شهري', 'name_en' => 'Monthly', 'description_en' => 'Gym and classes', 'features_en' => ['Lockers'],
            'type' => 'combo', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 25, 'is_public' => true, 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Monthly', $this->asTenant($club, fn () => MembershipPlan::find($plan->id)->name_en));

        $this->actingAs($owner)->post(route('club.programs.store'), [
            'name' => 'يوغا', 'name_en' => 'Yoga', 'description_en' => 'Stretch and breathe', 'category' => 'class', 'level' => 'all',
            'color' => '#123456', 'duration_minutes' => 60, 'default_capacity' => 12, 'is_public' => true, 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.trainers.store'), [
            'name' => 'سارة', 'name_en' => 'Sara', 'bio_en' => 'Certified coach', 'specialties_en' => ['Pilates'], 'is_public' => true, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        auth()->logout();
        $this->get($this->base($club, '/en/plans'))->assertInertia(fn (AssertableInertia $p) => $p->where('plans.0.name', 'Monthly')->where('plans.0.features', ['Lockers']));
        $this->get($this->base($club, '/en/programs'))->assertInertia(fn (AssertableInertia $p) => $p->where('programs.0.name', 'Yoga')->where('programs.0.description', 'Stretch and breathe'));
        $this->get($this->base($club, '/en/trainers'))->assertInertia(fn (AssertableInertia $p) => $p->where('trainers.0.name', 'Sara')->where('trainers.0.specialties', ['Pilates']));
        $this->get($this->base($club, '/programs'))->assertInertia(fn (AssertableInertia $p) => $p->where('programs.0.name', 'يوغا'));

        // The class timetable names the program and trainer in the page's language.
        $program = $this->asTenant($club, fn () => Program::where('name', 'يوغا')->first());
        $trainer = $this->asTenant($club, fn () => Trainer::where('name', 'سارة')->first());
        $this->classSession($club, $program, ['trainer_id' => $trainer->id]);
        $this->get($this->base($club, '/en/schedule'))->assertInertia(fn (AssertableInertia $p) => $p->where('sessions.0.program', 'Yoga')->where('sessions.0.trainer', 'Sara'));
        $this->get($this->base($club, '/schedule'))->assertInertia(fn (AssertableInertia $p) => $p->where('sessions.0.program', 'يوغا'));
    }

    public function test_news_and_offers_show_their_english_on_the_english_site(): void
    {
        $club = $this->club();
        $this->asTenant($club, fn () => SitePost::create(['type' => 'news', 'title' => 'افتتاح', 'title_en' => 'Opening', 'slug' => 'opening', 'excerpt' => 'ملخص', 'body' => 'نص', 'body_en' => 'Text', 'is_published' => true, 'published_at' => now()]));

        $this->get($this->base($club, '/en/news'))->assertInertia(fn (AssertableInertia $p) => $p->where('posts.data.0.title', 'Opening')->where('posts.data.0.excerpt', 'ملخص'));
        $this->get($this->base($club, '/en/news/opening'))->assertInertia(fn (AssertableInertia $p) => $p->where('post.title', 'Opening')->where('post.body', 'Text')->where('seo.title', 'Opening | '.$club->name));
        $this->get($this->base($club, '/news/opening'))->assertInertia(fn (AssertableInertia $p) => $p->where('post.title', 'افتتاح'));
    }

    public function test_forms_on_the_english_site_answer_in_english_and_come_back_to_the_english_page(): void
    {
        $club = $this->club();

        $this->from($this->base($club, '/en/contact'))->post($this->base($club, '/en/contact'), ['message' => 'Hi'])
            ->assertRedirect($this->base($club, '/en/contact'))
            ->assertSessionHasErrors(['name' => 'The name field is required.']);
        $this->from($this->base($club, '/en/contact'))->post($this->base($club, '/en/contact'), ['name' => 'Sara', 'phone' => '96890000000', 'message' => 'Hi'])
            ->assertSessionHas('success', 'Your message has been received.');

        // The Arabic form is as before.
        $this->from($this->base($club, '/contact'))->post($this->base($club, '/contact'), ['name' => 'سارة', 'phone' => '96890000000', 'message' => 'مرحبا'])
            ->assertSessionHas('success', 'تم استلام رسالتك بنجاح');
    }

    public function test_the_portal_sign_in_and_join_page_work_in_english(): void
    {
        $club = $this->club();
        $this->plan($club, ['name' => 'شهري', 'name_en' => 'Monthly', 'is_public' => true]);

        $this->get($this->base($club, '/en/portal/login'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Portal/Login')->where('locale', 'en')->where('seo.title', 'Member sign-in | '.$club->name));
        $this->get($this->base($club, '/en/join'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Site/Join')->where('plans.0.name', 'Monthly'));
        // A member's area asks for sign-in on the English side.
        $this->get($this->base($club, '/en/portal'))->assertRedirect(url($this->base($club, '/en/portal/login')));
    }
}
