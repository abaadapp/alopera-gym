<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Support\ImageStore;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Website\Models\ContactMessage;
use App\Modules\Website\Models\SiteMedia;
use App\Modules\Website\Models\SiteSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Website content: texts, images, contact details, SEO, gallery, contact-form inbox. */
class CmsController extends Controller
{
    private const IMAGES = ['hero_image', 'about_image', 'og_image'];

    public function edit(TenantContext $tenants): Response
    {
        $site = SiteSetting::firstOrCreate([]);

        return Inertia::render('Website/Edit', [
            'settings' => $site->only('is_published', 'hero_title', 'hero_subtitle', 'hero_cta_label', 'about_title', 'about_body', 'announcement',
                'contact_phone', 'whatsapp_number', 'contact_email', 'address', 'map_url', 'working_hours', 'social_links', 'stats', 'seo_title', 'seo_description') + [
                    'hero_image_url' => SiteSetting::url($site->hero_image_path),
                    'about_image_url' => SiteSetting::url($site->about_image_path),
                    'og_image_url' => SiteSetting::url($site->og_image_path),
                ],
            'media' => SiteMedia::orderBy('sort')->get()->map(fn ($m) => $m->only('id', 'title', 'in_gallery', 'sort') + ['url' => SiteSetting::url($m->path)]),
            'siteUrl' => route('site.home', ['club' => $tenants->require()->slug]),
            'unread' => ContactMessage::where('status', 'new')->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'is_published' => ['boolean'],
            'hero_title' => ['nullable', 'string', 'max:160'],
            'hero_subtitle' => ['nullable', 'string', 'max:500'],
            'hero_cta_label' => ['nullable', 'string', 'max:40'],
            'about_title' => ['nullable', 'string', 'max:160'],
            'about_body' => ['nullable', 'string', 'max:5000'],
            'announcement' => ['nullable', 'string', 'max:200'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'whatsapp_number' => ['nullable', 'string', 'max:32'],
            'contact_email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:255'],
            'map_url' => ['nullable', 'url:http,https', 'max:1000'],
            'working_hours' => ['nullable', 'array', 'max:7'],
            'working_hours.*.days' => ['required', 'string', 'max:60'],
            'working_hours.*.hours' => ['required', 'string', 'max:60'],
            'social_links' => ['nullable', 'array'],
            'social_links.*' => ['nullable', 'url:http,https', 'max:255'],
            'stats' => ['nullable', 'array', 'max:4'],
            'stats.*.label' => ['required', 'string', 'max:40'],
            'stats.*.value' => ['required', 'string', 'max:20'],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:300'],
            'hero_image' => ['nullable', 'image', 'max:'.config('gym.max_image_kb')],
            'about_image' => ['nullable', 'image', 'max:'.config('gym.max_image_kb')],
            'og_image' => ['nullable', 'image', 'max:'.config('gym.max_image_kb')],
        ]);

        // Multipart requests (with an image) drop empty arrays: missing means "cleared".
        foreach (['working_hours', 'stats', 'social_links'] as $list) {
            $data[$list] ??= [];
        }

        $site = SiteSetting::firstOrCreate([]);
        foreach (self::IMAGES as $field) {
            if ($request->hasFile($field)) {
                ImageStore::delete($site->{$field.'_path'});
                $site->{$field.'_path'} = ImageStore::store($request->file($field), 'site', $field === 'og_image' ? 1200 : 1920);
            }
            unset($data[$field]);
        }
        $site->fill($data)->save();

        return back()->with('success', 'تم حفظ محتوى الموقع.');
    }

    public function uploadMedia(Request $request): RedirectResponse
    {
        $request->validate([
            'images' => ['required', 'array', 'max:12'],
            'images.*' => ['image', 'max:'.config('gym.max_image_kb')],
            'title' => ['nullable', 'string', 'max:120'],
        ]);
        $sort = (int) SiteMedia::max('sort');
        foreach ($request->file('images') as $file) {
            SiteMedia::create([
                'title' => $request->input('title'),
                'path' => ImageStore::store($file, 'gallery'),
                'mime' => 'image/webp',
                'size' => $file->getSize(),
                'sort' => ++$sort,
            ]);
        }

        return back()->with('success', 'تم رفع الصور.');
    }

    public function deleteMedia(SiteMedia $media): RedirectResponse
    {
        ImageStore::delete($media->path);
        $media->delete();

        return back()->with('success', 'تم حذف الصورة.');
    }

    public function messages(): Response
    {
        return Inertia::render('Website/Messages', [
            'messages' => ContactMessage::latest()->paginate(30)->through(fn ($m) => $m->only('id', 'name', 'phone', 'email', 'subject', 'message', 'status') + ['at' => $m->created_at->format('Y-m-d H:i')]),
        ]);
    }

    public function markRead(ContactMessage $message): RedirectResponse
    {
        $message->update(['status' => 'read']);

        return back();
    }
}
