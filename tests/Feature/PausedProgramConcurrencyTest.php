<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSchedule;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\ScheduleService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * #140 at the same moment, in separate processes on PostgreSQL: a program being paused while a member books one of its
 * classes, or while the hourly generator runs. Booking and generating read the program under a share lock, so the pause
 * and they happen one after the other: nothing is booked or made after the pause is saved, and a booking already
 * inside when the pause is saved is kept (it came first).
 */
class PausedProgramConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** The pause, saved one second after it is written: "$dir/paused" says it is written and not saved yet. */
    private function pausing(Tenant $club, Program $program): \Closure
    {
        return fn (string $dir) => $this->asTenant($club, fn () => DB::transaction(function () use ($program, $dir) {
            Program::whereKey($program->id)->update(['is_active' => false]);
            file_put_contents("$dir/paused", '1');
            usleep(1_000_000);

            return 'paused';
        }));
    }

    public function test_a_booking_that_arrives_while_the_pause_is_being_saved_is_refused(): void
    {
        $club = $this->club();
        $zumba = $this->program($club);
        $session = $this->classSession($club, $zumba);
        $member = $this->member($club);
        $this->activeMembership($club, $member);

        $results = $this->concurrently([
            $this->pausing($club, $zumba),
            function (string $dir) use ($club, $member, $session) {
                self::waitFor("$dir/paused");

                return $this->asTenant($club, fn () => app(BookingService::class)->book($member, $session, 'portal')->id);
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($results[1]['ok'], 'the booking went through after the pause: '.json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('هذا البرنامج موقوف', (string) $results[1]['error']);
        $this->asTenant($club, function () use ($session) {
            $this->assertSame(0, Booking::where('class_session_id', $session->id)->count());
            $this->assertSame(0, ClassSession::findOrFail($session->id)->booked_count);
        });
    }

    public function test_a_booking_already_inside_when_the_pause_is_saved_is_kept(): void
    {
        $club = $this->club();
        $zumba = $this->program($club);
        $session = $this->classSession($club, $zumba);
        $member = $this->member($club);
        $this->activeMembership($club, $member);

        $results = $this->concurrently([
            // The member's booking has read the program (share lock held) and is slowed down before it is saved.
            function (string $dir) use ($club, $member, $session) {
                $held = false;
                DB::listen(function ($query) use ($dir, &$held) {
                    if (! $held && str_contains($query->sql, 'from "gym_programs"') && str_contains($query->sql, 'for share')) {
                        $held = true;
                        file_put_contents("$dir/inside", '1');
                        usleep(1_000_000);
                    }
                });

                return $this->asTenant($club, fn () => app(BookingService::class)->book($member, $session, 'portal')->id);
            },
            // Staff pause the program meanwhile: the pause waits for the booking, then is saved.
            function (string $dir) use ($club, $zumba) {
                self::waitFor("$dir/inside");
                $this->asTenant($club, fn () => Program::whereKey($zumba->id)->update(['is_active' => false]));

                return microtime(true);
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($results[1]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->asTenant($club, function () use ($session, $zumba) {
            $this->assertFalse(Program::findOrFail($zumba->id)->is_active);
            $this->assertSame(1, Booking::where('class_session_id', $session->id)->where('status', Booking::BOOKED)->count());
            $this->assertSame(1, ClassSession::findOrFail($session->id)->booked_count);
        });
    }

    public function test_a_cancellation_and_a_new_booking_while_the_pause_is_being_saved_give_back_one_credit_and_book_nothing(): void
    {
        $club = $this->club();
        $zumba = $this->program($club);
        $session = $this->classSession($club, $zumba);
        [$booked, $late] = [$this->member($club), $this->member($club)];
        $membership = $this->activeMembership($club, $booked, $this->plan($club, ['sessions_limit' => 8]));
        $this->activeMembership($club, $late);
        $existing = $this->asTenant($club, fn () => app(BookingService::class)->book($booked, $session, 'portal'));
        $this->assertSame(1, $membership->fresh()->sessions_used);

        $results = $this->concurrently([
            $this->pausing($club, $zumba),
            // The member cancels the booking she has: a paused program's bookings cancel as before.
            function (string $dir) use ($club, $existing) {
                self::waitFor("$dir/paused");

                return $this->asTenant($club, fn () => app(BookingService::class)->cancel($existing, false, 'إلغاء من العضو')->status);
            },
            // Another member books the same class at that moment: she waits for the pause, then is refused.
            function (string $dir) use ($club, $late, $session) {
                self::waitFor("$dir/paused");

                return $this->asTenant($club, fn () => app(BookingService::class)->book($late, $session, 'portal')->id);
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['ok' => true, 'value' => Booking::CANCELLED], array_intersect_key($results[1], ['ok' => 1, 'value' => 1]), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($results[2]['ok'], 'a booking went through on a paused program: '.json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('هذا البرنامج موقوف', (string) $results[2]['error']);
        $this->asTenant($club, function () use ($session, $membership) {
            $this->assertSame(0, Booking::where('class_session_id', $session->id)->where('status', Booking::BOOKED)->count());
            $this->assertSame(1, Booking::where('class_session_id', $session->id)->count(), 'no booking was added');
            $this->assertSame(0, ClassSession::findOrFail($session->id)->booked_count);
            $this->assertSame(0, $membership->fresh()->sessions_used, 'her credit came back once');
        });
    }

    public function test_the_generator_running_while_the_pause_is_being_saved_makes_no_class(): void
    {
        $club = $this->club();
        $zumba = $this->program($club);
        $this->asTenant($club, fn () => ClassSchedule::create([
            'program_id' => $zumba->id, 'weekday' => now()->addDays(2)->dayOfWeek, 'starts_at' => '18:00',
            'duration_minutes' => 60, 'capacity' => 12, 'valid_from' => today(),
        ]));

        $results = $this->concurrently([
            $this->pausing($club, $zumba),
            function (string $dir) use ($club) {
                self::waitFor("$dir/paused");

                return $this->asTenant($club, fn () => app(ScheduleService::class)->generate(3));
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($results[1]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $results[1]['value'], 'classes made for a program paused meanwhile');
        $this->assertSame(0, $this->asTenant($club, fn () => ClassSession::where('program_id', $zumba->id)->count()));
    }
}
