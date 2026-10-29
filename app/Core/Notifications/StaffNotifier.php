<?php

namespace App\Core\Notifications;

use App\Core\Tenancy\TenantContext;
use App\Models\StaffNotification;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Puts notifications in the bell of the staff who may act on them, and takes them out once handled.
 *
 * notify() gives each recipient one row per event key: the unique (user, key) index makes a repeat a no-op, so a
 * scheduled re-check (notifications:sync) can call it again safely. Recipients are the club's active staff holding
 * one of the permissions, narrowed further by `$audience` (a document they may see, an order of their branch).
 * resolve() marks an event read for everyone once it has been dealt with in its own screen.
 */
final class StaffNotifier
{
    /** @var array<int, Collection<int, User>> active staff per club, for this request */
    private array $staff = [];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  string|list<string>  $permissions  any one of them is enough
     * @param  (callable(User): bool)|null  $audience
     */
    public function notify(string|array $permissions, string $key, string $kind, string $text, ?string $url = null, ?callable $audience = null): int
    {
        $tenant = $this->tenants->get();
        if (! $tenant) {
            return 0;
        }

        $recipients = $this->staff($tenant->id)->filter(fn (User $u) => collect((array) $permissions)->contains(fn ($p) => $u->can($p)) && (! $audience || $audience($u)));
        if ($recipients->isEmpty()) {
            return 0;
        }

        $now = now();

        return StaffNotification::query()->insertOrIgnore($recipients->map(fn (User $u) => [
            'tenant_id' => $tenant->id,
            'user_id' => $u->id,
            'key' => mb_substr($key, 0, 120),
            'kind' => $kind,
            'text' => mb_substr($text, 0, 300),
            'url' => $url,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all());
    }

    /** The event was handled: mark it read for everyone who got it. A key ending in «*» matches a prefix. */
    public function resolve(string $key): void
    {
        if (! $this->tenants->check()) {
            return;
        }
        StaffNotification::query()->unread()
            ->when(str_ends_with($key, '*'), fn ($q) => $q->where('key', 'like', addcslashes(substr($key, 0, -1), '%_\\').'%'), fn ($q) => $q->where('key', $key))
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    /** @return Collection<int, User> */
    private function staff(int $tenantId): Collection
    {
        return $this->staff[$tenantId] ??= User::ofTenant($tenantId)->where('is_active', true)->with('role')->get();
    }
}
