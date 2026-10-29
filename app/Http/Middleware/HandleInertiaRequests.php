<?php

namespace App\Http\Middleware;

use App\Core\Auth\PermissionRegistry;
use App\Core\Notifications\StaffNotifications;
use App\Core\Tenancy\TenantContext;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Everything is lazy: the tenant is resolved by route middleware that runs
     * after this one, so values are read when the page renders.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'locale' => app()->getLocale(),
            'dir' => app()->getLocale() === 'ar' ? 'rtl' : 'ltr',
            'auth' => fn () => $this->staff($request),
            'tenant' => fn () => $this->tenant(),
            'modules' => ['sales' => PermissionRegistry::moduleEnabled('sales'), 'inventory' => PermissionRegistry::moduleEnabled('inventory')],
            'member' => fn () => $this->member(),
            // The bell of the club panel's top bar (App\Core\Notifications\StaffNotifications).
            'notifications' => fn () => $request->routeIs('club.*') && $request->user('web') instanceof User && app(TenantContext::class)->get()
                ? app(StaffNotifications::class)->for($request->user('web'))
                : null,
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'token' => $request->session()->get('device_token'),
                // Unique per response that carries a message, so the same text twice still shows twice.
                'id' => $request->session()->hasAny(['success', 'error']) ? (string) Str::ulid() : null,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function staff(Request $request): ?array
    {
        $user = $request->user('web');
        if (! $user instanceof User) {
            return null;
        }

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->name,
                'role_key' => $user->role?->key,
                'is_platform_admin' => $user->isPlatformAdmin(),
                // For the branch label of the top bar: the user's branch, and how many the club has.
                'branch' => app(TenantContext::class)->get() ? $user->branch?->name : null,
                'branches' => app(TenantContext::class)->get() ? Branch::where('is_active', true)->count() : 0,
            ],
            'permissions' => $user->permissions(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function tenant(): ?array
    {
        $tenant = app(TenantContext::class)->get();

        return $tenant ? self::tenantPayload($tenant) : null;
    }

    /** @return array<string, mixed> */
    public static function tenantPayload(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'logo_url' => $tenant->logoUrl(),
            'primary_color' => $tenant->primary_color,
            'secondary_color' => $tenant->secondary_color,
            'currency' => $tenant->currency,
            'phone' => $tenant->phone,
            'trial_ends_at' => $tenant->trial_ends_at?->toDateString(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function member(): ?array
    {
        if (! app(TenantContext::class)->check()) {
            return null;
        }

        $member = auth('member')->user();

        return $member ? ['id' => $member->id, 'name' => $member->full_name, 'member_no' => $member->member_no] : null;
    }
}
