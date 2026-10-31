<?php

namespace App\Modules\Inventory\Http\Controllers\Concerns;

use App\Core\Tenancy\BranchContext;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The branch a stock or purchasing list shows. A user tied to a branch always sees their own; a club-wide user may
 * pick one (?branch=id) or all of them (?branch=all), and by default sees what the top bar shows.
 */
trait ScopesBranches
{
    protected function listBranch(Request $request): ?int
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->branch_id) {
            return (int) $user->branch_id;
        }
        $asked = $request->query('branch');
        if ($asked === 'all') {
            return null;
        }
        if (is_string($asked) && ctype_digit($asked) && Branch::whereKey((int) $asked)->exists()) {
            return (int) $asked;
        }

        return app(BranchContext::class)->id();
    }

    /** @return array{value: string, options: list<array{id: int, name: string}>, locked: bool} */
    protected function branchFilter(Request $request, ?int $current): array
    {
        $choices = app(BranchContext::class)->choices();

        return [
            'value' => $current ? (string) $current : 'all',
            'options' => $choices->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'locked' => (bool) $request->user()->branch_id || $choices->count() < 2,
        ];
    }
}
