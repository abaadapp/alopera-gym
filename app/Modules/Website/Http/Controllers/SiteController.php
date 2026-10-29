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
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Website\Http\RendersSite;
use App\Modules\Website\Models\ContactMessage;
use App\Modules\Website\Models\SiteMedia;
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

    public function home(TenantContext $tenants, OnlinePaymentService $online): Response
    {
        $tenant = $tenants->require();
        $site = $this->site();

        return $this->page('Site/Home', [
            'sections' => $site->visibleSections(),
            'hero' => [
                'title' => $site->hero_title ?: $tenant->name,
                'subtitle' => $site->hero_subtitle,
                'image_url' => SiteSetting::url($site->hero_image_path),
                'cta' => $site->hero_cta_label ?: 'اشترك الآن',
                'badge' => $site->hero_badge,
            ],
            'features' => $site->features ?? self::defaultFeatures($online->available()),
            'about' => ['title' => $site->about_title, 'body' => $site->about_body, 'image_url' => SiteSetting::url($site->about_image_path)],
            'stats' => $site->stats ?? [],
            'plans' => MembershipPlan::public()->orderByDesc('is_featured')->orderBy('sort')->limit(3)->get()->map(fn ($p) => GymPresenter::plan($p)),
            'programs' => Program::public()->orderBy('sort')->limit(6)->get()->map(fn ($p) => $this->program($p)),
            'trainers' => Trainer::public()->orderBy('sort')->limit(4)->get()->map(fn ($t) => $this->trainer($t)),
            'offers' => SitePost::visible()->where('type', 'offer')->latest('published_at')->limit(3)->get()->map(fn ($p) => $this->post($p)),
            'news' => SitePost::visible()->where('type', 'news')->latest('published_at')->limit(3)->get()->map(fn ($p) => $this->post($p)),
            'gallery' => SiteMedia::where('in_gallery', true)->orderBy('sort')->limit(8)->get()->map(fn ($m) => ['url' => SiteSetting::url($m->path), 'title' => $m->title]),
        ], null, null, null, [
            '@context' => 'https://schema.org',
            '@type' => 'HealthClub',
            'name' => $tenant->name,
            'url' => route('site.home'),
            'telephone' => $site->contact_phone ?: $tenant->phone,
            'image' => $tenant->logoUrl(),
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => $site->address ?: $tenant->address, 'addressLocality' => $tenant->city, 'addressCountry' => $tenant->country],
        ]);
    }

    /**
     * The "why us" cards until the club writes its own. Online payment is only promised when the club
     * has a working payment gateway.
     *
     * @return list<array{title: string, text: string}>
     */
    public static function defaultFeatures(bool $onlinePayments): array
    {
        return [
            ['title' => 'احجز حصتك أونلاين', 'text' => 'شاهد المقاعد المتاحة واحجز من جوالك في ثوانٍ.'],
            $onlinePayments
                ? ['title' => 'دفع إلكتروني آمن', 'text' => 'اشترك وجدد وادفع فواتيرك أونلاين بكل سهولة.']
                : ['title' => 'اشتراك سهل', 'text' => 'اختر باقتك وسجّل بياناتك أونلاين، وأكمل الدفع في الاستقبال.'],
            ['title' => 'مدربون محترفون', 'text' => 'بيئة مريحة ومتابعة من مدربين معتمدين.'],
        ];
    }

    public function plans(): Response
    {
        $plans = MembershipPlan::public()->with('programs:id,name')->orderBy('sort')->get();

        return $this->page('Site/Plans', [
            'plans' => $plans->map(fn ($p) => GymPresenter::plan($p) + ['programs' => $p->programs->pluck('name')]),
            'ptPackages' => PtPackage::where('is_active', true)->where('is_public', true)->orderBy('price')->get(['id', 'name', 'description', 'sessions_count', 'session_minutes', 'price', 'validity_days']),
        ], 'الباقات والأسعار', 'اختر الباقة المناسبة لك: اشتراكات شهرية وسنوية وباقات حصص وتدريب شخصي.', null, [
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
        return $this->page('Site/Programs', [
            'programs' => Program::public()->orderBy('sort')->get()->map(fn ($p) => $this->program($p)),
        ], 'البرامج التدريبية', 'برامجنا وحصصنا الجماعية لكل المستويات.');
    }

    public function trainers(): Response
    {
        return $this->page('Site/Trainers', [
            'trainers' => Trainer::public()->orderBy('sort')->get()->map(fn ($t) => $this->trainer($t)),
        ], 'فريق التدريب', 'تعرّف على فريق المدربين المعتمدين.');
    }

    public function schedule(Request $request): Response
    {
        $start = CarbonImmutable::today();

        return $this->page('Site/Schedule', [
            'sessions' => ClassSession::with(['program:id,name,color', 'trainer:id,name'])
                ->where('status', ClassSession::SCHEDULED)
                ->whereHas('program', fn ($q) => $q->where('is_public', true))
                ->between($start, $start->addDays(7))->orderBy('starts_at')->get()
                ->map(fn ($s) => GymPresenter::session($s)),
            'from' => $start->toDateString(),
        ], 'جدول الحصص', 'جدول الحصص الأسبوعي والمقاعد المتاحة — احجز من بوابة الأعضاء.');
    }

    public function news(): Response
    {
        return $this->page('Site/News', [
            'posts' => SitePost::visible()->latest('published_at')->paginate(9)->through(fn ($p) => $this->post($p)),
        ], 'العروض والأخبار', 'آخر عروض النادي وأخباره.');
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
        return $this->page('Site/Contact', [], 'تواصل معنا', 'العنوان وأوقات العمل وطرق التواصل مع النادي.');
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

        return back()->with('success', 'وصلتنا رسالتك، وسنتواصل معك قريبًا.');
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
