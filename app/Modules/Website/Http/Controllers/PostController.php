<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Support\Images\ImageRule;
use App\Core\Support\Images\ImageUpload;
use App\Core\Support\ImageStore;
use App\Http\Controllers\Controller;
use App\Modules\Website\Models\SitePost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** News and offers shown on the website. */
class PostController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Website/Posts', [
            'posts' => SitePost::latest()->get()->map(fn (SitePost $p) => $p->only('id', 'type', 'title', 'title_en', 'slug', 'excerpt', 'excerpt_en', 'body', 'body_en', 'is_published') + [
                'image_url' => ImageStore::thumbUrl($p->image_path),
                'image_download_url' => $p->image_path ? route('club.website.posts.image', $p) : null,
                'starts_on' => $p->starts_on?->toDateString(), 'ends_on' => $p->ends_on?->toDateString(),
                'published_at' => $p->published_at?->format('Y-m-d'),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $post = new SitePost(collect($data)->except(['image', 'image_preview', 'image_direct'])->all());
        $post->slug = $this->slug($data['title']);
        $post->created_by = $request->user()->id;
        $post->published_at = $post->is_published ? now() : null;
        $image = ImageUpload::from($request, 'image');
        if (! $image) {
            $post->save();
        } else {
            ImageStore::commit(null, ImageStore::store($image, 'posts'), function (string $path) use ($post) {
                $post->image_path = $path;
                $post->save();
            });
        }

        return back()->with('success', 'تم الحفظ.');
    }

    /** The image as it was uploaded, for download. */
    public function image(SitePost $post): \Symfony\Component\HttpFoundation\Response
    {
        return ImageStore::download($post->image_path, $post->title);
    }

    public function update(Request $request, SitePost $post): RedirectResponse
    {
        $data = $this->validated($request);
        $wasPublished = $post->is_published;
        $post->fill(collect($data)->except(['image', 'image_preview', 'image_direct'])->all());
        if ($post->is_published && ! $wasPublished) {
            $post->published_at = now();
        }
        // The new image is stored and saved first; the old files go only after that worked.
        $image = ImageUpload::from($request, 'image');
        if (! $image) {
            $post->save();
        } else {
            ImageStore::replace($post->image_path, $image, 'posts', 1600, function (string $path) use ($post) {
                $post->image_path = $path;
                $post->save();
            });
        }

        return back()->with('success', 'تم الحفظ.');
    }

    public function destroy(SitePost $post): RedirectResponse
    {
        $post->delete();

        return back()->with('success', 'تم الحذف.');
    }

    private function slug(string $title): string
    {
        $base = Str::slug($title) ?: 'post-'.now()->format('Ymd');
        $slug = $base;
        $i = 2;
        while (SitePost::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'in:news,offer'],
            'title' => ['required', 'string', 'max:160'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:20000'],
            // The English website's words (#68); without them the English site shows the Arabic.
            'title_en' => ['nullable', 'string', 'max:160'],
            'excerpt_en' => ['nullable', 'string', 'max:500'],
            'body_en' => ['nullable', 'string', 'max:20000'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_published' => ['boolean'],
            'image' => ImageRule::rules(),
            'image_preview' => ImageRule::previewRules(),
            'image_direct' => ImageRule::ticketRules(),
        ]);
    }
}
