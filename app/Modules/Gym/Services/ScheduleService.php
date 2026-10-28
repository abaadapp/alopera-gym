<?php

namespace App\Modules\Gym\Services;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantContext;
use App\Modules\Gym\Models\ClassSchedule;
use App\Modules\Gym\Models\ClassSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns the weekly timetable into dated class sessions and keeps them in sync.
 */
final class ScheduleService
{
    public function __construct(private readonly TenantContext $tenants)
    {
    }

    /** Refuse a slot that double-books the trainer on the same weekday. */
    public function assertNoTrainerClash(array $data, ?int $ignoreId = null): void
    {
        if (empty($data['trainer_id'])) {
            return;
        }

        $start = CarbonImmutable::parse('2000-01-01 '.$data['starts_at']);
        $end = $start->addMinutes((int) $data['duration_minutes']);

        $clash = ClassSchedule::where('trainer_id', $data['trainer_id'])
            ->where('weekday', $data['weekday'])
            ->where('is_active', true)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()
            ->first(function (ClassSchedule $s) use ($start, $end) {
                $sStart = CarbonImmutable::parse('2000-01-01 '.$s->starts_at);
                $sEnd = $sStart->addMinutes($s->duration_minutes);

                return $sStart->lt($end) && $sEnd->gt($start);
            });

        if ($clash) {
            throw new BusinessRuleException('schedule.trainer_clash', 'المدربة لديها حصة أخرى في نفس الوقت.', 'trainer_id');
        }
    }

    /**
     * Create the missing sessions from today to N weeks ahead. Idempotent:
     * running it twice creates nothing new.
     */
    public function generate(?int $weeks = null, ?CarbonImmutable $from = null): int
    {
        $tenant = $this->tenants->require();
        $weeks ??= (int) $tenant->setting('class_session_weeks_ahead', 4);
        $from ??= CarbonImmutable::today();
        $until = $from->addWeeks($weeks);
        $created = 0;

        $schedules = ClassSchedule::where('is_active', true)
            ->where('valid_from', '<=', $until)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $from))
            ->get();

        foreach ($schedules as $schedule) {
            $created += $this->generateFor($schedule, $from, $until);
        }

        return $created;
    }

    public function generateFor(ClassSchedule $schedule, CarbonImmutable $from, CarbonImmutable $until): int
    {
        $created = 0;
        $date = $from->max($schedule->valid_from);
        $last = $schedule->valid_until ? $until->min($schedule->valid_until) : $until;

        while ($date->lte($last)) {
            if ($date->dayOfWeek === (int) $schedule->weekday) {
                $startsAt = CarbonImmutable::parse($date->toDateString().' '.$schedule->starts_at);

                if ($startsAt->isFuture()) {
                    $session = ClassSession::firstOrCreate(
                        ['class_schedule_id' => $schedule->id, 'starts_at' => $startsAt],
                        [
                            'branch_id' => $schedule->branch_id,
                            'program_id' => $schedule->program_id,
                            'trainer_id' => $schedule->trainer_id,
                            'ends_at' => $startsAt->addMinutes($schedule->duration_minutes),
                            'capacity' => $schedule->capacity,
                            'room' => $schedule->room,
                            'status' => ClassSession::SCHEDULED,
                        ]
                    );
                    $created += $session->wasRecentlyCreated ? 1 : 0;
                }
            }
            $date = $date->addDay();
        }

        return $created;
    }

    /**
     * After the timetable slot changes: future sessions without bookings are
     * rebuilt; sessions with bookings keep their time but take the new trainer,
     * room and (never smaller than what is booked) capacity.
     */
    public function sync(ClassSchedule $schedule): void
    {
        DB::transaction(function () use ($schedule) {
            $future = ClassSession::where('class_schedule_id', $schedule->id)
                ->where('status', ClassSession::SCHEDULED)
                ->where('starts_at', '>', now())
                ->lockForUpdate()
                ->get();

            foreach ($future as $session) {
                if ($session->booked_count === 0) {
                    $session->delete();

                    continue;
                }

                $session->forceFill([
                    'trainer_id' => $schedule->trainer_id,
                    'room' => $schedule->room,
                    'capacity' => max($schedule->capacity, $session->booked_count),
                ])->save();
            }

            if ($schedule->is_active) {
                $this->generateFor(
                    $schedule,
                    CarbonImmutable::today(),
                    CarbonImmutable::today()->addWeeks((int) $this->tenants->require()->setting('class_session_weeks_ahead', 4))
                );
            }
        });
    }
}
