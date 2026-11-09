<?php

namespace App\Modules\Website\Content;

use App\Modules\Website\Models\SiteMedia;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Checks and cleans a website document coming from «إدارة الموقع» before it is stored.
 *
 * Only the keys the schema knows survive; every value is checked against its field: plain text within its
 * length (control characters removed, never HTML — the pages print it as text), links through SiteLinks, images
 * must be in this club's media library, colours are #rrggbb, lists respect their maximum. Problems are reported
 * per field path (doc.sections.2.data.title) so the editor can point at them.
 */
final class SiteDocument
{
    public const MAX_SECTIONS = 60;

    public const MAX_BYTES = 400_000;

    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<int, list<string>> media id => paths that use it */
    private array $images = [];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function clean(array $input): array
    {
        return (new self)->run($input);
    }

    /** The fields of the parts around the sections (menu, footer, contact, theme, search engines). @return array<string, list<array<string, mixed>>> */
    public static function chromeFields(): array
    {
        $link = [['key' => 'label', 'type' => 'text', 'max' => 30], ['key' => 'href', 'type' => 'href']];

        return [
            'theme' => [['key' => 'accent', 'type' => 'color'], ['key' => 'ink', 'type' => 'color'], ['key' => 'surface', 'type' => 'color']],
            'header' => [['key' => 'links', 'type' => 'list', 'max' => 9, 'fields' => $link], ['key' => 'cta', 'type' => 'link'], ['key' => 'show_login', 'type' => 'toggle']],
            'footer' => [['key' => 'line', 'type' => 'textarea', 'max' => 300], ['key' => 'links', 'type' => 'list', 'max' => 12, 'fields' => $link], ['key' => 'show_hours', 'type' => 'toggle'], ['key' => 'show_social', 'type' => 'toggle']],
            'contact' => [
                ['key' => 'phone', 'type' => 'phone'], ['key' => 'whatsapp', 'type' => 'phone'], ['key' => 'email', 'type' => 'email'],
                ['key' => 'address', 'type' => 'text', 'max' => 255], ['key' => 'map_url', 'type' => 'url'],
                ['key' => 'hours', 'type' => 'list', 'max' => 7, 'fields' => [['key' => 'days', 'type' => 'text', 'max' => 60], ['key' => 'hours', 'type' => 'text', 'max' => 60]]],
                ['key' => 'social', 'type' => 'group', 'fields' => collect(['instagram', 'x', 'tiktok', 'snapchat', 'facebook', 'youtube'])->map(fn ($k) => ['key' => $k, 'type' => 'url'])->all()],
            ],
            'seo' => [['key' => 'title', 'type' => 'text', 'max' => 70], ['key' => 'description', 'type' => 'textarea', 'max' => 300]],
            'pages' => SitePages::fields(),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function run(array $input): array
    {
        if (strlen((string) json_encode($input)) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['doc' => 'محتوى الموقع كبير جدًا. قلّل عدد الأقسام أو النصوص الطويلة.']);
        }

        $doc = ['v' => 1];
        foreach (self::chromeFields() as $part => $fields) {
            $doc[$part] = $this->group($fields, is_array($input[$part] ?? null) ? $input[$part] : [], "doc.$part");
        }
        $doc['announcement'] = $this->text($input['announcement'] ?? '', 200, 'doc.announcement', false);
        $doc['sections'] = $this->sections($input['sections'] ?? null);

        $this->checkImages();
        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }

        return $doc;
    }

    /** @return list<array<string, mixed>> */
    private function sections(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $this->errors['doc.sections'] = 'قائمة الأقسام غير صحيحة.';

            return [];
        }
        if (count($raw) > self::MAX_SECTIONS) {
            $this->errors['doc.sections'] = 'الحد الأقصى '.self::MAX_SECTIONS.' قسمًا.';

            return [];
        }

        $out = [];
        $seen = [];
        foreach ($raw as $i => $section) {
            $path = "doc.sections.$i";
            $type = is_array($section) ? (string) ($section['type'] ?? '') : '';
            $schema = SectionTypes::get($type);
            if (! $schema) {
                $this->errors["$path.type"] = 'نوع قسم غير معروف.';

                continue;
            }
            $id = (string) ($section['id'] ?? '');
            if (! preg_match('/^[a-z0-9-]{1,40}$/', $id) || isset($seen[$id])) {
                $this->errors["$path.id"] = 'معرّف القسم غير صحيح أو مكرر.';

                continue;
            }
            $seen[$id] = true;
            $tone = (string) ($section['tone'] ?? 'auto');
            $data = is_array($section['data'] ?? null) ? $section['data'] : [];
            $out[] = [
                'id' => $id,
                'type' => $type,
                'visible' => filter_var($section['visible'] ?? true, FILTER_VALIDATE_BOOL),
                'tone' => in_array($tone, ['auto', 'white', 'beige', 'dark'], true) ? $tone : 'auto',
                'data' => $this->group($schema['fields'], $data + $schema['defaults'], "$path.data"),
            ];
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $fields @param array<string, mixed> $raw @return array<string, mixed> */
    private function group(array $fields, array $raw, string $path): array
    {
        $out = [];
        foreach ($fields as $field) {
            $out[$field['key']] = $this->value($field, $raw[$field['key']] ?? null, $path.'.'.$field['key']);
        }

        return $out;
    }

    /** @param array<string, mixed> $field */
    private function value(array $field, mixed $raw, string $path): mixed
    {
        switch ($field['type']) {
            case 'text':
                return $this->text($raw, $field['max'] ?? 200, $path, false);
            case 'textarea':
                return $this->text($raw, $field['max'] ?? 2000, $path, true);
            case 'toggle':
                return filter_var($raw ?? false, FILTER_VALIDATE_BOOL);
            case 'number':
                $n = is_numeric($raw) ? (int) $raw : ($field['min'] ?? 0);
                if ($n < ($field['min'] ?? PHP_INT_MIN) || $n > ($field['max'] ?? PHP_INT_MAX)) {
                    $this->errors[$path] = 'الرقم يجب أن يكون بين '.$field['min'].' و'.$field['max'].'.';
                }

                return $n;
            case 'select':
                $options = array_column($field['options'], 'value');
                if (! in_array($raw, $options, true)) {
                    if ($raw !== null && $raw !== '') {
                        $this->errors[$path] = 'اختيار غير صحيح.';
                    }

                    return $options[0];
                }

                return $raw;
            case 'color':
                $raw = is_string($raw) ? trim($raw) : '';
                if (! preg_match('/^#[0-9a-fA-F]{6}$/', $raw)) {
                    $this->errors[$path] = 'اللون يجب أن يكون بصيغة ‎#RRGGBB.';

                    return '#000000';
                }

                return strtoupper($raw);
            case 'href':
                return $this->href($raw, $path);
            case 'link':
                $raw = is_array($raw) ? $raw : [];

                return ['label' => $this->text($raw['label'] ?? '', 40, "$path.label", false), 'href' => $this->href($raw['href'] ?? '', "$path.href")];
            case 'video':
                $url = $this->text($raw, 300, $path, false);
                if ($url !== '' && SiteLinks::videoEmbed($url) === null) {
                    $this->errors[$path] = 'رابط الفيديو يجب أن يكون من YouTube أو Vimeo.';
                }

                return $url;
            case 'url':
                $url = $this->text($raw, 1000, $path, false);
                if ($url !== '' && (! preg_match('#^https?://#i', $url) || SiteLinks::problem($url) !== null)) {
                    $this->errors[$path] = 'الرابط يجب أن يبدأ بـ https://';
                }

                return $url;
            case 'email':
                $email = $this->text($raw, 255, $path, false);
                if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->errors[$path] = 'البريد الإلكتروني غير صحيح.';
                }

                return $email;
            case 'phone':
                $phone = $this->text($raw, 32, $path, false);
                $digits = preg_replace('/\D+/', '', $phone);
                if ($phone !== '' && (! preg_match('/^\+?[0-9 ()-]{6,24}$/', $phone) || strlen($digits) < 6 || strlen($digits) > 15)) {
                    $this->errors[$path] = 'رقم الهاتف غير صحيح. اكتبه بالأرقام مع رمز الدولة.';
                }

                return $digits;
            case 'image':
                if (! is_array($raw) || empty($raw['id'])) {
                    return null;
                }
                $id = filter_var($raw['id'], FILTER_VALIDATE_INT);
                if ($id === false || $id < 1) {
                    $this->errors[$path] = 'صورة غير صحيحة.';

                    return null;
                }
                $this->images[$id][] = $path;
                $fit = (string) ($raw['fit'] ?? 'auto');

                return ['id' => $id, 'fit' => in_array($fit, ['auto', 'contain', 'cover'], true) ? $fit : 'auto'];
            case 'group':
                return $this->group($field['fields'], is_array($raw) ? $raw : [], $path);
            case 'list':
                if ($raw === null || $raw === '') {
                    return [];
                }
                if (! is_array($raw) || ! array_is_list($raw)) {
                    $this->errors[$path] = 'قائمة غير صحيحة.';

                    return [];
                }
                if (count($raw) > $field['max']) {
                    $this->errors[$path] = 'الحد الأقصى '.$field['max'].'.';
                    $raw = array_slice($raw, 0, $field['max']);
                }
                $items = [];
                foreach ($raw as $i => $item) {
                    $item = is_array($item) ? $item : [];
                    $id = (string) ($item['id'] ?? '');
                    $items[] = ['id' => preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) ? $id : Str::lower(Str::random(8))]
                        + $this->group($field['fields'], $item, "$path.$i");
                }

                return $items;
        }

        return null;
    }

    private function text(mixed $raw, int $max, string $path, bool $multiline): string
    {
        if ($raw !== null && ! is_scalar($raw)) {
            $this->errors[$path] = 'قيمة غير صحيحة.';

            return '';
        }
        $text = str_replace("\r\n", "\n", (string) $raw);
        // Control characters out (keep new lines in paragraphs); the pages print text, never markup.
        $text = (string) preg_replace($multiline ? '/[^\P{C}\n]/u' : '/\p{C}/u', '', $text);
        $text = trim($multiline ? (string) preg_replace("/\n{3,}/", "\n\n", $text) : (string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) > $max) {
            $this->errors[$path] = "النص أطول من $max حرفًا.";

            return mb_substr($text, 0, $max);
        }

        return $text;
    }

    private function href(mixed $raw, string $path): string
    {
        $href = is_string($raw) ? trim($raw) : '';
        if (($problem = SiteLinks::problem($href)) !== null) {
            $this->errors[$path] = $problem;

            return '';
        }

        return $href;
    }

    private function checkImages(): void
    {
        if ($this->images === []) {
            return;
        }
        // Scoped to the current club: another club's image id is "not found" here.
        $known = SiteMedia::whereIn('id', array_keys($this->images))->pluck('id')->all();
        foreach (array_diff(array_keys($this->images), $known) as $missing) {
            foreach ($this->images[$missing] as $path) {
                $this->errors[$path] = 'الصورة غير موجودة في مكتبة صور النادي.';
            }
        }
    }

    /** Media ids a stored document uses, with where (for the delete guard). @param array<string, mixed>|null $doc @return array<int, list<string>> */
    public static function imageUses(?array $doc): array
    {
        $uses = [];
        foreach ($doc['sections'] ?? [] as $section) {
            $label = SectionTypes::get($section['type'] ?? '')['label'] ?? 'قسم';
            self::collectImages($section['data'] ?? [], $label, $uses);
        }

        return $uses;
    }

    /** @param array<string, mixed> $data @param array<int, list<string>> $uses */
    private static function collectImages(array $data, string $label, array &$uses): void
    {
        foreach ($data as $value) {
            if (is_array($value) && isset($value['id'], $value['fit']) && is_int($value['id'])) {
                $uses[$value['id']][] = $label;
            } elseif (is_array($value)) {
                self::collectImages($value, $label, $uses);
            }
        }
    }
}
