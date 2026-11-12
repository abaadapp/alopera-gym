<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Notifications\StaffAlerts;
use App\Core\Support\SiteLanguage;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Http\GymPresenter;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Website\Content\SiteContent;
use App\Modules\Website\Content\SiteRenderer;
use App\Modules\Website\Http\RendersSite;
use App\Modules\Website\Models\ContactMessage;
use App\Modules\Website\Models\SitePost;
use App\Modules\Website\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Response;

/** The club's public website. Plans, programs, trainers and timetable are read live from the system. */
class SiteController extends Controller
{
    use RendersSite;

    public function home(TenantContext $tenants, SiteContent $content, SiteRenderer $renderer): Response
    {
        $tenant = $tenants->require();
        $site = $this->site();
        // What the owner published from «إدارة الموقع» (or the starting design until then), with the system's live data.
        $rendered = $renderer->render($content->live($site));

        // Only what the club has entered: a phone or an address not entered yet is left out, not sent as null (which
        // search engines report as an invalid value).
        $filled = fn (array $values) => array_filter($values, fn ($v) => $v !== null && $v !== '');

        return $this->page('Site/Home', ['page' => $rendered['page']], null, null, null, $filled([
            '@context' => 'https://schema.org',
            '@type' => 'HealthClub',
            'name' => $tenant->name,
            'url' => route('site.home'),
            'telephone' => $rendered['site']['contact_phone'],
            'image' => $tenant->logoUrl(),
            'address' => $filled(['@type' => 'PostalAddress', 'streetAddress' => $rendered['site']['address'], 'addressLocality' => $tenant->city, 'addressCountry' => $tenant->country]),
        ]), $rendered);
    }

    public function plans(): Response
    {
        $plans = MembershipPlan::public()->with('programs:id,name,name_en')->orderBy('sort')->get();

        return $this->sitePage('plans', 'Site/Plans', [
            'plans' => $plans->map(fn ($p) => GymPresenter::sitePlan($p) + ['programs' => $p->programs->map(fn ($g) => SiteLanguage::pick($g->name, $g->name_en))->values()]),
            'ptPackages' => PtPackage::where('is_active', true)->where('is_public', true)->orderBy('price')->get(['id', 'name', 'name_en', 'description', 'description_en', 'sessions_count', 'session_minutes', 'price', 'validity_days'])
                ->map(fn (PtPackage $p) => ['name' => SiteLanguage::pick($p->name, $p->name_en), 'description' => SiteLanguage::pick($p->description, $p->description_en)] + $p->only('id', 'sessions_count', 'session_minutes', 'price', 'validity_days')),
        ], [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => $plans->values()->map(fn ($p, $i) => [
                '@type' => 'ListItem', 'position' => $i + 1,
                'item' => ['@type' => 'Offer', 'name' => SiteLanguage::pick($p->name, $p->name_en), 'price' => (string) $p->price, 'priceCurrency' => app(TenantContext::class)->require()->currency],
            ])->all(),
        ]);
    }

    public function programs(): Response
    {
        return $this->sitePage('programs', 'Site/Programs', [
            'programs' => Program::public()->orderBy('sort')->get()->map(fn ($p) => $this->program($p)),
        ]);
    }

    public function trainers(): Response
    {
        return $this->sitePage('trainers', 'Site/Trainers', [
            'trainers' => Trainer::public()->orderBy('sort')->get()->map(fn ($t) => $this->trainer($t)),
        ]);
    }

    public function schedule(Request $request): Response
    {
        $start = CarbonImmutable::today();

        return $this->sitePage('schedule', 'Site/Schedule', [
            'sessions' => ClassSession::with(['program:id,name,name_en,color', 'trainer:id,name,name_en'])
                ->where('status', ClassSession::SCHEDULED)
                ->whereHas('program', fn ($q) => $q->where('is_public', true)->where('is_active', true))
                ->between($start, $start->addDays(7))->orderBy('starts_at')->get()
                ->map(fn ($s) => GymPresenter::siteSession($s)),
            'from' => $start->toDateString(),
        ]);
    }

    public function news(): Response
    {
        return $this->sitePage('news', 'Site/News', [
            'posts' => SitePost::visible()->latest('published_at')->paginate(9)->through(fn ($p) => $this->post($p)),
        ]);
    }

    public function showPost(string $slug): Response
    {
        $post = SitePost::visible()->where('slug', $slug)->firstOrFail();

        $title = (string) SiteLanguage::pick($post->title, $post->title_en);

        return $this->page('Site/Post', ['post' => $this->post($post) + ['body' => SiteLanguage::pick($post->body, $post->body_en)]], $title, SiteLanguage::pick($post->excerpt, $post->excerpt_en), SiteSetting::url($post->image_path), [
            '@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $title, 'inLanguage' => SiteLanguage::current(), 'datePublished' => $post->published_at?->toIso8601String(),
        ]);
    }

    public function contact(): Response
    {
        return $this->sitePage('contact', 'Site/Contact', []);
    }

    public function sendContact(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:255'],
            'subject' => ['nullable', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:3000'],
            'website' => ['prohibited'], // honeypot
        ]);
        unset($data['website']);

        app(StaffAlerts::class)->contact(ContactMessage::create($data + ['ip_address' => $request->ip()]));

        return back()->with('success', __('تم استلام رسالتك بنجاح'));
    }

    public function sitemap(TenantContext $tenants, SiteRenderer $renderer): HttpResponse
    {
        // The pages with something to show, as the menu shows them: an empty page (no programs yet) is left out.
        $pages = collect($renderer->pagesWithContent())->map(fn ($r) => [$r, []]);
        SitePost::visible()->get(['slug'])->each(fn ($p) => $pages->push(['site.post', ['slug' => $p->slug]]));

        // Each page in both languages, each entry naming the other (hreflang), Arabic the default (x-default).
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'
            .$pages->map(function (array $page) {
                $in = collect(SiteLanguage::ALL)->mapWithKeys(fn ($l) => [$l => SiteLanguage::in($l, fn () => route($page[0], $page[1]))]);
                $links = $in->map(fn ($u, $l) => '<xhtml:link rel="alternate" hreflang="'.$l.'" href="'.e($u).'"/>')->implode('')
                    .'<xhtml:link rel="alternate" hreflang="x-default" href="'.e($in[SiteLanguage::DEFAULT]).'"/>';

                return $in->map(fn ($u) => '<url><loc>'.e($u).'</loc>'.$links.'</url>')->implode('');
            })->implode('')
            .'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /** @return array<string, mixed> */
    private function post(SitePost $p): array
    {
        return [
            'id' => $p->id, 'type' => $p->type, 'title' => SiteLanguage::pick($p->title, $p->title_en), 'slug' => $p->slug, 'excerpt' => SiteLanguage::pick($p->excerpt, $p->excerpt_en),
            'image_url' => SiteSetting::url($p->image_path), 'published_at' => $p->published_at?->toDateString(),
            'ends_on' => $p->ends_on?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function program(Program $p): array
    {
        return ['name' => SiteLanguage::pick($p->name, $p->name_en), 'description' => SiteLanguage::pick($p->description, $p->description_en)]
            + $p->only('id', 'slug', 'category', 'level', 'color', 'duration_minutes') + ['image_url' => $p->imageUrl()];
    }

    /** @return array<string, mixed> */
    private function trainer(Trainer $t): array
    {
        return ['name' => SiteLanguage::pick($t->name, $t->name_en), 'bio' => SiteLanguage::pick($t->bio, $t->bio_en), 'specialties' => SiteLanguage::pickList($t->specialties, $t->specialties_en)]
            + $t->only('id', 'color') + ['photo_url' => $t->photoUrl()];
    }
}
