<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Support\ImageStore;
use App\Http\Controllers\Controller;
use App\Modules\Website\Models\SitePost;
use App\Modules\Website\Models\SiteSetting;
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
            'posts' => SitePost::latest()->get()->map(fn (SitePost $p) => $p->only('id', 'type', 'title', 'slug', 'excerpt', 'body', 'is_published') + [
                'image_url' => SiteSetting::url($p->image_path),
                'starts_on' => $p->starts_on?->toDateString(), 'ends_on' => $p->ends_on?->toDateString(),
                'published_at' => $p->published_at?->format('Y-m-d'),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $post = new SitePost(collect($data)->except('image')->all());
        $post->slug = $this->slug($data['title']);
        $post->created_by = $request->user()->id;
        $post->published_at = $post->is_published ? now() : null;
        if ($request->hasFile('image')) {
            $post->image_path = ImageStore::store($request->file('image'), 'posts');
        }
        $post->save();

        return back()->with('success', 'تم الحفظ.');
    }

    public function update(Request $request, SitePost $post): RedirectResponse
    {
        $data = $this->validated($request);
        $wasPublished = $post->is_published;
        $post->fill(collect($data)->except('image')->all());
        if ($post->is_published && ! $wasPublished) {
            $post->published_at = now();
        }
        if ($request->hasFile('image')) {
            ImageStore::delete($post->image_path);
            $post->image_path = ImageStore::store($request->file('image'), 'posts');
        }
        $post->save();

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
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_published' => ['boolean'],
            'image' => ['nullable', 'image', 'max:'.config('gym.max_image_kb')],
        ]);
    }
}
