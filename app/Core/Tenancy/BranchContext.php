<?php

namespace App\Core\Tenancy;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Facades\Auth;

/**
 * Which branch the club panel is showing, for the signed-in staff user.
 *
 * A user tied to a branch (users.branch_id) always works in that branch: the server ignores anything else they ask
 * for. A club-wide user chooses a branch, or «all branches», from the top bar; the choice is kept in their session,
 * per club, and dropped if the branch is closed or belongs to another club.
 *
 * Lists and the dashboard narrow to the branch with apply(): the branch's own records and the ones not tied to any
 * branch (a club with one branch, or records made before branches were used). Operations — a check-in by QR, the
 * member search of the point of sale, the books — are not narrowed.
 */
final class BranchContext
{
    /** @var array<int, int|null> the resolved branch per user, for this request */
    private array $resolved = [];

    public function __construct(private readonly TenantContext $tenants) {}

    public function id(?User $user = null): ?int
    {
        $user ??= Auth::guard('web')->user();
        if (! $user instanceof User || ! $this->tenants->check() || $user->tenant_id !== $this->tenants->id()) {
            return null;
        }
        if ($user->branch_id) {
            return (int) $user->branch_id;
        }

        if (! array_key_exists($user->id, $this->resolved)) {
            $chosen = session($this->key());
            $this->resolved[$user->id] = $chosen && Branch::whereKey($chosen)->where('is_active', true)->exists() ? (int) $chosen : null;
        }

        return $this->resolved[$user->id];
    }

    public function current(?User $user = null): ?Branch
    {
        $id = $this->id($user);

        return $id ? Branch::find($id) : null;
    }

    /** Only a user not tied to a branch moves between branches. */
    public function canSwitch(User $user): bool
    {
        return $user->branch_id === null;
    }

    public function select(User $user, ?Branch $branch): void
    {
        abort_unless($this->canSwitch($user), 403);
        abort_if($branch !== null && ! $branch->is_active, 404);
        $branch ? session()->put($this->key(), $branch->id) : session()->forget($this->key());
        unset($this->resolved[$user->id]);
    }

    /**
     * Narrow a query to the current branch (its records and those tied to no branch). No branch chosen: unchanged.
     *
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public function apply(BuilderContract $query, string $column = 'branch_id', ?User $user = null): BuilderContract
    {
        $id = $this->id($user);

        return $id ? $query->where(fn ($q) => $q->where($column, $id)->orWhereNull($column)) : $query;
    }

    /**
     * apply() for the signed-in user, without injecting the context.
     *
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public static function scope(BuilderContract $query, string $column = 'branch_id'): BuilderContract
    {
        return app(self::class)->apply($query, $column);
    }

    private function key(): string
    {
        return 'club_branch.'.$this->tenants->id();
    }
}
