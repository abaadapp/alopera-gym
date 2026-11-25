<?php

namespace App\Core\Demo;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copies the gym's setup to the demo club, one way: the club's settings and branding, the website (content, posts,
 * images), plans and prices, programs, coupons, PT packages, products and add-ons, message templates and keyword
 * replies, expense categories, job titles and document types. What the owner changes in the gym shows in the demo on
 * the next run (php artisan gym:demo-sync, every five minutes); what is changed in the demo never reaches the gym.
 *
 * The demo's own made-up records (members, sales, stock, trainers, branches…) are never touched. Each copied row is
 * linked to its gym row (demo_mirror_links), so a rename or a new price updates the same demo row. A gym row deleted
 * is retired in the demo (soft-deleted, or switched off when the demo's history still uses it). Uploaded files under
 * the gym's folder are copied into the demo's own folder, so nothing the demo does can remove one of the gym's files.
 */
final class DemoMirror
{
    /**
     * In order (a table after the ones its ids point at). key: the natural keys tried, in turn, to adopt a demo row
     * of the same name before the first link; own: columns the demo keeps for itself; ids: id columns pointing at
     * another copied table; json_ids: a JSON list of such ids; users: user columns set to the demo owner; drop:
     * columns emptied (they point at the gym's own people).
     */
    private const TABLES = [
        'expense_categories' => ['key' => ['name']],
        'product_categories' => ['key' => ['name']],
        'gym_programs' => ['key' => ['slug', 'name']],
        'gym_membership_plans' => ['key' => ['name']],
        'gym_coupons' => ['key' => ['code'], 'own' => ['used_count'], 'json_ids' => ['plan_ids' => 'gym_membership_plans']],
        'gym_pt_packages' => ['key' => ['name'], 'drop' => ['trainer_id']],
        'products' => ['key' => ['sku', 'barcode', 'name'], 'own' => ['stock_quantity'], 'ids' => ['product_category_id' => 'product_categories']],
        'product_addons' => ['key' => ['name'], 'ids' => ['stock_product_id' => 'products']],
        'message_templates' => ['key' => ['key']],
        'auto_replies' => ['key' => ['keyword'], 'own' => ['hits']],
        'gym_job_titles' => ['key' => ['name']],
        'gym_document_types' => ['key' => ['name']],
        'site_media' => ['key' => ['path']],
        'site_posts' => ['key' => ['slug'], 'users' => ['created_by']],
    ];

    /** Pivot => [parent column => its table, other column => its table]: the demo's links follow the gym's. */
    private const PIVOTS = [
        'gym_plan_program' => ['membership_plan_id' => 'gym_membership_plans', 'program_id' => 'gym_programs'],
        'product_addon_product' => ['product_addon_id' => 'product_addons', 'product_id' => 'products'],
        'product_addon_category' => ['product_addon_id' => 'product_addons', 'product_category_id' => 'product_categories'],
    ];

    /** The club record's own fields copied (the demo keeps its name, slug, contact address and status). */
    private const TENANT_COLUMNS = ['legal_name', 'logo_path', 'primary_color', 'secondary_color', 'address', 'city', 'country',
        'latitude', 'longitude', 'timezone', 'currency', 'locale'];

    private Tenant $gym;

    private Tenant $demo;

    private ?int $owner = null;

    /** @var array<string, array<int, int>> table => gym id => demo id */
    private array $links = [];

    /** @var array<string, int> */
    private array $changed = [];

    /** @return array<string, int> rows changed in the demo, by table (empty when it was already in step) */
    public function sync(Tenant $gym, Tenant $demo): array
    {
        if ($gym->is($demo) || ! $demo->setting('is_demo', false)) {
            throw new \LogicException('The demo mirror copies the gym to a club marked as the demo only.');
        }
        $this->gym = $gym;
        $this->demo = $demo;
        $this->owner = DB::table('users')->where('tenant_id', $demo->id)->orderBy('id')->value('id');
        $this->changed = [];
        $this->links = DB::table('demo_mirror_links')->get()->groupBy('table_name')
            ->map(fn ($rows) => $rows->pluck('target_id', 'source_id')->map(fn ($v) => (int) $v)->all())->all();

        DB::transaction(function () {
            $this->tenant();
            $this->siteSettings();
            foreach (self::TABLES as $table => $rules) {
                if (Schema::hasTable($table)) {
                    $this->table($table, $rules);
                }
            }
            foreach (self::PIVOTS as $pivot => $columns) {
                if (Schema::hasTable($pivot)) {
                    $this->pivot($pivot, $columns);
                }
            }
        });

        return array_filter($this->changed);
    }

    /** After the demo club's rows were deleted (gym:demo --fresh): the links point at nothing any more. */
    public static function forget(): void
    {
        if (Schema::hasTable('demo_mirror_links')) {
            DB::table('demo_mirror_links')->delete();
        }
    }

    private function tenant(): void
    {
        $gym = (array) DB::table('tenants')->where('id', $this->gym->id)->first();
        $demo = (array) DB::table('tenants')->where('id', $this->demo->id)->first();
        $settings = json_decode((string) ($gym['settings'] ?? 'null'), true) ?: [];
        $settings['is_demo'] = true;

        $row = array_intersect_key($gym, array_flip(self::TENANT_COLUMNS));
        $row['settings'] = json_encode($settings, JSON_UNESCAPED_UNICODE);
        $row = $this->moveFiles($row);

        $before = json_decode((string) ($demo['settings'] ?? 'null'), true) ?: [];
        $after = json_decode($row['settings'], true);
        if ($before == $after) {
            unset($row['settings']);
        }
        if ($this->differs($demo, $row)) {
            DB::table('tenants')->where('id', $this->demo->id)->update($row + ['updated_at' => now()]);
            $this->changed['club settings'] = 1;
        }
    }

    private function siteSettings(): void
    {
        if (! Schema::hasTable('site_settings') || ! ($gym = DB::table('site_settings')->where('tenant_id', $this->gym->id)->first())) {
            return;
        }
        $row = array_diff_key((array) $gym, array_flip(['id', 'tenant_id', 'created_at', 'updated_at']));
        foreach (['draft_saved_by', 'content_published_by'] as $user) {
            if (array_key_exists($user, $row)) {
                $row[$user] = $row[$user] === null ? null : $this->owner;
            }
        }
        $row = $this->moveFiles($row);

        $demo = DB::table('site_settings')->where('tenant_id', $this->demo->id)->first();
        if (! $demo) {
            DB::table('site_settings')->insert($row + ['tenant_id' => $this->demo->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->changed['site_settings'] = 1;
        } elseif ($this->differs((array) $demo, $row)) {
            DB::table('site_settings')->where('id', $demo->id)->update($row + ['updated_at' => now()]);
            $this->changed['site_settings'] = 1;
        }
    }

    private function table(string $table, array $rules): void
    {
        $columns = Schema::getColumnListing($table);
        $seen = [];

        foreach (DB::table($table)->where('tenant_id', $this->gym->id)->orderBy('id')->get() as $source) {
            $source = (array) $source;
            $seen[$source['id']] = true;
            $row = $this->translate($source, $rules);
            $targetId = $this->links[$table][$source['id']] ?? null;
            $target = $targetId ? DB::table($table)->where('id', $targetId)->where('tenant_id', $this->demo->id)->first() : null;

            if (! $target) {
                $target = $this->adopt($table, $rules['key'] ?? [], $row);
            }
            if ($target) {
                $target = (array) $target;
                $update = array_diff_key($row, array_flip($rules['own'] ?? []));
                if ($this->differs($target, $update)) {
                    DB::table($table)->where('id', $target['id'])->update($update + (in_array('updated_at', $columns, true) ? ['updated_at' => now()] : []));
                    $this->count($table);
                }
                $this->link($table, $source['id'], $target['id']);

                continue;
            }

            $insert = array_diff_key($row, array_flip($rules['own'] ?? [])) + ['tenant_id' => $this->demo->id];
            foreach (['created_at', 'updated_at'] as $stamp) {
                if (in_array($stamp, $columns, true)) {
                    $insert[$stamp] = $source[$stamp] ?? now();
                }
            }
            $this->link($table, $source['id'], (int) DB::table($table)->insertGetId($insert));
            $this->count($table);
        }

        // Gone from the gym: retired in the demo, never deleted while the demo's own records still point at it.
        foreach ($this->links[$table] ?? [] as $sourceId => $targetId) {
            if (isset($seen[$sourceId])) {
                continue;
            }
            $this->retire($table, $columns, $targetId) && $this->count($table);
            if (! DB::table($table)->where('id', $targetId)->exists()) {
                DB::table('demo_mirror_links')->where('table_name', $table)->where('source_id', $sourceId)->delete();
                unset($this->links[$table][$sourceId]);
            }
        }
    }

    /** The gym row as the demo holds it: the demo's ids, the demo owner, files in the demo's own folder. */
    private function translate(array $source, array $rules): array
    {
        $row = array_diff_key($source, array_flip(['id', 'tenant_id', 'created_at', 'updated_at']));
        foreach ($rules['drop'] ?? [] as $column) {
            array_key_exists($column, $row) && $row[$column] = null;
        }
        foreach ($rules['users'] ?? [] as $column) {
            array_key_exists($column, $row) && $row[$column] = $row[$column] === null ? null : $this->owner;
        }
        foreach ($rules['ids'] ?? [] as $column => $table) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = $this->links[$table][$row[$column]] ?? null;
            }
        }
        foreach ($rules['json_ids'] ?? [] as $column => $table) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $ids = json_decode((string) $row[$column], true);
                $row[$column] = is_array($ids)
                    ? json_encode(array_values(array_filter(array_map(fn ($id) => $this->links[$table][(int) $id] ?? null, $ids))))
                    : null;
            }
        }

        return $this->moveFiles($row);
    }

    /** A demo row already standing for this gym row: the same name, code or slug, not yet the copy of another. */
    private function adopt(string $table, array $keys, array $row): ?object
    {
        $taken = array_values($this->links[$table] ?? []);
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
                continue;
            }
            $found = DB::table($table)->where('tenant_id', $this->demo->id)->where($key, $row[$key])->whereNotIn('id', $taken)->first();
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    private function retire(string $table, array $columns, int $id): bool
    {
        if (in_array('deleted_at', $columns, true)) {
            return DB::table($table)->where('id', $id)->whereNull('deleted_at')->update(['deleted_at' => now()]) > 0;
        }
        try {
            // A savepoint: refused when the demo's own records still use it (a category with expenses…).
            return DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()) > 0;
        } catch (Throwable) {
            return in_array('is_active', $columns, true)
                && DB::table($table)->where('id', $id)->where('is_active', true)->update(['is_active' => false]) > 0;
        }
    }

    private function pivot(string $pivot, array $columns): void
    {
        [$parentColumn, $otherColumn] = array_keys($columns);
        [$parentTable, $otherTable] = array_values($columns);
        $parents = $this->links[$parentTable] ?? [];
        if ($parents === []) {
            return;
        }

        $want = [];
        foreach (DB::table($pivot)->whereIn($parentColumn, array_keys($parents))->get() as $row) {
            $other = $this->links[$otherTable][$row->{$otherColumn}] ?? null;
            if ($other) {
                $want[$parents[$row->{$parentColumn}].':'.$other] = [$parentColumn => $parents[$row->{$parentColumn}], $otherColumn => $other];
            }
        }
        $have = [];
        foreach (DB::table($pivot)->whereIn($parentColumn, array_values($parents))->get() as $row) {
            $have[$row->{$parentColumn}.':'.$row->{$otherColumn}] = true;
        }

        foreach (array_diff_key($have, $want) as $pair => $_) {
            [$parent, $other] = explode(':', $pair);
            DB::table($pivot)->where($parentColumn, $parent)->where($otherColumn, $other)->delete();
            $this->count($pivot);
        }
        foreach (array_diff_key($want, $have) as $insert) {
            DB::table($pivot)->insert($insert);
            $this->count($pivot);
        }
    }

    /**
     * Paths under the gym's folder (tenants/{gym}/…) become the same paths under the demo's (tenants/{demo}/…), in
     * plain and JSON columns alike, and each file is copied there once.
     */
    private function moveFiles(array $row): array
    {
        $pattern = '~tenants(\\\\?/)'.$this->gym->id.'\\\\?/([^"\'\s<>)]+)~';
        foreach ($row as $column => $value) {
            if (! is_string($value) || ! str_contains($value, 'tenants')) {
                continue;
            }
            $row[$column] = preg_replace_callback($pattern, function (array $m) {
                $rest = str_replace('\\/', '/', $m[2]);
                $this->copyFile('tenants/'.$this->gym->id.'/'.$rest, 'tenants/'.$this->demo->id.'/'.$rest);

                return 'tenants'.$m[1].$this->demo->id.$m[1].str_replace('/', $m[1], $rest);
            }, $value);
        }

        return $row;
    }

    private function copyFile(string $from, string $to): void
    {
        try {
            $disk = Storage::disk('public');
            if ($disk->exists($from) && ! $disk->exists($to)) {
                $disk->copy($from, $to);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function differs(array $current, array $wanted): bool
    {
        foreach ($wanted as $column => $value) {
            $now = $current[$column] ?? null;
            if (is_string($value) && is_string($now) && ($a = json_decode($value, true)) !== null && is_array($a)) {
                if ($a != json_decode($now, true)) {
                    return true;
                }

                continue;
            }
            if ((string) $now !== (string) $value || ($now === null) !== ($value === null)) {
                if (is_numeric($now) && is_numeric($value) && (float) $now === (float) $value) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    private function link(string $table, int $sourceId, int $targetId): void
    {
        if (($this->links[$table][$sourceId] ?? null) === $targetId) {
            return;
        }
        DB::table('demo_mirror_links')->updateOrInsert(['table_name' => $table, 'source_id' => $sourceId], ['target_id' => $targetId, 'updated_at' => now(), 'created_at' => now()]);
        $this->links[$table][$sourceId] = $targetId;
    }

    private function count(string $table): void
    {
        $this->changed[$table] = ($this->changed[$table] ?? 0) + 1;
    }
}
