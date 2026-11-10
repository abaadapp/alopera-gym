<?php

namespace App\Core\Support;

use App\Core\Tenancy\TenantContext;

/**
 * The two languages of the club's website and member portal: Arabic, the default, at the plain addresses (/plans),
 * right to left; English under /en (/en/plans), left to right. The language is part of the address, so each page has
 * one address per language that search engines can list (hreflang) and a visitor can share.
 *
 * Only the website and the portal have an English side. The club panel, papers and messages stay as they are.
 */
final class SiteLanguage
{
    public const DEFAULT = 'ar';

    /** Every language, the default first. */
    public const ALL = ['ar', 'en'];

    public static function current(): string
    {
        return app()->getLocale() === 'en' ? 'en' : 'ar';
    }

    public static function isEnglish(): bool
    {
        return self::current() === 'en';
    }

    public static function dir(string $locale): string
    {
        return $locale === 'ar' ? 'rtl' : 'ltr';
    }

    /**
     * The language a site path is in, and the path without it: /c/club/en/plans → ['en', '/c/club/plans']. Anything
     * else is Arabic and stays as it is.
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $path): array
    {
        if (preg_match('#^/c/([a-z0-9-]+)/en(/.*)?$#', $path, $m)) {
            return ['en', '/c/'.$m[1].(($m[2] ?? '') === '/' ? '' : ($m[2] ?? ''))];
        }

        return [self::DEFAULT, $path];
    }

    /**
     * A site path in a language: /c/club/plans → /c/club/en/plans; on the club's own domain /plans → /en/plans and
     * / → /en. Arabic paths have no prefix.
     */
    public static function path(string $path, string $locale): string
    {
        if ($locale === self::DEFAULT) {
            return $path;
        }
        if (preg_match('#^(/c/[a-z0-9-]+)(/.*)?$#', $path, $m)) {
            $rest = $m[2] ?? '';

            return $m[1].'/'.$locale.($rest === '/' ? '' : $rest);
        }

        return '/'.$locale.($path === '/' || $path === '' ? '' : $path);
    }

    /**
     * The address of the page being served in a language: on the club's domain its clean path there, otherwise the
     * address it was asked at.
     */
    public static function address(string $locale, bool $withQuery = false): string
    {
        $tenant = app(TenantContext::class)->get();
        $path = '/'.ltrim((string) parse_url(url()->current(), PHP_URL_PATH), '/');
        $query = $withQuery && request()->getQueryString() ? '?'.request()->getQueryString() : '';

        if ($tenant?->domain) {
            $clean = preg_replace('#^/c/'.preg_quote($tenant->slug, '#').'#', '', $path) ?: '/';

            return 'https://'.$tenant->domain.self::path($clean === '' ? '/' : $clean, $locale).$query;
        }

        return url(self::path($path, $locale)).$query;
    }

    /** The page in each language, for the language switch and the hreflang links. @return array<string, string> */
    public static function alternates(bool $withQuery = false): array
    {
        return collect(self::ALL)->mapWithKeys(fn (string $l) => [$l => self::address($l, $withQuery)])->all();
    }

    /** Runs $make with the page in another language (an address for the sitemap), then puts the language back. */
    public static function in(string $locale, callable $make): mixed
    {
        $was = app()->getLocale();
        app()->setLocale($locale);
        try {
            return $make();
        } finally {
            app()->setLocale($was);
        }
    }

    /** The English words when the page is in English and the club wrote them, else the Arabic. */
    public static function pick(?string $arabic, ?string $english): ?string
    {
        return self::isEnglish() && $english !== null && trim($english) !== '' ? $english : $arabic;
    }

    /** The same for a list of lines (a plan's features, a trainer's specialties). @param array<int, string>|null $arabic @param array<int, string>|null $english @return array<int, string>|null */
    public static function pickList(?array $arabic, ?array $english): ?array
    {
        $english = array_values(array_filter($english ?? [], fn ($l) => is_string($l) && trim($l) !== ''));

        return self::isEnglish() && $english !== [] ? $english : $arabic;
    }

    /** A fixed Arabic phrase of the site (a default heading, a label) in the page's language: lang/en.json. */
    public static function word(string $arabic): string
    {
        return self::isEnglish() && $arabic !== '' ? (string) __($arabic) : $arabic;
    }
}
