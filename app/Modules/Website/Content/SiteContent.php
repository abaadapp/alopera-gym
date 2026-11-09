<?php

namespace App\Modules\Website\Content;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Website\Models\SiteMedia;
use App\Modules\Website\Models\SiteRevision;
use App\Modules\Website\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draft, publish and go back for the club's website content.
 *
 * - Save keeps the owner's work as a draft in the database: visitors still see the published version.
 * - Publish copies the saved draft to the live version in one transaction and keeps a numbered revision.
 * - Discard drops unpublished changes; restore publishes an older revision again (as a new revision).
 *
 * Every change locks the club's site row and names the draft version it started from, so two windows, a double
 * click or two staff members cannot overwrite each other or publish twice: the second one is told to reload.
 * Content changes are plain database rows: no rebuild of the pages and no deploy is involved.
 */
final class SiteContent
{
    public function __construct(private AuditLogger $audit, private TenantContext $tenants) {}

    public function site(): SiteSetting
    {
        return SiteSetting::firstOrCreate([]);
    }

    /** What the editor opens: the saved draft, else what is live, else the starting design. @return array<string, mixed> */
    public function editable(SiteSetting $site): array
    {
        $doc = $site->draft ?? $site->published ?? SiteDefaults::document($site, $this->tenants->require(), true);
        // A document saved before the inner pages' words could be edited gets their defaults.
        $doc['pages'] = SitePages::of($doc);

        return $doc;
    }

    /** What visitors see. @return array<string, mixed> */
    public function live(SiteSetting $site): array
    {
        return $site->published ?? SiteDefaults::document($site, $this->tenants->require());
    }

    /** @return array<string, mixed> */
    public function status(SiteSetting $site): array
    {
        $name = fn (?int $id) => $id ? User::whereKey($id)->value('name') : null;

        return [
            'has_unpublished' => $site->published === null ? $site->draft !== null : $site->published_draft_version !== $site->draft_version,
            'never_published' => $site->published === null,
            'published_at' => $site->content_published_at?->format('Y-m-d H:i'),
            'published_by' => $name($site->content_published_by),
            'draft_saved_at' => $site->draft_saved_at?->format('Y-m-d H:i'),
            'draft_saved_by' => $name($site->draft_saved_by),
        ];
    }

    /** @param array<string, mixed> $input */
    public function saveDraft(array $input, int $version, User $user): SiteSetting
    {
        $doc = SiteDocument::clean($input);

        $site = DB::transaction(function () use ($doc, $version, $user) {
            $site = $this->locked($version);
            $site->forceFill(['draft' => $doc, 'draft_version' => $version + 1, 'draft_saved_at' => now(), 'draft_saved_by' => $user->id])->save();

            return $site;
        });
        $this->audit->log('website.draft_saved', $site, ['version' => $site->draft_version, 'sections' => count($doc['sections'])]);

        return $site;
    }

    /** Publishes the saved draft. Returns null when the live site already shows it (a repeated click). */
    public function publish(int $version, User $user, ?string $note = null): ?SiteRevision
    {
        $revision = DB::transaction(function () use ($version, $user, $note) {
            $site = $this->locked($version);
            if ($site->published !== null && $site->published_draft_version === $site->draft_version) {
                return null;
            }
            // Checked again at publish time: an image may have been deleted since the draft was saved.
            $doc = SiteDocument::clean($site->draft ?? SiteDefaults::document($site, $this->tenants->require(), true));

            return $this->goLive($site, $doc, $user, $note);
        });
        if ($revision) {
            $this->audit->log('website.published', $revision, ['number' => $revision->number]);
        }

        return $revision;
    }

    public function discard(int $version, User $user): void
    {
        $site = DB::transaction(function () use ($version) {
            $site = $this->locked($version);
            $next = $version + 1;
            $site->forceFill(['draft' => null, 'draft_version' => $next, 'published_draft_version' => $site->published !== null ? $next : null])->save();

            return $site;
        });
        $this->audit->log('website.draft_discarded', $site, ['by' => $user->id]);
    }

    /** Publishes an older revision again; images deleted since then are left out rather than blocking it. */
    public function restore(SiteRevision $old, User $user): SiteRevision
    {
        $revision = DB::transaction(function () use ($old, $user) {
            $site = SiteSetting::query()->lockForUpdate()->firstOrCreate([]);
            $doc = SiteDocument::clean(self::withoutMissingImages($old->document));
            $site->forceFill(['draft_version' => $site->draft_version + 1]);

            return $this->goLive($site, $doc, $user, 'استرجاع النسخة '.$old->number);
        });
        $this->audit->log('website.revision_restored', $revision, ['from' => $old->number, 'number' => $revision->number]);

        return $revision;
    }

    /** @param array<string, mixed> $doc */
    private function goLive(SiteSetting $site, array $doc, User $user, ?string $note): SiteRevision
    {
        $revision = SiteRevision::create([
            'number' => (int) SiteRevision::max('number') + 1,
            'document' => $doc,
            'note' => $note,
            'published_by' => $user->id,
            'published_at' => now(),
        ]);
        $site->forceFill([
            'draft' => $doc,
            'published' => $doc,
            'published_draft_version' => $site->draft_version,
            'content_published_at' => now(),
            'content_published_by' => $user->id,
        ] + self::legacyColumns($doc))->save();

        return $revision;
    }

    /** The site row, locked for this transaction, after checking nobody changed the draft since $version. */
    private function locked(int $version): SiteSetting
    {
        $site = SiteSetting::query()->lockForUpdate()->first() ?? SiteSetting::create([]);
        if ($site->draft_version !== $version) {
            throw ValidationException::withMessages(['version' => 'عُدّل الموقع من نافذة أخرى أو من شخص آخر بعد فتحك هذه الصفحة، فلم يُحفظ شيء. أعد تحميل الصفحة ثم أعد تعديلك.']);
        }

        return $site;
    }

    /**
     * The contact details and texts other pages already read from site_settings (header, footer, structured data,
     * sign-up and payment pages), kept equal to what is published.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private static function legacyColumns(array $doc): array
    {
        $c = $doc['contact'];

        return [
            'contact_phone' => $c['phone'] ?: null,
            'whatsapp_number' => $c['whatsapp'] ?: null,
            'contact_email' => $c['email'] ?: null,
            'address' => $c['address'] ?: null,
            'map_url' => $c['map_url'] ?: null,
            'working_hours' => collect($c['hours'])->map(fn ($h) => ['days' => $h['days'], 'hours' => $h['hours']])->values()->all(),
            'social_links' => array_filter($c['social']),
            'announcement' => $doc['announcement'] ?: null,
            'seo_title' => $doc['seo']['title'] ?: null,
            'seo_description' => $doc['seo']['description'] ?: null,
        ];
    }

    /** @param array<string, mixed> $doc @return array<string, mixed> */
    private static function withoutMissingImages(array $doc): array
    {
        $ids = array_keys(SiteDocument::imageUses($doc));
        $known = array_flip(SiteMedia::whereIn('id', $ids)->pluck('id')->all());
        $strip = function ($value) use (&$strip, $known) {
            if (is_array($value) && isset($value['id'], $value['fit']) && is_int($value['id'])) {
                return isset($known[$value['id']]) ? $value : null;
            }

            return is_array($value) ? array_map($strip, $value) : $value;
        };
        foreach ($doc['sections'] ?? [] as $i => $section) {
            $doc['sections'][$i]['data'] = array_map($strip, $section['data'] ?? []);
        }

        return $doc;
    }
}
