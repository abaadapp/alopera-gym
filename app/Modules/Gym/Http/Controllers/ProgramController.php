<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Support\Images\ImageRule;
use App\Core\Support\Images\ImageUpload;
use App\Core\Support\ImageStore;
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
                'id', 'name', 'name_en', 'slug', 'category', 'level', 'description', 'description_en', 'color', 'duration_minutes', 'default_capacity', 'is_public', 'is_active', 'sort', 'schedules_count'
            ) + ['image_url' => $p->imageUrl(), 'image_download_url' => $p->image_path ? route('club.programs.image', $p) : null]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->slug($data['name']);
        $program = new Program(collect($data)->except(['image', 'image_preview', 'image_direct'])->all());
        $image = ImageUpload::from($request, 'image');
        if (! $image) {
            $program->save();
        } else {
            ImageStore::commit(null, ImageStore::store($image, 'programs'), function (string $path) use ($program) {
                $program->image_path = $path;
                $program->save();
            });
        }

        return back()->with('success', 'تمت إضافة البرنامج.');
    }

    /** The image as it was uploaded, for download. */
    public function image(Program $program): \Symfony\Component\HttpFoundation\Response
    {
        return ImageStore::download($program->image_path, $program->name);
    }

    public function update(Request $request, Program $program): RedirectResponse
    {
        $data = $this->validated($request, $program);
        $program->fill(collect($data)->except(['image', 'image_preview', 'image_direct'])->all());
        $image = ImageUpload::from($request, 'image');
        if (! $image) {
            $program->save();
        } else {
            // The new image is stored and saved first; the old files go only after that worked. A refused image
            // shows its reason on the field and saves nothing.
            ImageStore::replace($program->image_path, $image, 'programs', 1600, function (string $path) use ($program) {
                $program->image_path = $path;
                $program->save();
            });
        }

        return back()->with('success', 'تم حفظ البرنامج.');
    }

    public function destroy(Program $program): RedirectResponse
    {
        if ($program->schedules()->where('is_active', true)->exists()) {
            return back()->with('error', 'البرنامج مستخدم في الجدول الأسبوعي. أوقف حصصه أولًا.');
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
            'name_en' => ['nullable', 'string', 'max:120'],
            'description_en' => ['nullable', 'string', 'max:3000'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'duration_minutes' => ['required', 'integer', 'min:10', 'max:300'],
            'default_capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'is_public' => ['boolean'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer'],
            'image' => ImageRule::rules(),
            'image_preview' => ImageRule::previewRules(),
            'image_direct' => ImageRule::ticketRules(),
        ]);
    }
}
