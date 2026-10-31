<?php

namespace App\Http\Controllers\Club;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Club/Settings/Branches', ['branches' => Branch::orderByDesc('is_main')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Branch::create($this->validated($request));

        return back()->with('success', 'تمت إضافة الفرع.');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $branch->update($this->validated($request, $branch));

        return back()->with('success', 'تم حفظ الفرع.');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        if ($branch->is_main) {
            return back()->with('error', 'لا يمكن حذف الفرع الرئيسي.');
        }
        $branch->update(['is_active' => false]);

        return back()->with('success', 'تم إيقاف الفرع.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Branch $branch = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'alpha_dash', 'max:20', TenantRule::unique('branches', 'code')->ignore($branch?->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }
}
