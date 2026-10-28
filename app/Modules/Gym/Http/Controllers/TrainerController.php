<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Support\ImageStore;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Gym\Models\Trainer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TrainerController extends Controller
{
    public function index(TenantContext $tenants): Response
    {
        return Inertia::render('Gym/Trainers/Index', [
            'trainers' => Trainer::with(['availabilities', 'user:id,name,email'])->orderBy('sort')->orderBy('name')->get()->map(fn (Trainer $t) => $t->only(
                'id', 'name', 'phone', 'email', 'bio', 'specialties', 'color', 'is_public', 'is_active', 'sort', 'user_id', 'branch_id'
            ) + [
                'photo_url' => $t->photoUrl(),
                'user' => $t->user?->only('id', 'name', 'email'),
                'availability' => $t->availabilities->map(fn ($a) => ['weekday' => $a->weekday, 'starts_at' => substr($a->starts_at, 0, 5), 'ends_at' => substr($a->ends_at, 0, 5)]),
            ]),
            // Staff accounts that can be linked so the trainer can sign in to her own screen.
            'users' => User::ofTenant($tenants->require()->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'branches' => Branch::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $trainer = new Trainer(collect($data)->except('photo')->all());
        if ($request->hasFile('photo')) {
            $trainer->photo_path = ImageStore::store($request->file('photo'), 'trainers', 800);
        }
        $trainer->save();

        return back()->with('success', 'تمت إضافة المدربة.');
    }

    public function update(Request $request, Trainer $trainer): RedirectResponse
    {
        $data = $this->validated($request, $trainer);
        // Multipart forms drop empty arrays: a missing key means "no specialties".
        $data['specialties'] = $data['specialties'] ?? [];
        $trainer->fill(collect($data)->except('photo')->all());
        if ($request->hasFile('photo')) {
            ImageStore::delete($trainer->photo_path);
            $trainer->photo_path = ImageStore::store($request->file('photo'), 'trainers', 800);
        }
        $trainer->save();

        return back()->with('success', 'تم حفظ بيانات المدربة.');
    }

    public function destroy(Trainer $trainer): RedirectResponse
    {
        $trainer->update(['is_active' => false, 'is_public' => false]);
        $trainer->delete();

        return back()->with('success', 'تم حذف المدربة.');
    }

    public function availability(Request $request, Trainer $trainer): RedirectResponse
    {
        $data = $request->validate([
            'slots' => ['array', 'max:42'],
            'slots.*.weekday' => ['required', 'integer', 'between:0,6'],
            'slots.*.starts_at' => ['required', 'date_format:H:i'],
            'slots.*.ends_at' => ['required', 'date_format:H:i', 'after:slots.*.starts_at'],
        ]);

        DB::transaction(function () use ($trainer, $data) {
            $trainer->availabilities()->delete();
            foreach ($data['slots'] ?? [] as $slot) {
                $trainer->availabilities()->create($slot);
            }
        });

        return back()->with('success', 'تم حفظ أوقات توفر المدربة.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Trainer $trainer = null): array
    {
        $tenantId = app(TenantContext::class)->require()->id;

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'specialties' => ['nullable', 'array', 'max:10'],
            'specialties.*' => ['string', 'max:60'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_public' => ['boolean'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer'],
            'branch_id' => ['nullable', TenantRule::exists('branches')],
            'user_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId), Rule::unique('gym_trainers', 'user_id')->ignore($trainer?->id)],
            'photo' => ['nullable', 'image', 'max:'.config('gym.max_image_kb')],
        ]);
    }
}
