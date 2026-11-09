<?php

namespace App\Modules\Website\Content;

/**
 * The words at the top of each inner page of the site (its title and the line under it), which are also the page's
 * title and description in search results. They are part of the website document, so the owner edits them in
 * «إدارة الموقع» like everything else on the site; the defaults state what the page holds and promise nothing.
 */
final class SitePages
{
    /** @var array<string, array{label: string, title: string, subtitle: string, extra?: array<string, string>}> */
    public const PAGES = [
        'plans' => ['label' => 'صفحة الباقات', 'title' => 'الباقات والأسعار', 'subtitle' => 'باقات العضوية المتاحة في النادي وأسعارها.', 'extra' => ['pt_title' => 'التدريب الشخصي', 'pt_subtitle' => '']],
        'programs' => ['label' => 'صفحة البرامج', 'title' => 'البرامج التدريبية', 'subtitle' => 'برامج النادي المتاحة.'],
        'schedule' => ['label' => 'صفحة جدول الحصص', 'title' => 'جدول الحصص', 'subtitle' => 'حصص الأيام القادمة والمقاعد المتاحة. يحجز الأعضاء من بوابة الأعضاء.'],
        'trainers' => ['label' => 'صفحة فريق التدريب', 'title' => 'فريق التدريب', 'subtitle' => ''],
        'news' => ['label' => 'صفحة العروض والأخبار', 'title' => 'العروض والأخبار', 'subtitle' => 'آخر عروض النادي وأخباره.'],
        'contact' => ['label' => 'صفحة تواصل معنا', 'title' => 'تواصل معنا', 'subtitle' => 'بيانات التواصل مع النادي، ونموذج لإرسال رسالة.'],
        'join' => ['label' => 'صفحة التسجيل', 'title' => 'اشترك الآن', 'subtitle' => 'اختر الباقة وأكمل بياناتك.'],
        'shop' => ['label' => 'صفحة المتجر', 'title' => 'المتجر', 'subtitle' => 'منتجات النادي وأسعارها.'],
    ];

    /** Field labels in the editor. */
    private const LABELS = ['title' => 'عنوان الصفحة', 'subtitle' => 'السطر تحت العنوان', 'pt_title' => 'عنوان قسم التدريب الشخصي', 'pt_subtitle' => 'السطر تحت عنوان التدريب الشخصي'];

    /** The fields of the document's `pages` part, checked like the rest of the document. @return list<array<string, mixed>> */
    public static function fields(): array
    {
        return collect(self::PAGES)->map(fn ($page, $key) => [
            'key' => $key,
            'type' => 'group',
            'fields' => collect(self::keys($key))->map(fn ($field) => [
                'key' => $field,
                'type' => str_ends_with($field, 'subtitle') ? 'textarea' : 'text',
                'max' => str_ends_with($field, 'subtitle') ? 300 : 80,
            ])->all(),
        ])->values()->all();
    }

    /** @return array<string, array<string, string>> */
    public static function defaults(): array
    {
        return collect(self::PAGES)->map(fn ($page) => ['title' => $page['title'], 'subtitle' => $page['subtitle']] + ($page['extra'] ?? []))->all();
    }

    /**
     * Every page's words from a document. A document saved before pages could be edited has none and takes the
     * defaults; a page always has a title (an emptied title falls back to the default), its other lines may be empty.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, array<string, string>>
     */
    public static function of(array $doc): array
    {
        $saved = is_array($doc['pages'] ?? null) ? $doc['pages'] : [];

        return collect(self::defaults())->map(function (array $default, string $key) use ($saved) {
            $page = is_array($saved[$key] ?? null) ? $saved[$key] : $default;
            $out = [];
            foreach ($default as $field => $value) {
                $out[$field] = trim((string) ($page[$field] ?? $value));
            }
            if ($out['title'] === '') {
                $out['title'] = $default['title'];
            }

            return $out;
        })->all();
    }

    /** What «إدارة الموقع» shows: each page with its fields' labels. @return list<array<string, mixed>> */
    public static function forEditor(): array
    {
        return collect(self::PAGES)->map(fn ($page, $key) => [
            'key' => $key,
            'label' => $page['label'],
            'fields' => collect(self::keys($key))->map(fn ($field) => [
                'key' => $field,
                'type' => str_ends_with($field, 'subtitle') ? 'textarea' : 'text',
                'label' => self::LABELS[$field],
                'max' => str_ends_with($field, 'subtitle') ? 300 : 80,
            ])->all(),
        ])->values()->all();
    }

    /** @return list<string> */
    private static function keys(string $page): array
    {
        return ['title', 'subtitle', ...array_keys(self::PAGES[$page]['extra'] ?? [])];
    }
}
