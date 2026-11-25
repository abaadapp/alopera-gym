<?php

namespace App\Console\Commands;

use App\Core\Backup\TenantTables;
use App\Core\Demo\DemoClubBuilder;
use App\Core\Notifications\StaffNotifier;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\HomeGym;
use App\Core\Tenancy\TenantProvisioner;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Services\ChartOfAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Builds the demo club: a club record of its own, next to the gym, filled with made-up data on every screen, whose
 * owner signs in to the same panel and sees only it (HomeGym::allowsPanel). Safe on the live server: it never touches
 * another club's rows and refuses a club that is not marked as the demo.
 *
 *   GYM_DEMO_CLUB=demo   (in .env, then config:cache)
 *   php artisan gym:demo --email=demo@example.org --password=... [--fresh] [--members=240]
 *
 * --fresh deletes the demo club's rows (only rows whose tenant_id is the demo club's) and builds it again.
 */
class BuildDemoClub extends Command
{
    protected $signature = 'gym:demo {--email= : the demo owner\'s sign-in email} {--password= : the demo owner\'s password}
        {--fresh : delete the demo club\'s data and build it again} {--members=240 : how many members to make}';

    protected $description = 'Build (or rebuild) the demo club, full of made-up data, beside the gym';

    public function handle(TenantProvisioner $provisioner, DemoClubBuilder $builder): int
    {
        $slug = HomeGym::demoSlug();
        if (! $slug) {
            $this->error('Set GYM_DEMO_CLUB (the demo club\'s slug, e.g. demo) first; it must differ from GYM_HOME_CLUB.');

            return self::FAILURE;
        }
        $home = HomeGym::tenant();
        if (! $home && Tenant::where('slug', '!=', $slug)->exists()) {
            $this->error('No gym is named as the one served (GYM_HOME_CLUB) and several clubs exist; set it before adding a demo club.');

            return self::FAILURE;
        }
        if ($home?->slug === $slug) {
            $this->error("\"{$slug}\" is the gym itself.");

            return self::FAILURE;
        }

        $email = strtolower((string) $this->option('email'));
        $password = (string) $this->option('password');
        $members = max(20, min(2000, (int) $this->option('members')));
        $check = Validator::make(['email' => $email, 'password' => $password], ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:8']]);
        if ($check->fails()) {
            $this->error($check->errors()->first());

            return self::FAILURE;
        }

        $club = Tenant::withTrashed()->where('slug', $slug)->first();
        if ($club && ! $club->setting('is_demo', false)) {
            $this->error("A club \"{$slug}\" exists and is not marked as the demo club; nothing was changed.");

            return self::FAILURE;
        }
        $taken = User::where('email', $email)->first();
        if ($taken && (! $club || $taken->tenant_id !== $club->id)) {
            $this->error("{$email} already belongs to another account; nothing was changed.");

            return self::FAILURE;
        }
        if ($club && ! $this->option('fresh')) {
            $this->error("The demo club \"{$slug}\" exists. Run with --fresh to delete its data and build it again.");

            return self::FAILURE;
        }

        $this->info($club ? "Deleting the demo club's data ({$slug}, #{$club->id})…" : "Making the demo club ({$slug})…");
        $owner = DB::transaction(function () use ($club, $slug, $email, $password, $provisioner) {
            if ($club) {
                $club->restore();
                $this->wipe($club);
            } else {
                $club = Tenant::create(['name' => 'Alopera Gym — نسخة تجريبية', 'slug' => $slug, 'email' => 'hello@demo.aloperagym.om', 'phone' => '96824000010',
                    'city' => 'مسقط', 'primary_color' => '#9d174d', 'secondary_color' => '#f9a8d4', 'status' => Tenant::STATUS_ACTIVE]);
            }
            $club->forceFill(['status' => Tenant::STATUS_ACTIVE, 'settings' => array_replace($club->settings ?? [], ['is_demo' => true])])->save();

            return $provisioner->furnish($club, ['name' => 'حساب التجربة', 'email' => $email, 'password' => $password]);
        });
        $club = Tenant::where('slug', $slug)->sole();
        // What earlier work in this process remembered of the club (its accounts, branches) was deleted with it.
        foreach ([ChartOfAccounts::class, BranchContext::class, StaffNotifier::class] as $remembered) {
            app()->forgetInstance($remembered);
        }

        $this->info("Filling it with {$members} members and the rest (a few minutes)…");
        $report = $builder->build($club, $owner, $members);
        $this->table(['Part', 'Result'], collect($report)->map(fn ($v, $k) => [$k, $v])->values()->all());

        $skipped = collect($report)->filter(fn ($v) => str_starts_with($v, 'SKIPPED'))->count();
        $this->info('Demo club ready: sign in at '.route('login')." as {$email}.");
        $skipped && $this->warn("{$skipped} part(s) were skipped; the rest is in place (details in the log).");

        return self::SUCCESS;
    }

    /** Every row the demo club owns, children first; nothing of another club. The audit trail stays (append-only). */
    private function wipe(Tenant $club): void
    {
        $tables = array_merge(array_reverse(TenantTables::ORDER), ['staff_notifications']);
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (isset(TenantTables::PIVOTS[$table])) {
                [$fk, $parent] = TenantTables::PIVOTS[$table];
                DB::table($table)->whereIn($fk, DB::table($parent)->where('tenant_id', $club->id)->select('id'))->delete();

                continue;
            }
            if (isset(TenantTables::SELF_REFERENCING[$table])) {
                DB::table($table)->where('tenant_id', $club->id)->update([TenantTables::SELF_REFERENCING[$table] => null]);
            }
            DB::table($table)->where('tenant_id', $club->id)->delete();
        }
    }
}
