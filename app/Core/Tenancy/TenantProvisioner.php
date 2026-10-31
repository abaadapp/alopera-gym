<?php

namespace App\Core\Tenancy;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\SystemRoles;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Website\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sets up the gym once, on installation (php artisan gym:install): its record, main branch, system roles, owner
 * account, website defaults, expense categories and WhatsApp template stubs. The tests use it to build clubs too.
 */
final class TenantProvisioner
{
    public const EXPENSE_CATEGORIES = ['إيجار', 'رواتب', 'كهرباء ومياه', 'صيانة', 'تسويق وإعلانات', 'مستلزمات', 'أخرى'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, slug?: string|null, email?: string|null, phone?: string|null, city?: string|null, primary_color?: string|null, secondary_color?: string|null}  $club
     * @param  array{name: string, email: string, password: string, phone?: string|null}  $owner
     */
    public function provision(array $club, array $owner): Tenant
    {
        return DB::transaction(function () use ($club, $owner) {
            $tenant = Tenant::create([
                'name' => $club['name'],
                'slug' => $this->uniqueSlug($club['slug'] ?? $club['name']),
                'email' => $club['email'] ?? $owner['email'],
                'phone' => $club['phone'] ?? null,
                'city' => $club['city'] ?? null,
                'primary_color' => $club['primary_color'] ?? '#9d174d',
                'secondary_color' => $club['secondary_color'] ?? '#f9a8d4',
                'status' => Tenant::STATUS_ACTIVE,
            ]);

            $this->tenants->run($tenant, function (Tenant $tenant) use ($owner) {
                Branch::create(['name' => 'الفرع الرئيسي', 'code' => 'MAIN', 'is_main' => true, 'phone' => $tenant->phone]);

                $roles = [];
                foreach (SystemRoles::definitions() as $key => $definition) {
                    $roles[$key] = Role::create([
                        'key' => $key,
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'permissions' => $definition['permissions'],
                        'is_system' => true,
                        'is_locked' => $key === SystemRoles::OWNER,
                    ]);
                }

                $user = new User([
                    'name' => $owner['name'],
                    'email' => strtolower($owner['email']),
                    'phone' => $owner['phone'] ?? null,
                    'password' => $owner['password'],
                ]);
                $user->tenant_id = $tenant->id;
                $user->role_id = $roles[SystemRoles::OWNER]->id;
                $user->email_verified_at = now();
                $user->save();

                SiteSetting::create([
                    'hero_title' => $tenant->name,
                    'hero_subtitle' => 'ناديك للياقة والصحة — برامج متنوعة، فريق تدريب محترف، وأجواء تناسبك.',
                    'hero_cta_label' => 'اشترك الآن',
                    'about_title' => 'عن النادي',
                    'contact_phone' => $tenant->phone,
                    'contact_email' => $tenant->email,
                    'seo_title' => $tenant->name.' — نادٍ رياضي',
                    'seo_description' => 'اشترك في '.$tenant->name.': باقات مرنة، حصص جماعية، تدريب شخصي، وحجز أونلاين.',
                ]);

                foreach (self::EXPENSE_CATEGORIES as $name) {
                    ExpenseCategory::create(['name' => $name]);
                }

                TemplateCatalog::seedDefaults();
            });

            $this->audit->log('tenant.provisioned', $tenant, ['owner' => $owner['email']], tenantId: $tenant->id);

            return $tenant;
        });
    }

    public function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'club';
        $slug = $base;
        $i = 2;

        while (Tenant::withTrashed()->where('slug', $slug)->exists() || in_array($slug, ['admin', 'platform', 'app', 'api', 'www'], true)) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
