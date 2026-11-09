<?php

namespace App\Modules\Website\Content;

/**
 * Where a button or menu item on the website may point, and the real address it opens.
 *
 * Stored links are tokens, never raw markup: a page of the site ('@plans'…), a section of the home page
 * ('#schedule'), or an outside address with an allowed scheme (https, http, tel, mailto). Anything else —
 * javascript:, data:, protocol-relative '//host', a relative path — is refused, so a link can never run code.
 * Pages are resolved per request with the site's own routes, so the same content works on the club's domain and
 * on /c/{club}.
 */
final class SiteLinks
{
    /** @var array<string, array{0: string, 1: string}> token => [route name, label] */
    public const PAGES = [
        '@home' => ['site.home', 'الصفحة الرئيسية'],
        '@plans' => ['site.plans', 'صفحة الباقات'],
        '@programs' => ['site.programs', 'صفحة البرامج'],
        '@schedule' => ['site.schedule', 'صفحة جدول الحصص'],
        '@trainers' => ['site.trainers', 'صفحة فريق التدريب'],
        '@news' => ['site.news', 'صفحة العروض والأخبار'],
        '@contact' => ['site.contact', 'صفحة تواصل معنا'],
        '@join' => ['site.join', 'صفحة التسجيل والاشتراك'],
        '@shop' => ['site.shop', 'المتجر'],
        '@portal' => ['portal.login', 'دخول العضوات (البوابة)'],
    ];

    public const ANCHOR = '/^#[a-z0-9-]{1,40}$/';

    /** Why $href is refused, or null when it is a link the site may show. '' (no link) is allowed. */
    public static function problem(string $href): ?string
    {
        if ($href === '' || isset(self::PAGES[$href]) || preg_match(self::ANCHOR, $href)) {
            return null;
        }
        if (mb_strlen($href) > 1000) {
            return 'الرابط طويل جدًا.';
        }
        if (preg_match('/^tel:\+?[0-9][0-9 ]{4,19}$/', $href) || preg_match('/^mailto:[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]+$/u', $href)) {
            return null;
        }
        if (preg_match('#^https?://#i', $href) && filter_var($href, FILTER_VALIDATE_URL) && ! preg_match('/[\s<>"\'`]/', $href)) {
            return null;
        }

        return 'الرابط غير مسموح. اختر صفحة من الموقع أو قسمًا، أو اكتب رابطًا يبدأ بـ https:// أو tel: أو mailto:.';
    }

    public static function external(string $href): bool
    {
        return (bool) preg_match('#^https?://#i', $href);
    }

    /**
     * The address a visitor's browser opens. Anchors point at the home page so a menu works from every page.
     * Returns null for '' or a token whose page is not available (e.g. the shop when it is closed).
     */
    public static function resolve(string $href, string $homeUrl, bool $shopOpen = true): ?string
    {
        if ($href === '') {
            return null;
        }
        if (isset(self::PAGES[$href])) {
            if ($href === '@shop' && ! $shopOpen) {
                return null;
            }

            return route(self::PAGES[$href][0]);
        }
        if ($href[0] === '#') {
            return rtrim($homeUrl, '/').'/'.$href;
        }

        return self::problem($href) === null ? $href : null;
    }

    /** A YouTube or Vimeo address as the privacy-friendly player address, or null when it is neither. */
    public static function videoEmbed(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('~^https?://(?:www\.|m\.)?youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)([A-Za-z0-9_-]{11})~', $url, $m)
            || preg_match('~^https?://youtu\.be/([A-Za-z0-9_-]{11})~', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1];
        }
        if (preg_match('~^https?://(?:www\.)?vimeo\.com/(?:video/)?(\d{5,12})~', $url, $m) || preg_match('~^https?://player\.vimeo\.com/video/(\d{5,12})~', $url, $m)) {
            return 'https://player.vimeo.com/video/'.$m[1];
        }

        return null;
    }

    /** @return list<array{value: string, label: string}> */
    public static function targets(bool $shopOpen): array
    {
        return collect(self::PAGES)
            ->reject(fn ($page, $token) => $token === '@shop' && ! $shopOpen)
            ->map(fn ($page, $token) => ['value' => $token, 'label' => $page[1]])->values()->all();
    }
}
