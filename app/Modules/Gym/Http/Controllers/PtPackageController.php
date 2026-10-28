<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\Trainer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PtPackageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Gym/Pt/Packages', [
            'packages' => PtPackage::with('trainer:id,name')->orderBy('price')->get()->map(fn ($p) => $p->only('id', 'name', 'description', 'sessions_count', 'session_minutes', 'price', 'validity_days', 'trainer_id', 'is_public', 'is_active') + ['trainer' => $p->trainer?->name]),
            'trainers' => Trainer::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        PtPackage::create($this->validated($request));

        return back()->with('success', 'تمت إضافة باقة التدريب الشخصي.');
    }

    public function update(Request $request, PtPackage $ptPackage): RedirectResponse
    {
        $ptPackage->update($this->validated($request));

        return back()->with('success', 'تم الحفظ.');
    }

    public function destroy(PtPackage $ptPackage): RedirectResponse
    {
        $ptPackage->update(['is_active' => false]);
        $ptPackage->delete();

        return back()->with('success', 'تم الحذف.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sessions_count' => ['required', 'integer', 'min:1', 'max:200'],
            'session_minutes' => ['required', 'integer', 'min:15', 'max:240'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'validity_days' => ['required', 'integer', 'min:1', 'max:730'],
            'trainer_id' => ['nullable', TenantRule::exists('gym_trainers')],
            'is_public' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
