<?php

namespace App\Http\Middleware;

use App\Core\Auth\PermissionRegistry;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Club\NotificationController;
use App\Models\Branch;
use App\Models\StaffNotification;
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
            // The bell of the club panel's top bar: unread count and the latest few (App\Core\Notifications).
            'notifications' => fn () => $request->routeIs('club.*') && $request->user('web') instanceof User && app(TenantContext::class)->id() === $request->user('web')->tenant_id
                ? [
                    'unread' => StaffNotification::for($request->user('web'))->unread()->count(),
                    'items' => StaffNotification::for($request->user('web'))->latest('id')->limit(8)->get()->map(fn (StaffNotification $n) => NotificationController::row($n))->all(),
                ]
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
            ],
            'permissions' => $user->permissions(),
            'branch' => $this->branch($user),
        ];
    }

    /**
     * The top bar's branch: the one shown, whether the user is tied to it, and — for a club-wide user of a club with
     * more than one branch — the branches to choose from.
     *
     * @return array{current: array{id: int, name: string}|null, locked: bool, options: list<array{id: int, name: string}>, multi: bool}|null
     */
    private function branch(User $user): ?array
    {
        // Only inside the user's own club (a staff member may also be looking at another club's website).
        if (app(TenantContext::class)->id() === null || app(TenantContext::class)->id() !== $user->tenant_id) {
            return null;
        }
        $context = app(BranchContext::class);
        $current = $context->current($user);
        $options = $context->canSwitch($user) ? Branch::where('is_active', true)->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']) : collect();

        return [
            'current' => $current ? ['id' => $current->id, 'name' => $current->name] : null,
            'locked' => ! $context->canSwitch($user),
            'options' => $options->count() > 1 ? $options->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])->all() : [],
            // The club has more than one open branch (screens between branches — transfers, supply — show only then).
            'multi' => $context->canSwitch($user) ? $options->count() > 1 : Branch::where('is_active', true)->count() > 1,
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
