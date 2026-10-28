<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Events\ClassSessionChanged;
use App\Modules\Gym\Http\GymPresenter;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Dated class sessions: week view, roster, one-off classes, cancellation. */
class SessionController extends Controller
{
    public function index(Request $request): Response
    {
        $weekStart = CarbonImmutable::parse($request->string('week')->toString() ?: 'today')->startOfWeek(CarbonImmutable::SATURDAY);

        return Inertia::render('Gym/Sessions/Index', [
            'week' => $weekStart->toDateString(),
            'sessions' => ClassSession::with(['program:id,name,color', 'trainer:id,name'])
                ->between($weekStart, $weekStart->addWeek())
                ->when($request->integer('trainer_id'), fn ($q, $id) => $q->where('trainer_id', $id))
                ->when($request->integer('program_id'), fn ($q, $id) => $q->where('program_id', $id))
                ->orderBy('starts_at')->get()->map(fn ($s) => GymPresenter::session($s)),
            'programs' => Program::where('is_active', true)->orderBy('name')->get(['id', 'name', 'color', 'duration_minutes', 'default_capacity']),
            'trainers' => Trainer::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('trainer_id', 'program_id'),
        ]);
    }

    public function show(ClassSession $session): Response
    {
        $session->load(['program:id,name,color', 'trainer:id,name']);

        return Inertia::render('Gym/Sessions/Show', [
            'session' => GymPresenter::session($session) + ['cancel_reason' => $session->cancel_reason],
            'bookings' => Booking::with('member:id,full_name,member_no,phone,photo_path')->where('class_session_id', $session->id)
                ->orderByRaw("case status when 'cancelled' then 1 else 0 end")->orderBy('id')->get()
                ->map(fn (Booking $b) => $b->only('id', 'status', 'source', 'consumed_session', 'cancel_reason') + [
                    'member' => $b->member?->only('id', 'full_name', 'member_no', 'phone'),
                    'attended_at' => $b->attended_at?->format('H:i'), 'created_at' => $b->created_at->format('Y-m-d H:i'),
                ]),
            'trainers' => Trainer::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** One-off session outside the weekly timetable (workshop, extra class). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'program_id' => ['required', TenantRule::exists('gym_programs')],
            'trainer_id' => ['nullable', TenantRule::exists('gym_trainers')],
            'starts_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['required', 'integer', 'min:10', 'max:300'],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'room' => ['nullable', 'string', 'max:60'],
        ]);
        $start = CarbonImmutable::parse($data['starts_at']);

        ClassSession::create([
            'program_id' => $data['program_id'],
            'trainer_id' => $data['trainer_id'] ?? null,
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($data['duration_minutes']),
            'capacity' => $data['capacity'],
            'room' => $data['room'] ?? null,
        ]);

        return back()->with('success', 'تمت إضافة الحصة.');
    }

    public function generate(ScheduleService $schedule): RedirectResponse
    {
        $count = $schedule->generate();

        return back()->with('success', "تم توليد {$count} حصة قادمة من الجدول الأسبوعي.");
    }

    public function cancel(Request $request, ClassSession $session, BookingService $bookings): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $bookings->cancelSession($session, $data['reason']);

        return back()->with('success', 'تم إلغاء الحصة وإبلاغ المحجوزات واستعادة حصصهن.');
    }

    /** Change trainer, room, capacity or time of a single session; booked members are notified. */
    public function update(Request $request, ClassSession $session): RedirectResponse
    {
        $data = $request->validate([
            'trainer_id' => ['nullable', TenantRule::exists('gym_trainers')],
            'room' => ['nullable', 'string', 'max:60'],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'starts_at' => ['required', 'date', 'after:now'],
        ]);

        if ($session->status !== ClassSession::SCHEDULED) {
            throw new BusinessRuleException('session.closed', 'لا يمكن تعديل حصة ملغاة أو منتهية.');
        }
        if ($data['capacity'] < $session->booked_count) {
            throw new BusinessRuleException('session.capacity', 'السعة أقل من عدد المحجوزات الحالي ('.$session->booked_count.').', 'capacity');
        }

        $start = CarbonImmutable::parse($data['starts_at']);
        $duration = (int) $session->starts_at->diffInMinutes($session->ends_at);
        $changes = [];
        if (! $start->equalTo($session->starts_at)) {
            $changes[] = 'الموعد الجديد '.$start->format('Y/m/d H:i');
        }
        if ((int) ($data['trainer_id'] ?? 0) !== (int) $session->trainer_id) {
            $changes[] = 'المدربة: '.(Trainer::find($data['trainer_id'])?->name ?? '—');
        }
        if (($data['room'] ?? null) !== $session->room && ! empty($data['room'])) {
            $changes[] = 'القاعة: '.$data['room'];
        }

        $session->forceFill([
            'trainer_id' => $data['trainer_id'] ?? null,
            'room' => $data['room'] ?? null,
            'capacity' => $data['capacity'],
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($duration),
        ])->save();

        if ($changes && app(\App\Core\Tenancy\TenantContext::class)->require()->setting('notify_class_changes', true)) {
            ClassSessionChanged::dispatch($session, implode('، ', $changes));
        }

        return back()->with('success', 'تم تعديل الحصة'.($changes ? ' وإبلاغ المحجوزات.' : '.'));
    }
}
