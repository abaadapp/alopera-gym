<?php

namespace App\Http\Controllers\Club;

use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The branch picker of the top bar: a club-wide user chooses a branch of this club, or all of them. */
class BranchSwitchController extends Controller
{
    public function __invoke(Request $request, BranchContext $branches): RedirectResponse
    {
        $data = $request->validate(['branch_id' => ['nullable', 'integer', TenantRule::exists('branches')]]);
        $branch = isset($data['branch_id']) ? Branch::findOrFail($data['branch_id']) : null;
        $branches->select($request->user(), $branch);

        return back()->with('success', $branch ? 'تعرض اللوحة الآن فرع «'.$branch->name.'».' : 'تعرض اللوحة الآن كل الفروع.');
    }
}
