<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Payroll\Models\JobTitle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JobTitleController extends Controller
{
    public function index(): Response
    {
        JobTitle::ensureDefaults();

        return Inertia::render('Payroll/JobTitles', [
            'titles' => JobTitle::withCount(['employees', 'employees as active_count' => fn ($q) => $q->where('status', 'active')])->orderBy('name')->get()
                ->map(fn (JobTitle $t) => $t->only('id', 'name', 'description', 'is_active', 'employees_count', 'active_count')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        JobTitle::create($this->validated($request));

        return back()->with('success', 'أُضيف المسمى الوظيفي.');
    }

    public function update(Request $request, JobTitle $jobTitle): RedirectResponse
    {
        $jobTitle->update($this->validated($request, $jobTitle));

        return back()->with('success', 'حُفظ المسمى الوظيفي.');
    }

    public function destroy(JobTitle $jobTitle): RedirectResponse
    {
        if ($jobTitle->employees()->exists()) {
            throw new BusinessRuleException('job_title.in_use', 'المسمى مستخدم في ملفات موظفين؛ أوقفه بدل حذفه.');
        }
        $jobTitle->delete();

        return back()->with('success', 'حُذف المسمى الوظيفي.');
    }

    private function validated(Request $request, ?JobTitle $title = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', TenantRule::unique('gym_job_titles', 'name')->ignore($title?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ], [], ['name' => 'المسمى']);
    }
}
