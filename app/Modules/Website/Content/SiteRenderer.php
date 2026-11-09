<?php

namespace App\Modules\Website\Content;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\Program;
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
 * Hidden sections and sections with nothing to show are left out, and so are menu links to them. Only public
 * fields of public records are read: no member, staff contact or stock figure reaches the page.
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

    private Tenant $tenant;

    public function __construct(private TenantContext $tenants, private ShopSettings $shop) {}

    /**
     * @param  array<string, mixed>  $doc
     * @return array{chrome: array<string, mixed>, page: array{sections: list<array<string, mixed>>}, site: array<string, mixed>, seo: array{title: string, description: string}}
     */
    public function render(array $doc, bool $preview = false): array
    {
        $this->tenant = $this->tenants->require();
        $this->homeUrl = route('site.home');
        $this->shopOpen = $this->shop->shopOpen($this->tenant);
        $this->signup = (bool) $this->tenant->setting('member_signup_enabled', true);

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
            ],
            'page' => ['sections' => $sections],
            'site' => $this->siteInfo($doc),
            'seo' => ['title' => (string) ($doc['seo']['title'] ?? ''), 'description' => (string) ($doc['seo']['description'] ?? '')],
        ];
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
                'href' => is_string($value) ? SiteLinks::resolve($value, $this->homeUrl, $this->shopOpen) : null,
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
                return ['programs' => Program::public()->orderBy('sort')->limit($limit(6))->get()->map(fn (Program $p) => $p->only('id', 'name', 'description', 'category', 'level', 'duration_minutes') + ['image_url' => $p->imageUrl()])->all(), 'all_url' => route('site.programs')];
            case 'trainers':
                return ['trainers' => Trainer::public()->orderBy('sort')->limit($limit(4))->get()->map(fn (Trainer $t) => ['id' => $t->id, 'name' => $t->name, 'bio' => $t->bio, 'specialties' => $t->specialties, 'photo_url' => $t->photoUrl()])->all(), 'all_url' => route('site.trainers')];
            case 'plans':
                return ['plans' => $this->plans($limit(3)), 'all_url' => route('site.plans'), 'currency' => $this->tenant->currency];
            case 'join':
                return $this->signup ? ['plans' => ($data['show_plans'] ?? false) ? $this->plans(6) : [], 'join_url' => route('site.join'), 'currency' => $this->tenant->currency] : null;
            case 'schedule':
                return $this->schedule(max(1, min(14, (int) ($data['days'] ?? 7))), (bool) ($data['show_trainer'] ?? true));
            case 'products':
                return $this->shopOpen ? ['products' => $this->products($limit(4)), 'shop_url' => route('site.shop'), 'currency' => $this->tenant->currency] : null;
            case 'posts':
                $kind = $data['kind'] ?? 'all';

                return ['posts' => SitePost::visible()->when($kind !== 'all', fn ($q) => $q->where('type', $kind))->latest('published_at')->limit(max(1, min(9, (int) ($data['limit'] ?? 3))))->get()
                    ->map(fn (SitePost $p) => ['id' => $p->id, 'type' => $p->type, 'title' => $p->title, 'excerpt' => $p->excerpt, 'image_url' => $p->image_path ? Storage::disk('public')->url($p->image_path) : null, 'published_at' => $p->published_at?->toDateString(), 'ends_on' => $p->ends_on?->toDateString(), 'url' => route('site.post', ['slug' => $p->slug])])->all(),
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
            'id' => $p->id, 'name' => $p->name, 'description' => $p->description, 'price' => (string) $p->price,
            'signup_fee' => $p->signup_fee !== null ? (string) $p->signup_fee : null, 'duration_label' => $p->durationLabel(),
            'type_label' => MembershipPlan::TYPES[$p->type] ?? $p->type, 'features' => $p->features, 'is_featured' => (bool) $p->is_featured,
            'join_url' => route('site.join', ['plan' => $p->id]),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function products(int $limit): array
    {
        return Product::onWebsite()->with('category:id,name')->orderBy('name')->limit($limit)->get()->map(function (Product $p) {
            $available = $p->available();

            return ['id' => $p->id, 'name' => $p->name, 'price' => (string) $p->price, 'category' => $p->category?->name, 'image_url' => $p->imageUrl(),
                'in_stock' => $available === null || $available >= 1, 'url' => route('site.shop.product', ['product' => $p->id])];
        })->all();
    }

    /** @return array<string, mixed> */
    private function schedule(int $days, bool $showTrainer): array
    {
        $start = CarbonImmutable::today();
        $sessions = ClassSession::with(['program:id,name', 'trainer:id,name'])
            ->where('status', ClassSession::SCHEDULED)
            ->whereHas('program', fn ($q) => $q->where('is_public', true))
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
                    'program' => $s->program?->name,
                    'trainer' => $showTrainer ? $s->trainer?->name : null,
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
        $s = ClassSession::with('program:id,name')->where('status', ClassSession::SCHEDULED)
            ->whereHas('program', fn ($q) => $q->where('is_public', true))
            ->where('starts_at', '>', now())->where('starts_at', '<', now()->addDays(7))->orderBy('starts_at')->first();

        return $s ? ['name' => (string) $s->program?->name, 'date' => $s->starts_at->toDateString(), 'time' => $s->starts_at->format('H:i'), 'day_label' => $this->dayLabel(CarbonImmutable::parse($s->starts_at))] : null;
    }

    private function dayLabel(CarbonImmutable $date): string
    {
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
        if ($href === '@join' && ! $this->signup) {
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
