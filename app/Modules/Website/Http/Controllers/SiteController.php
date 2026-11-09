<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Notifications\StaffAlerts;
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

        return $this->page('Site/Home', ['page' => $rendered['page']], null, null, null, [
            '@context' => 'https://schema.org',
            '@type' => 'HealthClub',
            'name' => $tenant->name,
            'url' => route('site.home'),
            'telephone' => $rendered['site']['contact_phone'],
            'image' => $tenant->logoUrl(),
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => $rendered['site']['address'], 'addressLocality' => $tenant->city, 'addressCountry' => $tenant->country],
        ], $rendered);
    }

    public function plans(): Response
    {
        $plans = MembershipPlan::public()->with('programs:id,name')->orderBy('sort')->get();

        return $this->sitePage('plans', 'Site/Plans', [
            'plans' => $plans->map(fn ($p) => GymPresenter::plan($p) + ['programs' => $p->programs->pluck('name')]),
            'ptPackages' => PtPackage::where('is_active', true)->where('is_public', true)->orderBy('price')->get(['id', 'name', 'description', 'sessions_count', 'session_minutes', 'price', 'validity_days']),
        ], [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => $plans->values()->map(fn ($p, $i) => [
                '@type' => 'ListItem', 'position' => $i + 1,
                'item' => ['@type' => 'Offer', 'name' => $p->name, 'price' => (string) $p->price, 'priceCurrency' => app(TenantContext::class)->require()->currency],
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
            'sessions' => ClassSession::with(['program:id,name,color', 'trainer:id,name'])
                ->where('status', ClassSession::SCHEDULED)
                ->whereHas('program', fn ($q) => $q->where('is_public', true))
                ->between($start, $start->addDays(7))->orderBy('starts_at')->get()
                ->map(fn ($s) => GymPresenter::session($s)),
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

        return $this->page('Site/Post', ['post' => $this->post($post) + ['body' => $post->body]], $post->title, $post->excerpt, SiteSetting::url($post->image_path), [
            '@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $post->title, 'datePublished' => $post->published_at?->toIso8601String(),
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

        return back()->with('success', 'تم استلام رسالتك بنجاح');
    }

    public function sitemap(TenantContext $tenants): HttpResponse
    {
        $urls = collect(['site.home', 'site.plans', 'site.programs', 'site.trainers', 'site.schedule', 'site.news', 'site.contact', 'site.join'])
            ->map(fn ($r) => route($r));
        SitePost::visible()->get(['slug'])->each(fn ($p) => $urls->push(route('site.post', ['slug' => $p->slug])));

        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$urls->map(fn ($u) => '<url><loc>'.e($u).'</loc></url>')->implode('')
            .'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /** @return array<string, mixed> */
    private function post(SitePost $p): array
    {
        return [
            'id' => $p->id, 'type' => $p->type, 'title' => $p->title, 'slug' => $p->slug, 'excerpt' => $p->excerpt,
            'image_url' => SiteSetting::url($p->image_path), 'published_at' => $p->published_at?->toDateString(),
            'ends_on' => $p->ends_on?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function program(Program $p): array
    {
        return $p->only('id', 'name', 'slug', 'category', 'level', 'description', 'color', 'duration_minutes') + ['image_url' => $p->imageUrl()];
    }

    /** @return array<string, mixed> */
    private function trainer(Trainer $t): array
    {
        return $t->only('id', 'name', 'bio', 'specialties', 'color') + ['photo_url' => $t->photoUrl()];
    }
}
