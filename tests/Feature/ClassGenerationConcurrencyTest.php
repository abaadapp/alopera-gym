<?php

namespace Tests\Feature;

use App\Modules\Gym\Models\ClassSchedule;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Services\ScheduleService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * The hourly timetable run and the "generate" button at the same moment, in separate processes on PostgreSQL: both
 * find the same slot free, then both make it. The unique slot index keeps one class; the run that loses the race
 * counts nothing for that slot instead of failing with a duplicate-key error.
 */
class ClassGenerationConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** Inside a task: once past the first "is this slot taken?" check, wait until the other run is past it too. */
    private static function meetAfterTheFirstCheck(string $dir, string $me, string $other): void
    {
        $met = false;
        DB::listen(function ($query) use ($dir, $me, $other, &$met) {
            if (! $met && str_starts_with($query->sql, 'select exists') && str_contains($query->sql, '"gym_class_sessions"')) {
                $met = true;
                file_put_contents("$dir/$me", '1');
                self::waitFor("$dir/$other");
            }
        });
    }

    public function test_two_runs_making_the_same_classes_at_once_make_each_class_once_and_both_finish(): void
    {
        $club = $this->club();
        $program = $this->program($club);
        $this->asTenant($club, fn () => ClassSchedule::create([
            'program_id' => $program->id, 'weekday' => now()->addDays(2)->dayOfWeek, 'starts_at' => '18:00',
            'duration_minutes' => 60, 'capacity' => 12, 'valid_from' => today(),
        ]));

        $run = fn (string $me, string $other) => function (string $dir) use ($club, $me, $other) {
            self::meetAfterTheFirstCheck($dir, $me, $other);

            return $this->asTenant($club, fn () => app(ScheduleService::class)->generate(2));
        };
        $results = $this->concurrently([$run('hourly', 'button'), $run('button', 'hourly')]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], (string) $result['error']);
        }
        $this->asTenant($club, function () use ($results) {
            $made = ClassSession::count();
            $this->assertGreaterThanOrEqual(2, $made);
            $this->assertSame($made, ClassSession::distinct()->count('slot_starts_at'), 'each slot once');
            $this->assertSame($made, $results[0]['value'] + $results[1]['value'], 'each class counted by the run that made it');
        });
    }
}
