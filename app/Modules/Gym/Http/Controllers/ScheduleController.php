<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Gym\Models\ClassSchedule;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Gym\Services\ScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The weekly class timetable. */
class ScheduleController extends Controller
{
    public function __construct(private readonly ScheduleService $schedule)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Gym/Schedule/Index', [
            'slots' => ClassSchedule::with(['program:id,name,color', 'trainer:id,name'])->orderBy('weekday')->orderBy('starts_at')->get()
                ->map(fn (ClassSchedule $s) => $s->only('id', 'weekday', 'duration_minutes', 'capacity', 'room', 'is_active', 'program_id', 'trainer_id', 'branch_id') + [
                    'starts_at' => substr($s->starts_at, 0, 5),
                    'program' => $s->program?->name, 'color' => $s->program?->color, 'trainer' => $s->trainer?->name,
                    'valid_from' => $s->valid_from->toDateString(), 'valid_until' => $s->valid_until?->toDateString(),
                ]),
            'programs' => Program::where('is_active', true)->orderBy('name')->get(['id', 'name', 'color', 'duration_minutes', 'default_capacity']),
            'trainers' => Trainer::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->schedule->assertNoTrainerClash($data);
        $slot = ClassSchedule::create($data);
        $this->schedule->sync($slot);

        return back()->with('success', 'تمت إضافة الحصة للجدول وتوليد مواعيدها القادمة.');
    }

    public function update(Request $request, ClassSchedule $schedule): RedirectResponse
    {
        $data = $this->validated($request);
        $this->schedule->assertNoTrainerClash($data, $schedule->id);
        $schedule->update($data);
        $this->schedule->sync($schedule);

        return back()->with('success', 'تم تحديث الجدول. الحصص المحجوزة احتفظت بموعدها.');
    }

    public function destroy(ClassSchedule $schedule): RedirectResponse
    {
        $schedule->update(['is_active' => false]);
        $this->schedule->sync($schedule);

        return back()->with('success', 'تم إيقاف الحصة. المواعيد غير المحجوزة حُذفت؛ المحجوزة تبقى حتى تُلغى يدويًا.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'program_id' => ['required', TenantRule::exists('gym_programs')],
            'trainer_id' => ['nullable', TenantRule::exists('gym_trainers')],
            'branch_id' => ['nullable', TenantRule::exists('branches')],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'starts_at' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:10', 'max:300'],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'room' => ['nullable', 'string', 'max:60'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['boolean'],
        ]);
        $data['valid_from'] ??= today()->toDateString();

        return $data;
    }
}
