<?php

namespace App\Modules\Website\Content;

use App\Core\Support\Images\ImageInspector;
use App\Core\Support\Images\ImageRejected;
use App\Core\Support\Images\ImageRule;
use App\Core\Support\Images\ImageUpload;
use App\Core\Support\ImageStore;
use App\Modules\Website\Models\SiteMedia;
use App\Modules\Website\Models\SiteSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The club's website images: stored once on the persistent public disk (storage/app/public/tenants/{id}/site —
 * outside public/build and outside git, so a code deploy or a rebuild of the pages never touches them), measured
 * on upload, and referenced by id from the website content. An image in use cannot be deleted.
 */
final class MediaLibrary
{
    /** Formats the file picker offers; the file's own bytes decide (ImageRule), not its name. */
    public const TYPES = ImageInspector::EXTENSIONS;

    /** @return list<mixed> */
    public static function rules(): array
    {
        return array_values(array_filter(ImageRule::rules(required: true), fn ($r) => $r !== 'required'));
    }

    public static function upload(UploadedFile|ImageUpload $file, ?string $title = null, bool $inGallery = false): SiteMedia
    {
        $upload = ImageUpload::wrap($file);
        try {
            $stored = ImageStore::storeMeasured($upload, 'site');
        } catch (ImageRejected $e) {
            throw ValidationException::withMessages(['images' => '«'.$upload->name().'»: '.$e->getMessage()]);
        }

        try {
            return SiteMedia::create([
                'title' => $title,
                'path' => $stored['path'],
                'mime' => $stored['mime'],
                'size' => $stored['size'],
                'width' => $stored['width'],
                'height' => $stored['height'],
                'orientation' => self::orientation($stored['width'], $stored['height']),
                'in_gallery' => $inGallery,
                'sort' => (int) SiteMedia::max('sort') + 1,
            ]);
        } catch (\Throwable $e) {
            ImageStore::delete($stored['path']); // no orphan file when the row could not be written

            throw $e;
        }
    }

    /** landscape (wider by more than 15%), portrait (taller by more than 15%) or square. Shape only, never content. */
    public static function orientation(?int $width, ?int $height): ?string
    {
        if (! $width || ! $height) {
            return null;
        }
        $ratio = $width / $height;

        return $ratio > 1.15 ? 'landscape' : ($ratio < 0.87 ? 'portrait' : 'square');
    }

    /** An image already on the disk (uploaded before the media library knew its size), as a library item. */
    public static function adopt(string $path): SiteMedia
    {
        $media = SiteMedia::firstOrCreate(['path' => $path], ['in_gallery' => false, 'mime' => 'image/webp', 'size' => (int) Storage::disk('public')->size($path)]);

        return $media->width ? $media : self::measure($media);
    }

    public static function measure(SiteMedia $media): SiteMedia
    {
        $disk = Storage::disk('public');
        if ($disk->exists($media->path) && ($info = @getimagesize($disk->path($media->path)))) {
            $media->forceFill(['width' => $info[0], 'height' => $info[1], 'orientation' => self::orientation($info[0], $info[1])])->save();
        }

        return $media;
    }

    /**
     * Where each image is used: the draft, the live site and the older image fields.
     *
     * @return array<int, list<string>>
     */
    public static function uses(SiteSetting $site): array
    {
        $uses = [];
        foreach (['المسودة' => $site->draft, 'المنشور' => $site->published] as $where => $doc) {
            foreach (SiteDocument::imageUses($doc) as $id => $labels) {
                foreach (array_unique($labels) as $label) {
                    $uses[$id][] = "$where: $label";
                }
            }
        }
        $legacy = array_filter(['صورة المشاركة في وسائل التواصل' => $site->og_image_path]);
        if ($legacy) {
            foreach (SiteMedia::whereIn('path', array_values($legacy))->get(['id', 'path']) as $m) {
                $uses[$m->id][] = array_search($m->path, $legacy, true);
            }
        }

        return array_map(fn ($l) => array_values(array_unique($l)), $uses);
    }

    /** @return array<string, mixed> */
    public static function present(SiteMedia $m, array $uses = []): array
    {
        return [
            'id' => $m->id,
            'url' => ImageStore::url($m->path),
            'thumb_url' => ImageStore::thumbUrl($m->path),
            'has_original' => ImageStore::hasOriginal($m->path),
            'download_url' => route('club.website.media.original', $m->id),
            'title' => $m->title,
            'alt' => $m->alt,
            'width' => $m->width,
            'height' => $m->height,
            'orientation' => $m->orientation,
            'size' => (int) $m->size,
            'mime' => $m->mime,
            'used_in' => $uses[$m->id] ?? [],
        ];
    }

    /** Deletes an unused image and its file; an image the website uses is refused with where it is used. */
    public static function delete(SiteMedia $media, SiteSetting $site): void
    {
        $used = self::uses($site)[$media->id] ?? [];
        if ($used !== []) {
            throw ValidationException::withMessages(['media' => 'هذه الصورة مستخدمة في الموقع ('.implode('، ', $used).'). أزلها من هذه الأماكن أولًا ثم احذفها.']);
        }
        $path = $media->path;
        $media->delete();
        ImageStore::delete($path);
    }
}
