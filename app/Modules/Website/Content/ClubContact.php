<?php

namespace App\Modules\Website\Content;

use App\Core\Audit\AuditLogger;
use App\Models\Tenant;
use App\Modules\Website\Models\SiteSetting;

/**
 * The club's phone, email and address are one set of facts, kept in two places the owner edits: the club settings
 * (printed on invoices and papers) and the website's «التواصل» tab (shown on the site, with its English address). They
 * are kept equal (owner's decision, 2026-11-24) so the site and the panel never say two different things:
 *
 *   toSite  the club settings are saved: the site's published and draft contact take them at once (no new revision —
 *           the same facts, not a new design). An editor still open on the old draft is told to reload (its version).
 *   toClub  the site is published: the club settings take the published phone, email and address.
 *
 * A value left empty on one side never empties the other: it is filled from it instead.
 */
final class ClubContact
{
    public const FIELDS = ['phone', 'email', 'address'];

    /** Both ways, once: the club's values where it has them, the site's where the club has none. */
    public static function sync(Tenant $tenant): void
    {
        self::toSite($tenant);
        $published = SiteSetting::query()->value('published');
        if (is_array($published)) {
            self::toClub($published, $tenant);
        } elseif (is_string($published) && is_array($doc = json_decode($published, true))) {
            self::toClub($doc, $tenant);
        }
    }

    /** @param array<string, mixed> $doc the published document */
    public static function toClub(array $doc, Tenant $tenant): void
    {
        $contact = is_array($doc['contact'] ?? null) ? $doc['contact'] : [];
        $changes = [];
        foreach (self::FIELDS as $field) {
            $value = trim((string) ($contact[$field] ?? ''));
            if ($value !== '' && ! self::same($field, $value, $tenant->{$field})) {
                $changes[$field] = $value;
            }
        }
        if ($changes === []) {
            return;
        }
        $before = $tenant->only(array_keys($changes));
        $tenant->forceFill($changes)->save();
        app(AuditLogger::class)->logChanges('settings.club_contact_from_site', $tenant, $before, $tenant->only(array_keys($changes)));
    }

    public static function toSite(Tenant $tenant): void
    {
        $site = SiteSetting::query()->lockForUpdate()->first();
        if (! $site) {
            return;
        }
        $published = $site->published !== null ? self::patched($site->published, $tenant) : null;
        $draft = $site->draft !== null ? self::patched($site->draft, $tenant) : null;
        $publishedChanged = $published !== null && $published !== $site->published;
        $draftChanged = $draft !== null && $draft !== $site->draft;
        if (! $publishedChanged && ! $draftChanged) {
            return;
        }

        $fill = [];
        if ($publishedChanged) {
            $fill['published'] = $published;
            $fill['contact_phone'] = $published['contact']['phone'] ?: null;
            $fill['contact_email'] = $published['contact']['email'] ?: null;
            $fill['address'] = $published['contact']['address'] ?: null;
        }
        if ($draftChanged) {
            // An editor open on the draft before this change saves nothing over it: it is told to reload.
            $next = (int) $site->draft_version + 1;
            $fill['draft'] = $draft;
            $fill['draft_version'] = $next;
            if ($site->published_draft_version === $site->draft_version) {
                $fill['published_draft_version'] = $next;
            }
        }
        $site->forceFill($fill)->save();
    }

    /** @param array<string, mixed> $doc @return array<string, mixed> */
    private static function patched(array $doc, Tenant $tenant): array
    {
        if (! is_array($doc['contact'] ?? null)) {
            return $doc;
        }
        foreach (self::FIELDS as $field) {
            $value = trim((string) $tenant->{$field});
            if ($value === '' || self::same($field, $value, (string) ($doc['contact'][$field] ?? ''))) {
                continue;
            }
            // The site keeps a phone as digits (SiteDocument), an email and an address as written.
            $doc['contact'][$field] = $field === 'phone' ? (string) preg_replace('/\D+/', '', $value) : $value;
        }

        return $doc;
    }

    private static function same(string $field, ?string $a, ?string $b): bool
    {
        return match ($field) {
            'phone' => preg_replace('/\D+/', '', (string) $a) === preg_replace('/\D+/', '', (string) $b),
            'email' => strtolower(trim((string) $a)) === strtolower(trim((string) $b)),
            default => trim((string) $a) === trim((string) $b),
        };
    }
}
