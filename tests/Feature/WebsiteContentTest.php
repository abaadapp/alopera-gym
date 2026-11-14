<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Website\Content\SiteContent;
use App\Modules\Website\Content\SiteLinks;
use App\Modules\Website\Models\SiteMedia;
use App\Modules\Website\Models\SiteRevision;
use App\Modules\Website\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * «إدارة الموقع»: draft, preview, publish, go back, sections, images, and who may do it. The public home page is
 * checked through the props a visitor's page receives.
 */
class WebsiteContentTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** The editor's current document and version. @return array{0: array<string, mixed>, 1: int} */
    private function editor(Tenant $club): array
    {
        $props = $this->actingAs($this->owner($club))->get(route('club.website.edit'))->assertOk()->viewData('page')['props'];

        return [$props['doc'], $props['version']];
    }

    /** @return array<string, mixed> */
    private function home(Tenant $club): array
    {
        auth()->logout();

        return $this->get(route('site.home', ['club' => $club->slug]))->assertOk()->viewData('page')['props'];
    }

    private function save(Tenant $club, array $doc, int $version): TestResponse
    {
        return $this->actingAs($this->owner($club))->put(route('club.website.draft.save'), ['doc' => $doc, 'version' => $version]);
    }

    private function publish(Tenant $club, int $version): TestResponse
    {
        return $this->actingAs($this->owner($club))->post(route('club.website.publish'), ['version' => $version]);
    }

    /** @param array<string, mixed> $doc */
    private function hero(array $doc): array
    {
        return collect($doc['sections'])->firstWhere('type', 'hero');
    }

    /** @param array<string, mixed> $props */
    private function sectionsOf(array $props): array
    {
        return collect($props['page']['sections'])->pluck('type', 'id')->all();
    }

    private function image(string $name, int $width, int $height): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    public function test_the_site_starts_from_the_approved_design_without_invented_content(): void
    {
        $club = $this->club();
        $props = $this->home($club);

        $this->assertSame('hero', $props['page']['sections'][0]['type']);
        $this->assertSame('قوّتكِ', $props['page']['sections'][0]['data']['title']);
        // Sections that need the owner's own content (reviews, facilities, gallery) stay off until filled.
        $this->assertNotContains('testimonials', $this->sectionsOf($props));
        $this->assertNotContains('services', $this->sectionsOf($props));
        $this->assertNotContains('gallery', $this->sectionsOf($props));
        // No image is made up: the hero has none until the owner uploads one.
        $this->assertNull($props['page']['sections'][0]['data']['image']);
        // Menu links to sections that are not shown are left out.
        $this->assertNotContains('آراء العضوات', collect($props['chrome']['header']['links'])->pluck('label'));
        $this->assertStringNotContainsString('دفع إلكتروني', json_encode($props, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_starting_site_makes_no_promise_or_claim_the_owner_has_not_written(): void
    {
        $club = $this->club();
        $props = $this->home($club);
        $visible = json_encode([$props['page'], $props['chrome'], $props['seo']], JSON_UNESCAPED_UNICODE);

        foreach (['للسيدات فقط', 'نسائي', 'الأوبرا', 'معتمد', 'بلا رسوم', 'مخفية', 'دقيقتين', 'الأكثر طلب', 'تدفعين', 'بأمان', 'متكامل', 'تدريب شخصي', 'محترف', 'باقات مرنة', 'حجز أونلاين', 'سنتواصل', 'خطوة بخطوة'] as $claim) {
            $this->assertStringNotContainsString($claim, $visible, "«{$claim}» is a claim only the owner may make");
        }
        // The club's name in place of a slogan line, and no search-engine description until the owner writes one.
        $this->assertSame($club->name, $props['page']['sections'][0]['data']['eyebrow']);
        $this->assertNull($props['seo']['description']);
        $this->assertSame($club->name, $props['seo']['title']);
    }

    public function test_the_inner_pages_words_are_the_owners_and_promise_nothing(): void
    {
        $club = $this->club();
        $this->plan($club, ['is_public' => true]);
        [$doc, $version] = $this->editor($club);
        $props = $this->actingAs($this->owner($club))->get(route('club.website.edit'))->viewData('page')['props'];
        $this->assertSame(['plans', 'programs', 'schedule', 'trainers', 'news', 'contact', 'join', 'shop'], array_column($props['pageFields'], 'key'));
        // The starting words state what each page holds, nothing more.
        $words = json_encode($doc['pages'], JSON_UNESCAPED_UNICODE);
        foreach (['معتمد', 'قريب', 'دقائق', 'دقيقة', 'أونلاين', 'سنتواصل', 'أقرب وقت', 'مصمم'] as $claim) {
            $this->assertStringNotContainsString($claim, $words, "«{$claim}»");
        }

        $doc['pages']['plans'] = ['title' => 'باقات النادي', 'subtitle' => '', 'pt_title' => 'جلسات خاصة', 'pt_subtitle' => ''];
        $doc['pages']['contact']['subtitle'] = 'راسلنا من هنا.';
        $this->save($club, $doc, $version)->assertSessionHasNoErrors();
        $this->publish($club, $version + 1)->assertSessionHasNoErrors();
        auth()->logout();

        $this->get(route('site.plans', ['club' => $club->slug]))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('chrome.pages.plans.title', 'باقات النادي')
            ->where('chrome.pages.plans.pt_title', 'جلسات خاصة')
            ->where('seo.title', 'باقات النادي | '.$club->name));
        $this->get(route('site.contact', ['club' => $club->slug]))->assertInertia(fn (AssertableInertia $p) => $p->where('seo.description', 'راسلنا من هنا.'));
        // An emptied title falls back to the page's own; a page keeps a title.
        $doc['pages']['news']['title'] = '';
        $this->save($club, $doc, $version + 1)->assertSessionHasNoErrors();
        $this->publish($club, $version + 2);
        auth()->logout();
        $this->get(route('site.news', ['club' => $club->slug]))->assertInertia(fn (AssertableInertia $p) => $p->where('chrome.pages.news.title', 'العروض والأخبار'));
    }

    public function test_the_contact_form_confirms_receipt_without_promising_a_reply(): void
    {
        $club = $this->club();
        $this->post(route('site.contact.send', ['club' => $club->slug]), ['name' => 'سارة', 'phone' => '91234567', 'message' => 'استفسار عن الباقات'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'تم استلام رسالتك بنجاح');
    }

    public function test_every_page_menu_links_only_to_sections_that_show(): void
    {
        $club = $this->club();
        $labels = fn (string $route) => collect($this->get(route($route, ['club' => $club->slug]))->assertOk()->viewData('page')['props']['chrome']['header']['links'])->pluck('label')->all();

        // A new club has no programs, classes, trainers, plans, facilities or reviews yet.
        foreach (['site.home', 'site.join', 'site.plans', 'site.contact'] as $page) {
            $this->assertSame(['تواصل معنا'], $labels($page), $page);
        }
        $this->plan($club, ['is_public' => true]);
        $this->assertContains('الباقات', $labels('site.join'));
    }

    public function test_a_button_to_a_section_that_is_not_shown_is_left_out(): void
    {
        $club = $this->club();
        $this->plan($club, ['is_public' => true]);
        // No classes yet: the timetable section is not shown, so the hero's «جدول الحصص» button has nowhere to go.
        $hero = $this->home($club)['page']['sections'][0];
        $this->assertNull($hero['data']['secondary']);
        $this->assertSame(route('site.join', ['club' => $club->slug]), $hero['data']['primary']['href']);

        $program = $this->program($club, ['is_public' => true]);
        $this->classSession($club, $program, ['starts_at' => now()->addDay()->setTime(18, 0), 'ends_at' => now()->addDay()->setTime(19, 0)]);
        $hero = $this->home($club)['page']['sections'][0];
        $this->assertStringEndsWith('#schedule', $hero['data']['secondary']['href']);
        $this->assertArrayNotHasKey('_href', $hero['data']['secondary']);
    }

    public function test_no_button_or_link_opens_a_page_with_nothing_on_it(): void
    {
        // A new club as it opens: no plans, classes, news or products yet.
        $club = $this->club();
        $props = $this->home($club);
        $footer = array_column($props['chrome']['footer']['links'], 'label');
        $this->assertSame(['تواصل معنا'], $footer, 'the plans, timetable and news pages are empty, so their links are left out');
        $this->assertNull($props['chrome']['header']['cta'], 'nothing to sign up for yet: no «انضمي الآن»');
        $this->assertNull($props['page']['sections'][0]['data']['primary'], 'nor a sign-up button in the hero');
        $this->assertNotContains('join', $this->sectionsOf($props), 'nor a sign-up section');
        $this->assertTrue($props['chrome']['header']['show_login']);
        // The pages themselves still open for anyone who has their address.
        $this->get(route('site.plans', ['club' => $club->slug]))->assertOk();
        // Nor «اشترك الآن» on the members' sign-in page.
        $this->get(route('portal.login', ['club' => $club->slug]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('site.signup_enabled', false));

        // Once there is something on the page, its links come back by themselves, without publishing again.
        $this->plan($club, ['is_public' => true]);
        $props = $this->home($club);
        $this->assertSame(['الباقات', 'تواصل معنا'], array_column($props['chrome']['footer']['links'], 'label'));
        $this->assertSame(route('site.join', ['club' => $club->slug]), $props['chrome']['header']['cta']['href']);
        $this->assertSame(route('site.join', ['club' => $club->slug]), $props['page']['sections'][0]['data']['primary']['href']);
        $this->assertContains('join', $this->sectionsOf($props));
        $this->get(route('portal.login', ['club' => $club->slug]))->assertInertia(fn (AssertableInertia $p) => $p->where('site.signup_enabled', true));

        // With online sign-up switched off, the sign-up buttons go again (the page answers 404).
        $club->update(['settings' => ['member_signup_enabled' => false] + ($club->settings ?? [])]);
        $props = $this->home($club);
        $this->assertNull($props['chrome']['header']['cta']);
        $this->assertNotContains('join', $this->sectionsOf($props));
    }

    public function test_the_editor_says_which_system_sections_wait_for_data(): void
    {
        $club = $this->club();
        $props = $this->actingAs($this->owner($club))->get(route('club.website.edit'))->assertOk()->viewData('page')['props'];
        $types = collect($props['doc']['sections'])->pluck('type', 'id');
        $waiting = collect($props['waiting'])->keys()->map(fn ($id) => $types[$id])->sort()->values()->all();
        $this->assertSame(['join', 'plans', 'posts', 'products', 'programs', 'schedule', 'trainers'], $waiting);
        $this->assertStringContainsString('باقات', $props['waiting'][$types->search('plans')]);

        $this->plan($club, ['is_public' => true]);
        $props = $this->actingAs($this->owner($club))->get(route('club.website.edit'))->viewData('page')['props'];
        $this->assertNotContains('plans', collect($props['waiting'])->keys()->map(fn ($id) => $types[$id])->all());
        $this->assertNotContains('join', collect($props['waiting'])->keys()->map(fn ($id) => $types[$id])->all());
    }

    public function test_a_saved_draft_stays_off_the_public_site_until_published(): void
    {
        $club = $this->club();
        [$doc, $version] = $this->editor($club);
        $i = collect($doc['sections'])->search(fn ($s) => $s['type'] === 'hero');
        $doc['sections'][$i]['data']['title'] = 'عنوان المسودة';

        $this->save($club, $doc, $version)->assertSessionHasNoErrors();
        $this->assertSame('قوّتكِ', $this->home($club)['page']['sections'][0]['data']['title']);

        // The draft survives signing out and back in.
        [$again, $newVersion] = $this->editor($club);
        $this->assertSame('عنوان المسودة', $this->hero($again)['data']['title']);
        $this->assertSame($version + 1, $newVersion);

        $this->publish($club, $newVersion)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('عنوان المسودة', $this->home($club)['page']['sections'][0]['data']['title']);
        $this->assertSame(1, $this->asTenant($club, fn () => SiteRevision::count()));
    }

    public function test_the_preview_shows_the_draft_to_staff_only(): void
    {
        $club = $this->club();
        [$doc, $version] = $this->editor($club);
        $doc['sections'][0]['data']['title'] = 'معاينة فقط';
        $this->save($club, $doc, $version);

        $this->actingAs($this->owner($club))->get(route('club.website.preview'))->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Site/Home')
                ->where('page.sections.0.data.title', 'معاينة فقط')->where('chrome.preview', true));

        auth()->logout();
        $this->get(route('club.website.preview'))->assertRedirect(route('login'));
        $this->actingAs($this->staff($club, 'manager'))->get(route('club.website.preview'))->assertForbidden();
        // Visitors never get the draft through the public pages.
        $this->assertStringNotContainsString('معاينة فقط', json_encode($this->home($club), JSON_UNESCAPED_UNICODE));
    }

    public function test_sections_are_added_reordered_hidden_and_deleted(): void
    {
        $club = $this->club();
        [$doc, $version] = $this->editor($club);
        $faq = ['id' => 'faq-abc123', 'type' => 'faq', 'visible' => true, 'tone' => 'auto', 'data' => [
            'title' => 'أسئلة', 'items' => [['id' => 'q1', 'q' => 'هل يوجد موقف؟', 'a' => 'نعم.']],
        ]];
        // FAQ second, schedule hidden, contact deleted.
        $hero = array_shift($doc['sections']);
        $doc['sections'] = array_values(array_filter($doc['sections'], fn ($s) => $s['id'] !== 'contact'));
        foreach ($doc['sections'] as &$s) {
            if ($s['id'] === 'trainers') {
                $s['visible'] = false;
            }
        }
        unset($s);
        $doc['sections'] = [$hero, $faq, ...$doc['sections']];
        $this->asTenant($club, fn () => Trainer::create(['name' => 'مدربة', 'is_public' => true, 'is_active' => true]));

        $this->save($club, $doc, $version)->assertSessionHasNoErrors();
        $this->publish($club, $version + 1)->assertSessionHasNoErrors();

        $types = array_values($this->sectionsOf($this->home($club)));
        $this->assertSame(['hero', 'faq'], array_slice($types, 0, 2));
        $this->assertNotContains('trainers', $types);
        $this->assertNotContains('contact', $types);
        $this->assertSame('هل يوجد موقف؟', collect($this->home($club)['page']['sections'])->firstWhere('type', 'faq')['data']['items'][0]['q']);
    }

    public function test_html_and_unsafe_links_never_reach_the_page(): void
    {
        $club = $this->club();
        $this->plan($club, ['is_public' => true]);
        [$doc, $version] = $this->editor($club);
        $doc['sections'][0]['data']['title'] = '<script>alert(1)</script>';
        $doc['sections'][0]['data']['primary'] = ['label' => 'اضغطي', 'href' => 'javascript:alert(1)'];
        $doc['header']['links'][0]['href'] = 'data:text/html,hi';
        $doc['sections'][] = ['id' => 'x', 'type' => 'raw_html', 'visible' => true, 'tone' => 'auto', 'data' => ['html' => '<b>x</b>']];

        $this->save($club, $doc, $version)->assertSessionHasErrors(['doc.sections.0.data.primary.href', 'doc.header.links.0.href', 'doc.sections.'.(count($doc['sections']) - 1).'.type']);

        array_pop($doc['sections']);
        $doc['sections'][0]['data']['primary']['href'] = '@join';
        $doc['header']['links'][0]['href'] = '#programs';
        $doc['sections'][0]['data']['secret'] = 'not in the schema';
        $this->save($club, $doc, $version)->assertSessionHasNoErrors();
        $this->publish($club, $version + 1);

        // Text is kept as text (React prints it escaped); unknown keys are dropped.
        $hero = $this->home($club)['page']['sections'][0];
        $this->assertSame('<script>alert(1)</script>', $hero['data']['title']);
        $this->assertArrayNotHasKey('secret', $hero['data']);
        $this->assertSame(route('site.join', ['club' => $club->slug]), $hero['data']['primary']['href']);
    }

    public function test_two_windows_cannot_overwrite_each_other_and_a_double_publish_makes_one_version(): void
    {
        $club = $this->club();
        [$doc, $version] = $this->editor($club);
        $this->save($club, $doc, $version)->assertSessionHasNoErrors();
        // A second window still on the old version is refused.
        $this->save($club, $doc, $version)->assertSessionHasErrors('version');

        $this->publish($club, $version + 1)->assertSessionHasNoErrors();
        $this->publish($club, $version + 1)->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'لا توجد تغييرات'));
        $this->assertSame(1, $this->asTenant($club, fn () => SiteRevision::count()));
        $this->publish($club, $version)->assertSessionHasErrors('version');
    }

    public function test_discard_and_restore_go_back_to_published_versions(): void
    {
        $club = $this->club();
        [$doc, $v] = $this->editor($club);
        $doc['sections'][0]['data']['title'] = 'الأولى';
        $this->save($club, $doc, $v);
        $this->publish($club, ++$v);
        $doc['sections'][0]['data']['title'] = 'الثانية';
        $this->save($club, $doc, $v);
        $this->publish($club, ++$v);
        $this->assertSame('الثانية', $this->home($club)['page']['sections'][0]['data']['title']);

        // Unpublished changes are dropped.
        $doc['sections'][0]['data']['title'] = 'لن تُنشر';
        $this->save($club, $doc, $v);
        $this->actingAs($this->owner($club))->post(route('club.website.discard'), ['version' => ++$v])->assertSessionHasNoErrors();
        [$editing] = $this->editor($club);
        $this->assertSame('الثانية', $editing['sections'][0]['data']['title']);

        // Going back to version 1 publishes it again as version 3.
        $first = $this->asTenant($club, fn () => SiteRevision::where('number', 1)->first());
        $this->actingAs($this->owner($club))->post(route('club.website.revisions.restore', ['revision' => $first->id]))->assertSessionHasNoErrors();
        $this->assertSame('الأولى', $this->home($club)['page']['sections'][0]['data']['title']);
        $this->assertSame([1, 2, 3], $this->asTenant($club, fn () => SiteRevision::orderBy('number')->pluck('number')->all()));
    }

    public function test_publishing_keeps_the_contact_details_every_page_shows(): void
    {
        $club = $this->club();
        [$doc, $v] = $this->editor($club);
        $doc['contact']['phone'] = '+968 9123 4567';
        $doc['contact']['hours'] = [['id' => 'h1', 'days' => 'السبت – الخميس', 'hours' => '6ص – 11م']];
        $doc['contact']['social']['instagram'] = 'https://instagram.com/alopera';
        $this->save($club, $doc, $v)->assertSessionHasNoErrors();
        $this->publish($club, $v + 1);

        $this->get(route('site.plans', ['club' => $club->slug]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('site.contact_phone', '96891234567')
            ->where('site.working_hours.0.days', 'السبت – الخميس')
            ->where('site.social_links.instagram', 'https://instagram.com/alopera')
            ->has('chrome.header.links'));
        $this->assertSame('96891234567', $this->asTenant($club, fn () => SiteSetting::first()->contact_phone));
    }

    public function test_images_are_measured_and_shown_whole_in_any_shape(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $upload = fn (UploadedFile $f) => $this->actingAs($owner)->postJson(route('club.website.media.store'), ['images' => [$f]])->assertOk()->json('media.0');

        $wide = $upload($this->image('wide.jpg', 1600, 900));
        $tall = $upload($this->image('tall.jpg', 800, 1200));
        $square = $upload($this->image('square.png', 1000, 1000));
        $huge = $upload($this->image('huge.jpg', 5000, 2500));

        $this->assertSame(['landscape', 1600, 900], [$wide['orientation'], $wide['width'], $wide['height']]);
        $this->assertSame(['portrait', 800, 1200], [$tall['orientation'], $tall['width'], $tall['height']]);
        $this->assertSame('square', $square['orientation']);
        // Scaled down to fit the website's longest side (media.sides.site, 1920), proportions unchanged.
        $this->assertSame([1920, 960], [$huge['width'], $huge['height']]);

        [$doc, $v] = $this->editor($club);
        $doc['sections'][0]['data']['image'] = ['id' => $tall['id'], 'fit' => 'auto'];
        $doc['sections'][] = ['id' => 'gallery-2', 'type' => 'gallery', 'visible' => true, 'tone' => 'auto', 'data' => ['items' => [
            ['id' => 'a', 'image' => ['id' => $wide['id'], 'fit' => 'auto'], 'caption' => 'القاعة'],
            ['id' => 'b', 'image' => ['id' => $square['id'], 'fit' => 'contain'], 'caption' => ''],
        ]]];
        $this->save($club, $doc, $v)->assertSessionHasNoErrors();
        $this->publish($club, $v + 1);

        $sections = collect($this->home($club)['page']['sections']);
        $heroImage = $sections->firstWhere('type', 'hero')['data']['image'];
        $this->assertSame(['portrait', 800, 1200, 'auto'], [$heroImage['orientation'], $heroImage['width'], $heroImage['height'], $heroImage['fit']]);
        $this->assertSame(['landscape', 'square'], collect($sections->firstWhere('id', 'gallery-2')['data']['items'])->pluck('image.orientation')->all());
        Storage::disk('public')->assertExists(SiteMedia::withoutGlobalScopes()->find($tall['id'])->path);
    }

    public function test_transparency_survives_and_bad_files_are_refused_without_touching_existing_images(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $png = tempnam(sys_get_temp_dir(), 'png');
        $img = imagecreatetruecolor(300, 200);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledrectangle($img, 100, 50, 200, 150, imagecolorallocate($img, 200, 0, 0));
        imagepng($img, $png);
        $logo = $this->actingAs($owner)->postJson(route('club.website.media.store'), ['images' => [new UploadedFile($png, 'logo.png', 'image/png', null, true)]])->assertOk()->json('media.0');

        $stored = imagecreatefromwebp(Storage::disk('public')->path(SiteMedia::withoutGlobalScopes()->find($logo['id'])->path));
        $this->assertSame(127, (imagecolorat($stored, 5, 5) >> 24) & 0x7F, 'the transparent corner stays transparent');

        $before = Storage::disk('public')->allFiles();
        $this->actingAs($owner)->postJson(route('club.website.media.store'), ['images' => [UploadedFile::fake()->createWithContent('evil.svg', '<svg onload="alert(1)"/>')]])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('club.website.media.store'), ['images' => [UploadedFile::fake()->createWithContent('fake.jpg', 'not an image')]])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('club.website.media.store'), ['images' => [UploadedFile::fake()->create('big.jpg', 999999, 'image/jpeg')]])->assertStatus(422);
        $this->assertSame($before, Storage::disk('public')->allFiles());
        $this->assertSame(1, $this->asTenant($club, fn () => SiteMedia::count()));
    }

    public function test_an_image_in_use_cannot_be_deleted_and_a_free_one_can(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $used = $this->actingAs($owner)->postJson(route('club.website.media.store'), ['images' => [$this->image('a.jpg', 600, 400)]])->json('media.0');
        $free = $this->actingAs($owner)->postJson(route('club.website.media.store'), ['images' => [$this->image('b.jpg', 600, 400)]])->json('media.0');
        [$doc, $v] = $this->editor($club);
        $doc['sections'][0]['data']['image'] = ['id' => $used['id'], 'fit' => 'auto'];
        $this->save($club, $doc, $v)->assertSessionHasNoErrors();

        $this->actingAs($owner)->deleteJson(route('club.website.media.destroy', ['media' => $used['id']]))->assertStatus(422)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'المسودة'))->etc());
        $path = $this->asTenant($club, fn () => SiteMedia::find($free['id'])->path);
        $this->actingAs($owner)->deleteJson(route('club.website.media.destroy', ['media' => $free['id']]))->assertOk();
        Storage::disk('public')->assertMissing($path);
        $this->assertTrue($this->asTenant($club, fn () => SiteMedia::whereKey($used['id'])->exists()));
    }

    public function test_alt_text_is_stored_and_reaches_the_page(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $m = $this->actingAs($owner)->postJson(route('club.website.media.store'), ['images' => [$this->image('a.jpg', 1200, 800)]])->json('media.0');
        $this->actingAs($owner)->patchJson(route('club.website.media.update', ['media' => $m['id']]), ['alt' => 'صالة الأوزان'])->assertOk()->assertJsonPath('media.alt', 'صالة الأوزان');
        [$doc, $v] = $this->editor($club);
        $doc['sections'][0]['data']['image'] = ['id' => $m['id'], 'fit' => 'cover'];
        $this->save($club, $doc, $v);
        $this->publish($club, $v + 1);

        $image = $this->home($club)['page']['sections'][0]['data']['image'];
        $this->assertSame(['صالة الأوزان', 'cover'], [$image['alt'], $image['fit']]);
    }

    public function test_only_the_owner_or_staff_with_the_permission_manage_it_and_never_another_club(): void
    {
        $club = $this->club();
        $other = $this->club('Other');
        $manager = $this->staff($club, 'manager');
        [$doc, $v] = $this->editor($club);

        $this->actingAs($manager)->get(route('club.website.edit'))->assertForbidden();
        $this->actingAs($manager)->put(route('club.website.draft.save'), ['doc' => $doc, 'version' => $v])->assertForbidden();
        $this->actingAs($manager)->post(route('club.website.publish'), ['version' => $v])->assertForbidden();
        $this->actingAs($manager)->postJson(route('club.website.media.store'), ['images' => [$this->image('a.jpg', 10, 10)]])->assertForbidden();

        // Another club's image or revision cannot be used or reached.
        $theirs = $this->actingAs($this->owner($other))->postJson(route('club.website.media.store'), ['images' => [$this->image('t.jpg', 600, 400)]])->json('media.0');
        [$otherDoc, $ov] = $this->editor($other);
        $this->save($other, $otherDoc, $ov);
        $this->publish($other, $ov + 1);
        $theirRevision = $this->asTenant($other, fn () => SiteRevision::first());

        $doc['sections'][0]['data']['image'] = ['id' => $theirs['id'], 'fit' => 'auto'];
        $this->save($club, $doc, $v)->assertSessionHasErrors('doc.sections.0.data.image');
        $this->actingAs($this->owner($club))->deleteJson(route('club.website.media.destroy', ['media' => $theirs['id']]))->assertNotFound();
        $this->actingAs($this->owner($club))->post(route('club.website.revisions.restore', ['revision' => $theirRevision->id]))->assertNotFound();
        $this->assertSame(0, $this->asTenant($club, fn () => SiteRevision::count()));
    }

    public function test_system_sections_show_live_public_data_only(): void
    {
        $club = $this->club();
        $plan = $this->plan($club, ['name' => 'الباقة الذهبية', 'is_public' => true]);
        $this->plan($club, ['name' => 'باقة داخلية', 'is_public' => false]);
        $program = $this->program($club, ['name' => 'يوغا', 'is_public' => true]);
        $this->classSession($club, $program, ['starts_at' => now()->addDay()->setTime(18, 0), 'ends_at' => now()->addDay()->setTime(19, 0)]);
        $this->asTenant($club, fn () => Trainer::create(['name' => 'سارة', 'is_public' => true, 'is_active' => true, 'phone' => '96899998888', 'email' => 'private@trainer.test']));

        $props = $this->home($club);
        $sections = collect($props['page']['sections']);
        $plans = $sections->firstWhere('type', 'plans')['feed']['plans'];
        $this->assertSame(['الباقة الذهبية'], array_column($plans, 'name'));
        $this->assertSame(route('site.join', ['club' => $club->slug, 'plan' => $plan->id]), $plans[0]['join_url']);
        $sessions = collect($sections->firstWhere('type', 'schedule')['feed']['days'])->flatMap(fn ($d) => $d['sessions']);
        $this->assertSame('يوغا', $sessions->first()['program']);
        $this->assertSame('سارة', $sections->firstWhere('type', 'trainers')['feed']['trainers'][0]['name']);
        $json = json_encode($props, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('private@trainer.test', $json);
        $this->assertStringNotContainsString('96899998888', $json);
        $this->assertStringNotContainsString('باقة داخلية', $json);

        // A change in the system shows at once, without publishing again.
        $this->asTenant($club, fn () => $plan->update(['name' => 'الباقة الماسية']));
        $this->assertSame('الباقة الماسية', collect($this->home($club)['page']['sections'])->firstWhere('type', 'plans')['feed']['plans'][0]['name']);
    }

    public function test_shop_join_portal_and_payment_links_keep_working(): void
    {
        $club = $this->club();
        $this->plan($club, ['is_public' => true]);
        $props = $this->home($club);
        $this->assertSame(route('site.join', ['club' => $club->slug]), $props['chrome']['header']['cta']['href']);
        $this->get(route('site.join', ['club' => $club->slug]))->assertOk();
        $this->get(route('portal.login', ['club' => $club->slug]))->assertOk();
        $this->get(route('site.plans', ['club' => $club->slug]))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('chrome'));
        $this->get(route('site.contact', ['club' => $club->slug]))->assertOk();
        // The shop is closed for a new club: its link resolves to nothing rather than to a 404.
        $this->assertNull(app(SiteLinks::class)::resolve('@shop', '/', false));
    }

    public function test_uploaded_images_live_on_the_persistent_disk_not_in_the_built_pages(): void
    {
        $this->assertSame(storage_path('app/public'), config('filesystems.disks.public.root'));
        $club = $this->club();
        $m = $this->actingAs($this->owner($club))->postJson(route('club.website.media.store'), ['images' => [$this->image('a.jpg', 600, 400)]])->json('media.0');
        $path = $this->asTenant($club, fn () => SiteMedia::find($m['id'])->path);

        $this->assertStringStartsWith('tenants/'.$club->id.'/site/', $path);
        // The deploy rebuilds only public/build; it never removes storage/ (scripts/deploy.sh).
        $deploy = (string) file_get_contents(base_path('scripts/deploy.sh'));
        $this->assertStringContainsString('public/build.new', $deploy);
        $this->assertDoesNotMatchRegularExpression('#rm\s+-[a-z]*r[a-z]*\s+[^\n]*storage#', $deploy);
    }

    public function test_the_draft_content_service_never_writes_the_live_site_on_save(): void
    {
        $club = $this->club();
        [$doc, $v] = $this->editor($club);
        $this->save($club, $doc, $v);
        $this->assertNull($this->asTenant($club, fn () => SiteSetting::first()->published));
        $this->assertTrue($this->asTenant($club, fn () => app(SiteContent::class)->status(SiteSetting::first())['never_published']));
    }
}
