<?php

namespace App\Http\Controllers\Club;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The club's branches. Opening and stopping branches belongs to someone who works across the club; a user tied to a
 * branch may only keep their own branch's details.
 */
class BranchController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Club/Settings/Branches', ['branches' => Branch::orderByDesc('is_main')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if($request->user()->branch_id !== null, 403, 'حسابك مرتبط بفرعك، فلا تفتح فروعًا.');
        Branch::create($this->validated($request));

        return back()->with('success', 'تمت إضافة الفرع.');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $tied = $request->user()->branch_id;
        abort_if($tied !== null && (int) $tied !== $branch->id, 403, 'حسابك مرتبط بفرعك، فلا تعدّل فرعًا آخر.');
        $data = $this->validated($request, $branch);
        if ($tied !== null) {
            unset($data['is_active']);
        }
        $branch->update($data);

        return back()->with('success', 'تم حفظ الفرع.');
    }

    public function destroy(Request $request, Branch $branch): RedirectResponse
    {
        abort_if($request->user()->branch_id !== null, 403, 'حسابك مرتبط بفرعك، فلا توقف الفروع.');
        if ($branch->is_main) {
            return back()->with('error', 'لا يمكن حذف الفرع الرئيسي.');
        }
        $branch->update(['is_active' => false]);

        return back()->with('success', 'تم إيقاف الفرع.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Branch $branch = null): array
    {
        // A new name may not be one another branch of the club has. A branch saved under the name it already had passes:
        // branches that shared a name before this rule keep it, untouched, and stay editable.
        $kept = $branch !== null && $request->input('name') === $branch->name;

        return $request->validate([
            'name' => ['required', 'string', 'max:120', ...($kept ? [] : [TenantRule::unique('branches', 'name')->ignore($branch?->id)])],
            'code' => ['nullable', 'alpha_dash', 'max:20', TenantRule::unique('branches', 'code')->ignore($branch?->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ], ['name.unique' => 'يوجد في النادي فرع بهذا الاسم. اختر اسمًا آخر.']);
    }
}
