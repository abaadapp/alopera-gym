<?php

namespace App\Modules\Website\Content;

use App\Core\Support\SiteLanguage;

/**
 * The website document in the language of the page: on the English site every text field takes the English words
 * the owner wrote for it (the document's `en` part, kept by SiteDocument). A field the owner has not written in
 * English yet shows the site's own English for a standard phrase (a default heading, a button: lang/en.json), else
 * its Arabic, so no part of the page is left empty. A field empty in Arabic is empty in English too. The Arabic site
 * is the document as it is.
 */
final class SiteTranslation
{
    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public static function apply(array $doc, string $locale): array
    {
        if ($locale === SiteLanguage::DEFAULT) {
            return $doc;
        }
        $words = is_array($doc['en'] ?? null) ? $doc['en'] : [];
        $doc['pages'] = SitePages::of($doc);
        foreach (SiteDocument::chromeFields() as $part => $fields) {
            if (is_array($doc[$part] ?? null)) {
                $doc[$part] = self::merge($fields, $doc[$part], $words[$part] ?? [], $locale);
            }
        }
        $doc['announcement'] = self::pick((string) ($doc['announcement'] ?? ''), $words['announcement'] ?? null, $locale);
        foreach ($doc['sections'] ?? [] as $i => $section) {
            $schema = SectionTypes::get((string) ($section['type'] ?? ''));
            if ($schema && is_array($section['data'] ?? null)) {
                $doc['sections'][$i]['data'] = self::merge($schema['fields'], $section['data'], $words['sections'][$section['id'] ?? ''] ?? [], $locale);
            }
        }

        return $doc;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private static function merge(array $fields, array $base, mixed $words, string $locale): array
    {
        $words = is_array($words) ? $words : [];
        foreach ($fields as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $base)) {
                continue;
            }
            $value = $base[$key];
            $mine = $words[$key] ?? null;
            $base[$key] = match ($field['type']) {
                'text', 'textarea' => self::pick((string) $value, $mine, $locale),
                'link' => is_array($value) ? ['label' => self::pick((string) ($value['label'] ?? ''), is_array($mine) ? ($mine['label'] ?? null) : null, $locale)] + $value : $value,
                'group' => is_array($value) ? self::merge($field['fields'], $value, $mine, $locale) : $value,
                'list' => is_array($value) ? array_map(fn ($item) => is_array($item) ? self::merge($field['fields'], $item, is_array($mine) ? ($mine[$item['id'] ?? ''] ?? []) : [], $locale) : $item, $value) : $value,
                default => $value,
            };
        }

        return $base;
    }

    /** The Arabic decides whether a text is there at all (an emptied button label hides the button in both languages). */
    private static function pick(string $arabic, mixed $mine, string $locale): string
    {
        if ($arabic === '') {
            return '';
        }
        if (is_string($mine) && trim($mine) !== '') {
            return $mine;
        }

        return (string) __($arabic, [], $locale);
    }
}
