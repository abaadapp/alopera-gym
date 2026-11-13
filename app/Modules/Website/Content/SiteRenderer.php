<?php

namespace App\Modules\Website\Content;

use App\Core\Support\SiteLanguage;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ShopSettings;
use App\Modules\Website\Models\SiteMedia;
use App\Modules\Website\Models\SitePost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a website document into what a visitor's page receives: images with their size and shape, links as real
 * addresses, and the dynamic sections filled from the gym system at the moment of the request (so a plan or a
 * class changed in the panel shows on the site at once, without entering it twice).
 *
 * Hidden sections and sections with nothing to show are left out, and so are menu links to them. A button or
 * link to a page of the site that has nothing to show yet (the plans page with no plans, the sign-up page with
 * nothing to sign up for, the news page with no news…) is left out too, so a visitor never lands on an empty page.
 * Only public fields of public records are read: no member, staff contact or stock figure reaches the page.
 */
final class SiteRenderer
{
    private const WEEKDAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    private const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

    /** @var array<int, array<string, mixed>> */
    private array $media = [];

    private string $homeUrl = '';

    private bool $shopOpen = false;

    private bool $signup = true;

    /** @var array<string, bool> page token => whether that page has something to show, worked out once per render */
    private array $pages = [];

    /** What a visible section of the gym system is waiting for before it shows. */
    private const WAITING = [
        'programs' => 'لا توجد برامج ظاهرة في الموقع بعد.',
        'schedule' => 'لا توجد حصص مجدولة في الأيام القادمة.',
        'trainers' => 'لا يوجد مدربون ظاهرون في الموقع بعد.',
        'plans' => 'لا توجد باقات عضوية منشورة بعد.',
        'products' => 'لا توجد منتجات معروضة في الموقع، أو المتجر مغلق.',
        'posts' => 'لا توجد أخبار أو عروض منشورة بعد.',
        'join' => 'لا توجد باقات منشورة للتسجيل بعد، أو التسجيل الإلكتروني متوقف.',
    ];

    private Tenant $tenant;

    public function __construct(private TenantContext $tenants, private ShopSettings $shop) {}

    /**
     * @param  array<string, mixed>  $doc
     * @return array{chrome: array<string, mixed>, page: array{sections: list<array<string, mixed>>}, site: array<string, mixed>, seo: array{title: string, description: string}}
     */
    public function render(array $doc, bool $preview = false): array
    {
        $this->begin();
        // The page's language: the English site takes the owner's English words (SiteTranslation).
        $doc = SiteTranslation::apply($doc, SiteLanguage::current());
        $this->homeUrl = route('site.home');

        $sections = [];
        $this->loadMedia($doc);
        foreach ($doc['sections'] ?? [] as $section) {
            $rendered = ($section['visible'] ?? true) ? $this->section($section) : null;
            if ($rendered) {
                $sections[] = $rendered;
            }
        }
        $anchors = array_flip(array_column($sections, 'id'));
        // A link to a section of the home page is kept only when that section shows (it is not hidden or empty).
        $keep = fn (?array $link) => $link && (! str_starts_with($link['_href'], '#') || isset($anchors[substr($link['_href'], 1)]));
        $links = fn (array $items) => collect($items)->map(fn ($l) => $this->link($l))->filter($keep)->map(fn ($l) => $this->public($l))->values()->all();

        $cta = $this->link($doc['header']['cta'] ?? []);
        // A button inside a section that points at a section not shown (no classes yet, hidden) is dropped too.
        $sections = array_map(fn ($s) => ['data' => $this->settleLinks($s['data'], $anchors)] + $s, $sections);

        return [
            'chrome' => [
                'theme' => $doc['theme'],
                'announcement' => ($doc['announcement'] ?? '') ?: null,
                'header' => ['links' => $links($doc['header']['links'] ?? []), 'cta' => $keep($cta) ? $this->public($cta) : null, 'show_login' => (bool) ($doc['header']['show_login'] ?? true)],
                'footer' => ['line' => ($doc['footer']['line'] ?? '') ?: null, 'links' => $links($doc['footer']['links'] ?? []), 'show_hours' => (bool) ($doc['footer']['show_hours'] ?? true), 'show_social' => (bool) ($doc['footer']['show_social'] ?? true)],
                'home_url' => $this->homeUrl,
                'preview' => $preview,
                'pages' => SitePages::of($doc),
            ],
            'page' => ['sections' => $sections],
            'site' => $this->siteInfo($doc),
            'seo' => ['title' => (string) ($doc['seo']['title'] ?? ''), 'description' => (string) ($doc['seo']['description'] ?? '')],
        ];
    }

    /**
     * The visible sections fed by the gym system that show nothing at the moment, with what each is waiting for,
     * so «إدارة الموقع» can tell the owner why a section is not on the site (it shows by itself once there is data).
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, string> section id => reason
     */
    public function waiting(array $doc): array
    {
        $shown = array_flip(array_column($this->render($doc, true)['page']['sections'], 'id'));

        return collect($doc['sections'] ?? [])
            ->filter(fn ($s) => ($s['visible'] ?? true) && isset(self::WAITING[$s['type'] ?? '']) && ! isset($shown[$s['id'] ?? '']))
            ->mapWithKeys(fn ($s) => [$s['id'] => self::WAITING[$s['type']]])->all();
    }

    /**
     * Whether a page of the site has something to show, the same way that page reads it. Anything that is not a
     * page token (a section, an outside address) is not a page and is judged elsewhere.
     */
    /**
     * The route names of the site's own pages that have something to show, by the rule the menu drops the others with
     * (pageShows): the sitemap lists these, so it never offers search engines a page the site itself hides as empty.
     *
     * @return list<string>
     */
    public function pagesWithContent(): array
    {
        $this->begin();

        return collect(SiteLinks::PAGES)->except('@portal')->filter(fn ($page, $href) => $this->pageShows($href))->map(fn ($page) => $page[0])->values()->all();
    }

    private function begin(): void
    {
        $this->tenant = $this->tenants->require();
        $this->shopOpen = $this->shop->shopOpen($this->tenant);
        $this->signup = (bool) $this->tenant->setting('member_signup_enabled', true);
        $this->pages = [];
    }

    private function pageShows(string $href): bool
    {
        if (! isset(SiteLinks::PAGES[$href])) {
            return true;
        }

        return $this->pages[$href] ??= match ($href) {
            '@join' => $this->signup && MembershipPlan::public()->exists(),
            '@plans' => MembershipPlan::public()->exists() || PtPackage::where('is_active', true)->where('is_public', true)->exists(),
            '@programs' => Program::public()->exists(),
            '@trainers' => Trainer::public()->exists(),
            '@schedule' => ClassSession::where('status', ClassSession::SCHEDULED)->whereHas('program', fn ($q) => $q->where('is_public', true)->where('is_active', true))
                ->between(CarbonImmutable::today(), CarbonImmutable::today()->addDays(7))->exists(),
            '@news' => SitePost::visible()->exists(),
            '@shop' => $this->shopOpen,
            default => true,
        };
    }

    /** @param array<string, mixed> $data @param array<string, int> $anchors @return array<string, mixed> */
    private function settleLinks(array $data, array $anchors): array
    {
        foreach ($data as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if (array_key_exists('_href', $value)) {
                $data[$key] = str_starts_with($value['_href'], '#') && ! isset($anchors[substr($value['_href'], 1)]) ? null : $this->public($value);
            } else {
                $data[$key] = $this->settleLinks($value, $anchors);
            }
        }

        return $data;
    }

    /** The contact block every site page shows (header, footer, contact page), from the document. @return array<string, mixed> */
    private function siteInfo(array $doc): array
    {
        $c = $doc['contact'] ?? [];

        return [
            'announcement' => ($doc['announcement'] ?? '') ?: null,
            'contact_phone' => ($c['phone'] ?? '') ?: $this->tenant->phone,
            'whatsapp_number' => ($c['whatsapp'] ?? '') ?: null,
            'contact_email' => ($c['email'] ?? '') ?: $this->tenant->email,
            'address' => ($c['address'] ?? '') ?: $this->tenant->address,
            'map_url' => ($c['map_url'] ?? '') ?: null,
            'working_hours' => collect($c['hours'] ?? [])->map(fn ($h) => ['days' => $h['days'], 'hours' => $h['hours']])->values()->all(),
            'social_links' => array_filter($c['social'] ?? []),
        ];
    }

    /** @param array<string, mixed> $section @return array<string, mixed>|null */
    private function section(array $section): ?array
    {
        $schema = SectionTypes::get($section['type'] ?? '');
        if (! $schema) {
            return null;
        }
        $data = $this->fields($schema['fields'], $section['data'] ?? []);
        $feed = $this->feed($section['type'], $data);
        if (! $this->hasContent($section['type'], $data, $feed)) {
            return null;
        }

        return [
            'id' => $section['id'],
            'type' => $section['type'],
            'tone' => ($section['tone'] ?? 'auto') === 'auto' ? $schema['tone'] : $section['tone'],
            'data' => $data,
            'feed' => $feed,
        ];
    }

    /** @param list<array<string, mixed>> $fields @param array<string, mixed> $values @return array<string, mixed> */
    private function fields(array $fields, array $values): array
    {
        $out = [];
        foreach ($fields as $field) {
            $value = $values[$field['key']] ?? null;
            $out[$field['key']] = match ($field['type']) {
                'image' => $this->image($value),
                'link' => ($l = $this->link(is_array($value) ? $value : [])) && $l['label'] !== '' ? $l : null,
                'href' => is_string($value) && $this->pageShows($value) ? SiteLinks::resolve($value, $this->homeUrl, $this->shopOpen) : null,
                'list' => collect(is_array($value) ? $value : [])->map(fn ($item) => ['id' => $item['id'] ?? null] + $this->fields($field['fields'], is_array($item) ? $item : []))->values()->all(),
                default => $value,
            };
        }

        return $out;
    }

    /** @param array<string, mixed> $data @return array<string, mixed>|null */
    private function feed(string $type, array &$data): ?array
    {
        $limit = fn (int $default) => max(1, min(12, (int) ($data['limit'] ?? $default)));

        switch ($type) {
            case 'hero':
                return ['next_class' => ($data['show_next_class'] ?? false) ? $this->nextClass() : null];
            case 'programs':
                return ['programs' => Program::public()->orderBy('sort')->limit($limit(6))->get()->map(fn (Program $p) => ['name' => SiteLanguage::pick($p->name, $p->name_en), 'description' => SiteLanguage::pick($p->description, $p->description_en)] + $p->only('id', 'category', 'level', 'duration_minutes') + ['image_url' => $p->imageUrl()])->all(), 'all_url' => route('site.programs')];
            case 'trainers':
                return ['trainers' => Trainer::public()->orderBy('sort')->limit($limit(4))->get()->map(fn (Trainer $t) => ['id' => $t->id, 'name' => SiteLanguage::pick($t->name, $t->name_en), 'bio' => SiteLanguage::pick($t->bio, $t->bio_en), 'specialties' => SiteLanguage::pickList($t->specialties, $t->specialties_en), 'photo_url' => $t->photoUrl()])->all(), 'all_url' => route('site.trainers')];
            case 'plans':
                return ['plans' => $this->plans($limit(3)), 'all_url' => route('site.plans'), 'currency' => $this->tenant->currency];
            case 'join':
                return $this->pageShows('@join') ? ['plans' => ($data['show_plans'] ?? false) ? $this->plans(6) : [], 'join_url' => route('site.join'), 'currency' => $this->tenant->currency] : null;
            case 'schedule':
                return $this->schedule(max(1, min(14, (int) ($data['days'] ?? 7))), (bool) ($data['show_trainer'] ?? true));
            case 'products':
                return $this->shopOpen ? ['products' => $this->products($limit(4)), 'shop_url' => route('site.shop'), 'currency' => $this->tenant->currency] : null;
            case 'posts':
                $kind = $data['kind'] ?? 'all';

                return ['posts' => SitePost::visible()->when($kind !== 'all', fn ($q) => $q->where('type', $kind))->latest('published_at')->limit(max(1, min(9, (int) ($data['limit'] ?? 3))))->get()
                    ->map(fn (SitePost $p) => ['id' => $p->id, 'type' => $p->type, 'title' => SiteLanguage::pick($p->title, $p->title_en), 'excerpt' => SiteLanguage::pick($p->excerpt, $p->excerpt_en), 'image_url' => $p->image_path ? Storage::disk('public')->url($p->image_path) : null, 'published_at' => $p->published_at?->toDateString(), 'ends_on' => $p->ends_on?->toDateString(), 'url' => route('site.post', ['slug' => $p->slug])])->all(),
                    'all_url' => route('site.news')];
            case 'contact':
                return ['send_url' => route('site.contact.send')];
            case 'video':
                return ['embed_url' => SiteLinks::videoEmbed((string) ($data['url'] ?? ''))];
        }

        return null;
    }

    /** @param array<string, mixed> $data @param array<string, mixed>|null $feed */
    private function hasContent(string $type, array &$data, ?array $feed): bool
    {
        // Items without their main field are dropped, so an empty card or an image-less gallery tile never shows.
        $filled = function (string $list, string $key) use (&$data): array {
            return $data[$list] = array_values(array_filter($data[$list] ?? [], fn ($i) => ! empty($i[$key])));
        };

        return match ($type) {
            'hero' => ($data['title'] ?? '') !== '' || ($data['title_2'] ?? '') !== '' || $data['image'] !== null,
            'intro' => ($data['title'] ?? '') !== '' || ($data['body'] ?? '') !== '',
            'image_text' => ($data['title'] ?? '') !== '' || ($data['body'] ?? '') !== '' || $data['image'] !== null,
            'gallery' => $filled('items', 'image') !== [],
            'services' => $filled('items', 'title') !== [],
            'testimonials' => $filled('items', 'quote') !== [],
            'stats' => $filled('items', 'value') !== [],
            'faq' => (($data['items'] = array_values(array_filter($data['items'] ?? [], fn ($i) => ($i['q'] ?? '') !== '' && ($i['a'] ?? '') !== '')))) !== [],
            'cta' => ($data['title'] ?? '') !== '' || ($data['primary'] ?? null) !== null,
            'video' => ! empty($feed['embed_url']),
            'programs' => ($feed['programs'] ?? []) !== [],
            'trainers' => ($feed['trainers'] ?? []) !== [],
            'plans' => ($feed['plans'] ?? []) !== [],
            'products' => ($feed['products'] ?? []) !== [],
            'posts' => ($feed['posts'] ?? []) !== [],
            'schedule' => collect($feed['days'] ?? [])->sum(fn ($d) => count($d['sessions'])) > 0,
            'join' => $feed !== null,
            'contact' => true,
            default => false,
        };
    }

    /** @return list<array<string, mixed>> */
    private function plans(int $limit): array
    {
        return MembershipPlan::public()->orderByDesc('is_featured')->orderBy('sort')->limit($limit)->get()->map(fn (MembershipPlan $p) => [
            'id' => $p->id, 'name' => SiteLanguage::pick($p->name, $p->name_en), 'description' => SiteLanguage::pick($p->description, $p->description_en), 'price' => (string) $p->price,
            'signup_fee' => $p->signup_fee !== null ? (string) $p->signup_fee : null, 'duration_label' => $p->siteDurationLabel(),
            'type_label' => SiteLanguage::word(MembershipPlan::TYPES[$p->type] ?? $p->type), 'features' => SiteLanguage::pickList($p->features, $p->features_en), 'is_featured' => (bool) $p->is_featured,
            'join_url' => route('site.join', ['plan' => $p->id]),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function products(int $limit): array
    {
        return Product::onWebsite()->with('category:id,name,name_en')->orderBy('name')->limit($limit)->get()->map(function (Product $p) {
            $available = $p->available();

            return ['id' => $p->id, 'name' => SiteLanguage::pick($p->name, $p->name_en), 'price' => (string) $p->price, 'category' => SiteLanguage::pick($p->category?->name, $p->category?->name_en), 'image_url' => $p->imageUrl(), 'thumb_url' => $p->thumbUrl(),
                'in_stock' => $available === null || $available >= 1, 'url' => route('site.shop.product', ['product' => $p->id])];
        })->all();
    }

    /** @return array<string, mixed> */
    private function schedule(int $days, bool $showTrainer): array
    {
        $start = CarbonImmutable::today();
        $sessions = ClassSession::with(['program:id,name,name_en', 'trainer:id,name,name_en'])
            ->where('status', ClassSession::SCHEDULED)
            ->whereHas('program', fn ($q) => $q->where('is_public', true)->where('is_active', true))
            ->where('starts_at', '>=', now())
            ->between($start, $start->addDays($days))->orderBy('starts_at')->limit(200)->get();

        $byDay = $sessions->groupBy(fn (ClassSession $s) => $s->starts_at->toDateString());
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->addDays($i);
            $out[] = [
                'date' => $date->toDateString(),
                'label' => $this->dayLabel($date),
                'sessions' => ($byDay[$date->toDateString()] ?? collect())->map(fn (ClassSession $s) => [
                    'id' => $s->id,
                    'program' => SiteLanguage::pick($s->program?->name, $s->program?->name_en),
                    'trainer' => $showTrainer ? SiteLanguage::pick($s->trainer?->name, $s->trainer?->name_en) : null,
                    'date' => $s->starts_at->toDateString(),
                    'time' => $s->starts_at->format('H:i'),
                    'end_time' => $s->ends_at->format('H:i'),
                    'duration_minutes' => (int) $s->starts_at->diffInMinutes($s->ends_at),
                    'spots_left' => $s->capacity ? max(0, (int) $s->seatsLeft()) : null,
                    'full' => (bool) $s->isFull(),
                ])->values()->all(),
            ];
        }

        return ['days' => $out, 'booking_url' => route('portal.classes'), 'schedule_url' => route('site.schedule')];
    }

    /** @return array<string, string>|null */
    private function nextClass(): ?array
    {
        $s = ClassSession::with('program:id,name,name_en')->where('status', ClassSession::SCHEDULED)
            ->whereHas('program', fn ($q) => $q->where('is_public', true)->where('is_active', true))
            ->where('starts_at', '>', now())->where('starts_at', '<', now()->addDays(7))->orderBy('starts_at')->first();

        return $s ? ['name' => (string) SiteLanguage::pick($s->program?->name, $s->program?->name_en), 'date' => $s->starts_at->toDateString(), 'time' => $s->starts_at->format('H:i'), 'day_label' => $this->dayLabel(CarbonImmutable::parse($s->starts_at))] : null;
    }

    private function dayLabel(CarbonImmutable $date): string
    {
        if (SiteLanguage::isEnglish()) {
            return $date->locale('en')->translatedFormat('l j F');
        }

        return self::WEEKDAYS[$date->dayOfWeek].' '.$date->day.' '.self::MONTHS[$date->month - 1];
    }

    /** @param array<string, mixed> $doc */
    private function loadMedia(array $doc): void
    {
        $ids = array_keys(SiteDocument::imageUses($doc));
        $this->media = $ids === [] ? [] : SiteMedia::whereIn('id', $ids)->get()->mapWithKeys(fn (SiteMedia $m) => [$m->id => [
            'url' => Storage::disk('public')->url($m->path),
            'width' => $m->width,
            'height' => $m->height,
            'orientation' => $m->orientation,
            'alt' => (string) ($m->alt ?: $m->title ?: ''),
        ]])->all();
    }

    /** @return array<string, mixed>|null */
    private function image(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $fit = in_array($value['fit'] ?? 'auto', ['auto', 'contain', 'cover'], true) ? $value['fit'] : 'auto';
        if (isset($value['legacy_path']) && is_string($value['legacy_path'])) {
            return ['url' => Storage::disk('public')->url($value['legacy_path']), 'width' => null, 'height' => null, 'orientation' => null, 'alt' => '', 'fit' => $fit];
        }
        $media = $this->media[(int) ($value['id'] ?? 0)] ?? null;

        return $media ? $media + ['fit' => $fit] : null;
    }

    /** @param array<string, mixed> $link @return array<string, mixed>|null */
    private function link(array $link): ?array
    {
        $href = (string) ($link['href'] ?? '');
        if (! $this->pageShows($href)) {
            return null;
        }
        $url = SiteLinks::resolve($href, $this->homeUrl, $this->shopOpen);

        return $url ? ['label' => (string) ($link['label'] ?? ''), 'href' => $url, 'external' => SiteLinks::external($href), '_href' => $href] : null;
    }

    /** @param array<string, mixed> $link @return array<string, mixed> */
    private function public(array $link): array
    {
        unset($link['_href']);

        return $link;
    }
}
