<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Support\ImageStore;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Models\Program;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProgramController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Gym/Programs/Index', [
            'programs' => Program::withCount('schedules')->orderBy('sort')->orderBy('name')->get()->map(fn (Program $p) => $p->only(
                'id', 'name', 'slug', 'category', 'level', 'description', 'color', 'duration_minutes', 'default_capacity', 'is_public', 'is_active', 'sort', 'schedules_count'
            ) + ['image_url' => $p->imageUrl()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->slug($data['name']);
        $program = new Program(collect($data)->except('image')->all());
        if ($request->hasFile('image')) {
            $program->image_path = ImageStore::store($request->file('image'), 'programs');
        }
        $program->save();

        return back()->with('success', 'تمت إضافة البرنامج.');
    }

    public function update(Request $request, Program $program): RedirectResponse
    {
        $data = $this->validated($request, $program);
        $program->fill(collect($data)->except('image')->all());
        if ($request->hasFile('image')) {
            ImageStore::delete($program->image_path);
            $program->image_path = ImageStore::store($request->file('image'), 'programs');
        }
        $program->save();

        return back()->with('success', 'تم حفظ البرنامج.');
    }

    public function destroy(Program $program): RedirectResponse
    {
        if ($program->schedules()->where('is_active', true)->exists()) {
            return back()->with('error', 'البرنامج مستخدم في الجدول الأسبوعي. أوقفي حصصه أولًا.');
        }
        $program->update(['is_active' => false]);
        $program->delete();

        return back()->with('success', 'تم حذف البرنامج.');
    }

    private function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'program';
        $slug = $base;
        $i = 2;
        while (Program::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Program $program = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'in:class,program,pt'],
            'level' => ['required', 'in:'.implode(',', Program::LEVELS)],
            'description' => ['nullable', 'string', 'max:3000'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'duration_minutes' => ['required', 'integer', 'min:10', 'max:300'],
            'default_capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'is_public' => ['boolean'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer'],
            'image' => ['nullable', 'image', 'max:'.config('gym.max_image_kb')],
        ]);
    }
}
