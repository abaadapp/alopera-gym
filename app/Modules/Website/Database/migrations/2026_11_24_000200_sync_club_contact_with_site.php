<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Once, for the clubs there are: the club settings and the website say the same phone, email and address
 * (ClubContact keeps them so from now on, owner's decision 2026-11-24). Where both have one and they differ, the club
 * settings win — they are what invoices print; an empty side is filled from the other. Nothing is emptied. Plain rows
 * only (the deploy's migration check): the same rules as ClubContact, written out here.
 */
return new class extends Migration
{
    private const FIELDS = ['phone', 'email', 'address'];

    public function up(): void
    {
        foreach (DB::table('site_settings')->orderBy('id')->get(['id', 'tenant_id', 'published', 'draft', 'draft_version', 'published_draft_version']) as $site) {
            $tenant = DB::table('tenants')->where('id', $site->tenant_id)->first(['id', 'phone', 'email', 'address']);
            if (! $tenant) {
                continue;
            }
            $published = is_string($site->published) ? json_decode($site->published, true) : null;
            $draft = is_string($site->draft) ? json_decode($site->draft, true) : null;

            // The club's empty fields take the published site's.
            $club = [];
            foreach (self::FIELDS as $field) {
                $mine = trim((string) $tenant->{$field});
                $theirs = trim((string) ($published['contact'][$field] ?? ''));
                if ($mine === '' && $theirs !== '') {
                    $club[$field] = $theirs;
                }
            }
            if ($club !== []) {
                DB::table('tenants')->where('id', $tenant->id)->update($club + ['updated_at' => now()]);
            }
            $values = array_map(fn ($f) => trim((string) ($club[$f] ?? $tenant->{$f})), array_combine(self::FIELDS, self::FIELDS));

            // The site's published and draft contact take the club's values.
            $row = [];
            foreach (['published' => $published, 'draft' => $draft] as $column => $doc) {
                if (! is_array($doc) || ! is_array($doc['contact'] ?? null)) {
                    continue;
                }
                $patched = $doc;
                foreach ($values as $field => $value) {
                    if ($value !== '') {
                        $patched['contact'][$field] = $field === 'phone' ? (string) preg_replace('/\D+/', '', $value) : $value;
                    }
                }
                if ($patched !== $doc) {
                    $row[$column] = json_encode($patched, JSON_UNESCAPED_UNICODE);
                    if ($column === 'published') {
                        $row['contact_phone'] = $patched['contact']['phone'] ?: null;
                        $row['contact_email'] = $patched['contact']['email'] ?: null;
                        $row['address'] = $patched['contact']['address'] ?: null;
                    } else {
                        $row['draft_version'] = (int) $site->draft_version + 1;
                        if ((int) $site->published_draft_version === (int) $site->draft_version) {
                            $row['published_draft_version'] = $row['draft_version'];
                        }
                    }
                }
            }
            if ($row !== []) {
                DB::table('site_settings')->where('id', $site->id)->update($row);
            }
        }
    }

    public function down(): void
    {
        // Data only: the values agreed on stay.
    }
};
