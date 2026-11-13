<?php

namespace App\Core\Support\Images;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Accepts an image by what its bytes are: JPEG, PNG, GIF, WebP, AVIF, HEIC or HEIF within the size and pixel limits.
 * A display source made by the browser (the `_preview` beside an upload) must be a plain JPEG, PNG or WebP.
 */
final class ImageRule implements ValidationRule
{
    public function __construct(private readonly bool $preview = false) {}

    /** @return list<mixed> */
    public static function rules(bool $required = false): array
    {
        // This rule first: its message says why (too large for the server, not an image…), before the generic ones.
        return [$required ? 'required' : 'nullable', new self, 'file', 'max:'.config('gym.max_image_kb')];
    }

    /** @return list<mixed> */
    public static function previewRules(): array
    {
        return ['nullable', new self(preview: true), 'file', 'max:'.config('media.preview_max_kb')];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('تعذر استلام الصورة. حاول رفعها مرة أخرى.');

            return;
        }
        try {
            $facts = ImageInspector::inspect((string) $value->getRealPath(), $this->preview ? (int) config('media.preview_max_kb') * 1024 : null);
        } catch (ImageRejected $e) {
            $fail($e->getMessage());

            return;
        }
        if ($this->preview && (! in_array($facts->type, ['jpeg', 'png', 'webp'], true) || $facts->animated
            || max($facts->width, $facts->height) > (int) config('media.preview_max_side'))) {
            $fail('نسخة العرض التي جهزها المتصفح غير صالحة. حاول رفع الصورة مرة أخرى.');
        }
    }
}
