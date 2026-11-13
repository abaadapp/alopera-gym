<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Images\DirectUploads;
use App\Core\Support\Images\ImageRule;
use App\Core\Support\Images\ImageUpload;
use App\Core\Support\ImageStore;
use App\Core\Support\SiteLanguage;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\ShopSettings;
use App\Modules\Website\Content\MediaLibrary;
use App\Modules\Website\Content\SectionTypes;
use App\Modules\Website\Content\SiteContent;
use App\Modules\Website\Content\SiteDataHints;
use App\Modules\Website\Content\SiteLinks;
use App\Modules\Website\Content\SitePages;
use App\Modules\Website\Content\SiteRenderer;
use App\Modules\Website\Http\RendersSite;
use App\Modules\Website\Models\ContactMessage;
use App\Modules\Website\Models\SiteMedia;
use App\Modules\Website\Models\SiteRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «إدارة الموقع»: the owner (or staff given website.manage) edits the official website as a draft, previews it,
 * publishes it with one click and can go back to an earlier published version. The routes sit in the club panel
 * behind can:website.manage, and every model here is scoped to the signed-in club.
 */
class SiteBuilderController extends Controller
{
    use RendersSite;

    public function __construct(private SiteContent $content, private TenantContext $tenants) {}

    public function show(ShopSettings $shop, SiteRenderer $renderer, SiteDataHints $hints): Response
    {
        $tenant = $this->tenants->require();
        URL::defaults(['club' => $tenant->slug]);
        $site = $this->content->site();
        $doc = $this->content->editable($site);
        $uses = MediaLibrary::uses($site);

        return Inertia::render('Website/Builder', [
            'doc' => $doc,
            'version' => (int) $site->draft_version,
            'status' => $this->content->status($site),
            'schema' => SectionTypes::forEditor(),
            'pageFields' => SitePages::forEditor(),
            // Sections of the saved draft that wait for data in the system and are not on the site now, each saying
            // whether nothing exists yet or rows exist behind a switch (and which one).
            'waiting' => $hints->explain($renderer->waiting($doc), $doc),
            'media' => SiteMedia::latest('id')->get()->map(fn ($m) => MediaLibrary::present($m, $uses))->all(),
            'revisions' => SiteRevision::with('publisher:id,name')->latest('number')->limit(20)->get()->map(fn (SiteRevision $r) => [
                'id' => $r->id, 'number' => $r->number, 'note' => $r->note, 'published_at' => $r->published_at->format('Y-m-d H:i'), 'published_by' => $r->publisher?->name,
            ])->all(),
            'targets' => SiteLinks::targets($shop->shopOpen($tenant)),
            'siteUrl' => route('site.home', ['club' => $tenant->slug]),
            'siteUrlEn' => SiteLanguage::in('en', fn () => route('site.home', ['club' => $tenant->slug])),
            // The site's own English for its standard phrases, shown where the owner has not written English yet.
            'englishDefaults' => (object) app('translator')->getLoader()->load('en', '*', '*'),
            'previewUrl' => route('club.website.preview'),
            'limits' => ['max_image_kb' => (int) config('gym.max_image_kb'), 'types' => MediaLibrary::TYPES, 'direct' => DirectUploads::enabled(), 'max_request_kb' => (int) config('media.max_request_kb')],
            'unread' => ContactMessage::where('status', 'new')->count(),
        ]);
    }

    public function saveDraft(Request $request): RedirectResponse
    {
        $data = $request->validate(['doc' => ['required', 'array'], 'version' => ['required', 'integer', 'min:0']]);
        $this->content->saveDraft($data['doc'], (int) $data['version'], $request->user());

        return back()->with('success', 'حُفظت المسودة. لا يراها الزوار حتى تضغط «نشر التغييرات».');
    }

    public function publish(Request $request): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);
        $revision = $this->content->publish((int) $data['version'], $request->user());

        return back()->with('success', $revision
            ? 'نُشرت التغييرات على الموقع (النسخة '.$revision->number.'). يراها الزوار الآن.'
            : 'الموقع يعرض هذه النسخة بالفعل، فلا توجد تغييرات جديدة للنشر.');
    }

    public function discard(Request $request): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:0']]);
        $this->content->discard((int) $data['version'], $request->user());

        return back()->with('success', 'أُلغيت التعديلات غير المنشورة، وعاد المحرر إلى ما يراه الزوار.');
    }

    public function restore(Request $request, SiteRevision $revision): RedirectResponse
    {
        $new = $this->content->restore($revision, $request->user());

        return back()->with('success', 'استُرجعت النسخة '.$revision->number.' ونُشرت على الموقع (النسخة '.$new->number.').');
    }

    /** The saved draft exactly as visitors would see it; only staff with website.manage reach it, never indexed. */
    public function preview(Request $request, SiteRenderer $renderer): HttpResponse
    {
        URL::defaults(['club' => $this->tenants->require()->slug]);
        // The same draft on the English site (?lang=en), and a language switch that stays in the preview.
        if ($request->query('lang') === 'en') {
            app()->setLocale('en');
        }
        $this->alternates = collect(SiteLanguage::ALL)->mapWithKeys(fn ($l) => [$l => route('club.website.preview', ['lang' => $l])])->all();
        $site = $this->content->site();
        $rendered = $renderer->render($this->content->editable($site), preview: true);

        return $this->page('Site/Home', ['page' => $rendered['page']], null, null, null, null, $rendered)
            ->toResponse($request)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }

    /** A signed address to put one image straight into the Spaces bucket (404 while direct upload is off). */
    public function directUpload(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'type' => ['nullable', 'string', 'max:100'], 'size' => ['required', 'integer', 'min:1', 'max:'.config('gym.max_image_kb') * 1024]]);

        return response()->json(DirectUploads::begin($data['name'], (string) ($data['type'] ?? '')));
    }

    /** A library image as it was uploaded, for download. */
    public function mediaOriginal(SiteMedia $media): StreamedResponse
    {
        return ImageStore::download($media->path, $media->title ?: 'image-'.$media->id);
    }

    public function uploadMedia(Request $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $request->validate([
            'images' => ['required_without:images_direct', 'array', 'max:12'],
            'images.*' => ['required', ...MediaLibrary::rules()],
            'images_direct' => ['nullable', 'array', 'max:12'],
            'images_direct.*' => ['string', 'max:200'],
            'images_preview' => ['nullable', 'array'],
            'images_preview.*' => ImageRule::previewRules(),
            'title' => ['nullable', 'string', 'max:120'],
            'gallery' => ['nullable', 'boolean'],
        ], [
            'images.*.max' => 'حجم الصورة أكبر من المسموح ('.(int) (config('gym.max_image_kb') / 1024).' ميجابايت).',
        ]);

        $created = [];
        foreach (ImageUpload::all($request, 'images') as $upload) {
            $created[] = MediaLibrary::upload($upload, $request->input('title'), $request->boolean('gallery'));
        }
        $audit->log('website.media_uploaded', null, ['ids' => array_map(fn ($m) => $m->id, $created)]);

        return $request->expectsJson()
            ? response()->json(['media' => array_map(fn ($m) => MediaLibrary::present($m), $created)])
            : back()->with('success', 'تم رفع الصور.');
    }

    public function updateMedia(Request $request, SiteMedia $media): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['alt' => ['nullable', 'string', 'max:255'], 'title' => ['nullable', 'string', 'max:120']]);
        $media->update(['alt' => trim((string) ($data['alt'] ?? '')) ?: null, 'title' => trim((string) ($data['title'] ?? '')) ?: null]);

        return $request->expectsJson()
            ? response()->json(['media' => MediaLibrary::present($media, MediaLibrary::uses($this->content->site()))])
            : back()->with('success', 'حُفظ وصف الصورة.');
    }

    public function deleteMedia(Request $request, SiteMedia $media, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        try {
            MediaLibrary::delete($media, $this->content->site());
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->validator->errors()->first('media'), 'errors' => $e->errors()], 422);
            }

            throw $e;
        }
        $audit->log('website.media_deleted', null, ['id' => $media->id]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'حُذفت الصورة.');
    }
}
