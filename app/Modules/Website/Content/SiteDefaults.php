<?php

namespace App\Modules\Website\Content;

use App\Models\Tenant;
use App\Modules\Website\Models\SiteMedia;
use App\Modules\Website\Models\SiteSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The website a club starts from before its owner publishes anything: the approved design's sections and
 * headings, filled with what the club already wrote (contact details, hours, hero text, its own images).
 *
 * Nothing is invented: no prices, names, reviews or facilities — those sections start empty and stay off the
 * public page until the owner fills them; plans, trainers, classes and products come live from the system.
 */
final class SiteDefaults
{
    /**
     * @param  bool  $register  register the club's existing hero/about images in the media library so the editor
     *                          can show them (only when the owner opens «إدارة الموقع», never on a visitor's request)
     * @return array<string, mixed>
     */
    public static function document(SiteSetting $site, Tenant $tenant, bool $register = false): array
    {
        $legacyImage = fn (?string $path) => self::legacyImage($path, $register);
        // A new club's site_settings hold the starter text written when the club was set up (TenantProvisioner);
        // only what the owner changed counts as theirs, the rest takes the approved design's wording.
        $own = fn (?string $value, string $starter) => ($value !== null && trim($value) !== '' && $value !== $starter) ? $value : null;
        $heroTitle = $own($site->hero_title, $tenant->name);
        $heroSubtitle = $own($site->hero_subtitle, 'ناديك للياقة والصحة — برامج متنوعة، فريق تدريب محترف، وأجواء تناسبك.');
        $heroCta = $own($site->hero_cta_label, 'اشترك الآن');
        $seoTitle = $own($site->seo_title, $tenant->name.' — نادٍ رياضي');
        $seoDescription = $own($site->seo_description, 'اشترك في '.$tenant->name.': باقات مرنة، حصص جماعية، تدريب شخصي، وحجز أونلاين.');
        $facts = collect($site->features ?? [])->take(4)->map(fn ($f) => [
            'id' => Str::lower(Str::random(8)),
            'title' => mb_substr((string) ($f['title'] ?? ''), 0, 40),
            'text' => mb_substr((string) ($f['text'] ?? ''), 0, 60),
        ])->filter(fn ($f) => $f['title'] !== '')->values()->all();

        $section = fn (string $id, string $type, array $data = []) => [
            'id' => $id, 'type' => $type, 'visible' => true, 'tone' => 'auto', 'data' => $data + SectionTypes::get($type)['defaults'],
        ];
        $link = fn (string $label, string $href) => ['id' => Str::lower(Str::random(8)), 'label' => $label, 'href' => $href];

        $sections = [
            $section('top', 'hero', [
                // Facts only: the club's own name, never a claim the owner has not written (women only, certified…).
                'eyebrow' => $site->hero_badge ?: $tenant->name,
                'title' => $heroTitle ?? 'قوّتكِ',
                'title_2' => $heroTitle ? '' : 'تبدأ هنا.',
                'subtitle' => $heroSubtitle ?? '',
                'primary' => ['label' => $heroCta ?? 'ابدئي عضويتكِ', 'href' => '@join'],
                'image' => $legacyImage($site->hero_image_path),
                'facts' => $facts,
            ]),
        ];
        if (trim((string) $site->about_body) !== '') {
            $sections[] = $site->about_image_path
                ? $section('about', 'image_text', ['title' => (string) $site->about_title, 'body' => (string) $site->about_body, 'image' => $legacyImage($site->about_image_path)])
                : $section('about', 'intro', ['title' => (string) $site->about_title, 'body' => (string) $site->about_body]);
        }
        array_push($sections,
            $section('programs', 'programs', ['eyebrow' => 'البرامج', 'title' => 'برامج النادي']),
            $section('schedule', 'schedule', ['eyebrow' => 'جدول الحصص', 'title' => 'الحصص القادمة']),
            $section('trainers', 'trainers', ['eyebrow' => 'المدربات', 'title' => 'فريق التدريب']),
            $section('pricing', 'plans', ['eyebrow' => 'الباقات والأسعار', 'title' => 'اختاري باقتكِ']),
            $section('facilities', 'services', ['eyebrow' => 'المرافق', 'title' => 'مرافق النادي']),
            $section('stats', 'stats', ['items' => collect($site->stats ?? [])->take(6)->map(fn ($s) => ['id' => Str::lower(Str::random(8)), 'value' => mb_substr((string) ($s['value'] ?? ''), 0, 20), 'label' => mb_substr((string) ($s['label'] ?? ''), 0, 60)])->values()->all()]),
            $section('reviews', 'testimonials', ['eyebrow' => 'آراء العضوات', 'title' => '']),
            $section('news', 'posts', ['eyebrow' => 'العروض والأخبار', 'title' => 'جديد النادي']),
            $section('gallery', 'gallery', ['eyebrow' => 'معرض الصور', 'title' => 'صور النادي', 'items' => self::galleryItems($register)]),
            $section('shop', 'products', ['eyebrow' => 'المتجر', 'title' => 'منتجات النادي']),
            $section('register', 'join', [
                'eyebrow' => 'التسجيل', 'title' => 'ابدئي عضويتكِ',
                'subtitle' => 'سجّلي بياناتكِ واختاري باقتكِ، ثم أكملي اشتراككِ.',
                'steps' => collect(['بياناتكِ', 'الباقة', 'التأكيد'])->map(fn ($t) => ['id' => Str::lower(Str::random(8)), 'text' => $t])->all(),
            ]),
            $section('contact', 'contact', ['eyebrow' => 'تواصل معنا', 'title' => 'نسعد بزيارتكِ']),
        );

        return [
            'v' => 1,
            'theme' => ['accent' => '#8A6700', 'ink' => '#16130F', 'surface' => '#F6F3EE'],
            'announcement' => (string) $site->announcement,
            'header' => [
                'links' => [
                    $link('البرامج', '#programs'), $link('الجدول', '#schedule'), $link('المدربات', '#trainers'), $link('الباقات', '#pricing'),
                    $link('المرافق', '#facilities'), $link('آراء العضوات', '#reviews'), $link('تواصل معنا', '#contact'),
                ],
                'cta' => ['label' => 'انضمي الآن', 'href' => '@join'],
                'show_login' => true,
            ],
            'footer' => [
                'line' => '',
                'links' => [$link('الباقات', '@plans'), $link('جدول الحصص', '@schedule'), $link('العروض والأخبار', '@news'), $link('تواصل معنا', '@contact')],
                'show_hours' => true,
                'show_social' => true,
            ],
            'contact' => [
                'phone' => (string) ($site->contact_phone ?: $tenant->phone),
                'whatsapp' => (string) $site->whatsapp_number,
                'email' => (string) ($site->contact_email ?: $tenant->email),
                'address' => (string) ($site->address ?: $tenant->address),
                'map_url' => (string) $site->map_url,
                'hours' => collect($site->working_hours ?? [])->take(7)->map(fn ($h) => ['id' => Str::lower(Str::random(8)), 'days' => (string) ($h['days'] ?? ''), 'hours' => (string) ($h['hours'] ?? '')])->values()->all(),
                'social' => collect(['instagram', 'x', 'tiktok', 'snapchat', 'facebook', 'youtube'])->mapWithKeys(fn ($k) => [$k => (string) (($site->social_links ?? [])[$k] ?? '')])->all(),
            ],
            'seo' => ['title' => (string) ($seoTitle ?? $tenant->name), 'description' => (string) ($seoDescription ?? '')],
            'pages' => SitePages::defaults(),
            'sections' => $sections,
        ];
    }

    /** An image the club uploaded before this editor existed, as a media reference (or a path for visitors). @return array<string, mixed>|null */
    private static function legacyImage(?string $path, bool $register): ?array
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }
        if (! $register) {
            $known = SiteMedia::where('path', $path)->value('id');

            return $known ? ['id' => (int) $known, 'fit' => 'auto'] : ['legacy_path' => $path, 'fit' => 'auto'];
        }

        return ['id' => MediaLibrary::adopt($path)->id, 'fit' => 'auto'];
    }

    /** @return list<array<string, mixed>> */
    private static function galleryItems(bool $register): array
    {
        return SiteMedia::where('in_gallery', true)->orderBy('sort')->limit(30)->get()
            ->map(function (SiteMedia $m) use ($register) {
                if ($register && ! $m->width) {
                    MediaLibrary::measure($m);
                }

                return ['id' => Str::lower(Str::random(8)), 'image' => ['id' => $m->id, 'fit' => 'auto'], 'caption' => (string) $m->title];
            })->all();
    }
}
