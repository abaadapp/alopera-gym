<?php

namespace App\Core\Tenancy;

use App\Models\Branch;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Which branch the club panel is showing, for the signed-in staff user, and which branches a user may reach.
 *
 * A user tied to a branch (users.branch_id) always works in that branch: the server ignores anything else they ask
 * for, and a record of another branch opened by its address answers 404 (EnforceBranchAccess). A club-wide user
 * chooses a branch, or «all branches», from the top bar; the choice is kept in their session, per club, and dropped
 * if the branch is closed or belongs to another club. It narrows what they see, not what they may open.
 *
 * Records without a branch — everything made before the club had a second branch, and anything saved while «all
 * branches» was chosen — count as the main branch's. They are never moved or rewritten: the main branch's lists
 * include them, the other branches' lists do not, and the branches' figures add up to the club's.
 *
 * The member record is one per club: reception of any branch finds and serves any member (check-in, sale), and the
 * check-in or sale is recorded in the branch where it happened. Lists of members follow the branch like the rest.
 */
final class BranchContext
{
    /** @var array<int, int|null> the resolved branch per user, for this request */
    private array $resolved = [];

    /** @var array<int, int|null> the main branch per club, for this request */
    private array $main = [];

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

    /** The club's main branch (the one marked main, else the oldest), which owns the records without a branch. */
    public function mainId(): ?int
    {
        if (! $this->tenants->check()) {
            return null;
        }
        $tenant = $this->tenants->id();
        if (! array_key_exists($tenant, $this->main)) {
            $this->main[$tenant] = Branch::orderByDesc('is_main')->orderBy('id')->value('id');
        }

        return $this->main[$tenant];
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
     * The branches a form offers this user: their own when tied to one, else every branch of the club (the main
     * branch first). $withClosed also lists closed branches, for a list that names existing records' branches.
     *
     * @return Collection<int, Branch>
     */
    public function choices(bool $withClosed = false, ?User $user = null): Collection
    {
        $user ??= Auth::guard('web')->user();

        return Branch::query()
            ->when($user instanceof User && $user->branch_id, fn ($q) => $q->whereKey($user->branch_id))
            ->when(! $withClosed, fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']);
    }

    /** May this user reach a record of this branch? A club-wide user reaches every branch; null is the main branch. */
    public function allows(User $user, ?int $branch): bool
    {
        if (! $user->branch_id) {
            return true;
        }

        return (int) $user->branch_id === ($branch ?? $this->mainId());
    }

    /**
     * The branch a new record is saved under: the one asked for (the form rule has already refused a branch the
     * user may not reach), else the user's own branch or the one the panel is showing, else none («all branches»).
     */
    public function assign(?int $requested = null, ?User $user = null): ?int
    {
        return $requested ?? $this->id($user);
    }

    /**
     * Form rule for a branch field: a user tied to a branch may keep the record's current branch or choose their
     * own (or none, when theirs is the main branch); a club-wide user may choose any branch of the club.
     *
     * @return list<mixed>
     */
    public function rule(?int $current = null): array
    {
        return [TenantRule::exists('branches'), function (string $attribute, mixed $value, Closure $fail) use ($current) {
            $user = Auth::guard('web')->user();
            $value = $value === null || $value === '' ? null : (int) $value;
            if ($user instanceof User && $value !== $current && ! $this->allows($user, $value)) {
                $fail('لا يمكنك اختيار فرع غير فرعك.');
            }
        }];
    }

    /**
     * Narrow a query to the current branch. No branch chosen: unchanged.
     *
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public function apply(BuilderContract $query, string $column = 'branch_id', ?User $user = null): BuilderContract
    {
        return $this->narrow($query, $this->id($user), $column);
    }

    /**
     * Narrow a query to one branch: its records, and for the main branch also the records without a branch.
     *
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public function narrow(BuilderContract $query, ?int $branch, string $column = 'branch_id'): BuilderContract
    {
        if (! $branch) {
            return $query;
        }

        return $branch === $this->mainId()
            ? $query->where(fn ($q) => $q->where($column, $branch)->orWhereNull($column))
            : $query->where($column, $branch);
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
