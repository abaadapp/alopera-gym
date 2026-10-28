<?php

namespace App\Core\Audit;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the append-only audit trail.
 *
 * Inserts go straight through the query builder so that model events, the
 * tenant scope and mass-assignment rules cannot drop or alter an entry.
 */
final class AuditLogger
{
    /** @var array{type: string, id: int|null, name: string|null}|null */
    private ?array $actorOverride = null;

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function log(string $event, ?Model $subject = null, array $properties = [], ?array $old = null, ?array $new = null, ?int $tenantId = null): void
    {
        $actor = $this->actor();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        DB::table('audit_logs')->insert([
            'tenant_id' => $tenantId ?? ($subject?->getAttribute('tenant_id')) ?? $this->tenants->id(),
            'actor_type' => $actor['type'],
            'actor_id' => $actor['id'],
            'actor_name' => $actor['name'] ? Str::limit($actor['name'], 250) : null,
            'event' => $event,
            'subject_type' => $subject ? $this->morphAlias($subject) : null,
            'subject_id' => $subject?->getKey(),
            'old_values' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            'properties' => $properties ? json_encode($properties, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Attribute entries written inside the callback to a non-user actor
     * (a payment gateway webhook, an access device, a scheduled job).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function as(string $type, ?int $id, ?string $name, callable $callback): mixed
    {
        $previous = $this->actorOverride;
        $this->actorOverride = ['type' => $type, 'id' => $id, 'name' => $name];

        try {
            return $callback();
        } finally {
            $this->actorOverride = $previous;
        }
    }

    /** @return array{type: string, id: int|null, name: string|null} */
    private function actor(): array
    {
        if ($this->actorOverride) {
            return $this->actorOverride;
        }

        $user = Auth::guard('web')->user();
        if ($user instanceof User) {
            return ['type' => 'user', 'id' => $user->id, 'name' => $user->name];
        }

        $member = Auth::guard('member')->user();
        if ($member) {
            return ['type' => 'member', 'id' => $member->getAuthIdentifier(), 'name' => $member->full_name ?? null];
        }

        return ['type' => 'system', 'id' => null, 'name' => null];
    }

    private function morphAlias(Model $model): string
    {
        $alias = array_search($model::class, Relation::morphMap(), true);

        return $alias !== false ? $alias : class_basename($model);
    }
}
