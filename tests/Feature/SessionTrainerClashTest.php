<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSession;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Gym\Models\TrainerAvailability;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\PtService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A one-off class, a class moved to another time and a class given to another trainer all need that trainer free:
 * no other scheduled class of hers and no scheduled personal-training session overlapping it. Back to back is fine,
 * cancelled ones do not count, a class without a trainer never clashes.
 */
class SessionTrainerClashTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private Tenant $gym;

    private User $owner;

    private Program $zumba;

    private Trainer $coach;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gym = $this->club();
        $this->owner = $this->owner($this->gym);
        $this->zumba = $this->program($this->gym);
        $this->coach = $this->coach('Coach');
    }

    private function coach(string $name): Trainer
    {
        return $this->asTenant($this->gym, function () use ($name) {
            $trainer = Trainer::create(['name' => $name]);
            foreach (range(0, 6) as $day) {
                TrainerAvailability::create(['trainer_id' => $trainer->id, 'weekday' => $day, 'starts_at' => '06:00', 'ends_at' => '23:00']);
            }

            return $trainer;
        });
    }

    private static function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::tomorrow()->setTimeFromTimeString($time);
    }

    private function classAt(string $time, ?Trainer $trainer, int $minutes = 60): ClassSession
    {
        return $this->classSession($this->gym, $this->zumba, ['starts_at' => self::at($time), 'minutes' => $minutes, 'trainer_id' => $trainer?->id]);
    }

    private function ptAt(string $time, Trainer $trainer): PtSession
    {
        return $this->asTenant($this->gym, function () use ($time, $trainer) {
            $package = PtPackage::create(['name' => 'PT', 'sessions_count' => 4, 'session_minutes' => 60, 'price' => 40, 'validity_days' => 60]);
            $subscription = app(PtService::class)->sell($this->member($this->gym), $package, $trainer);
            app(PaymentService::class)->record($subscription->invoice()->first(), $subscription->price, 'cash');

            return app(PtService::class)->schedule($subscription->fresh(), self::at($time));
        });
    }

    private function create(string $time, ?Trainer $trainer, int $minutes = 60): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('club.sessions.store'), [
            'program_id' => $this->zumba->id, 'trainer_id' => $trainer?->id, 'starts_at' => self::at($time)->format('Y-m-d H:i'),
            'duration_minutes' => $minutes, 'capacity' => 10,
        ]);
    }

    private function edit(ClassSession $session, string $time, ?Trainer $trainer, ?string $room = null): TestResponse
    {
        return $this->actingAs($this->owner)->put(route('club.sessions.update', $session), [
            'trainer_id' => $trainer?->id, 'starts_at' => self::at($time)->format('Y-m-d H:i'), 'capacity' => 10, 'room' => $room,
        ]);
    }

    private function sessions(): int
    {
        return $this->asTenant($this->gym, fn () => ClassSession::count());
    }

    public function test_a_one_off_class_overlapping_another_class_of_the_trainer_is_refused(): void
    {
        $this->classAt('18:00', $this->coach);

        $this->create('18:30', $this->coach)->assertSessionHasErrors(['trainer_id' => 'المدرب لديه حصة أخرى في هذا الوقت: Zumba '.self::at('18:00')->format('Y/m/d').' 18:00–19:00.']);
        $this->create('17:30', $this->coach, 90)->assertSessionHasErrors('trainer_id');
        $this->assertSame(1, $this->sessions());

        // Another trainer at that hour is free.
        $this->create('18:30', $this->coach('Other'))->assertSessionHasNoErrors();
        $this->assertSame(2, $this->sessions());
    }

    public function test_a_one_off_class_overlapping_a_personal_training_session_is_refused(): void
    {
        $this->ptAt('10:00', $this->coach);

        $this->create('10:30', $this->coach)->assertSessionHasErrors(['trainer_id' => 'المدرب لديه جلسة تدريب شخصي في هذا الوقت: '.self::at('10:00')->format('Y/m/d').' 10:00–11:00.']);
        $this->assertSame(0, $this->sessions());
    }

    public function test_cancelled_classes_and_cancelled_pt_sessions_do_not_clash(): void
    {
        $class = $this->classAt('18:00', $this->coach);
        $pt = $this->ptAt('10:00', $this->coach);
        $this->asTenant($this->gym, function () use ($class, $pt) {
            app(BookingService::class)->cancelSession($class, 'المدرب مريض');
            app(PtService::class)->close($pt, PtSession::CANCELLED, 'اعتذار');
        });

        $this->create('18:00', $this->coach)->assertSessionHasNoErrors();
        $this->create('10:00', $this->coach)->assertSessionHasNoErrors();
        $this->assertSame(3, $this->sessions());
    }

    public function test_back_to_back_with_a_class_or_a_pt_session_is_allowed(): void
    {
        $this->classAt('18:00', $this->coach);
        $this->ptAt('10:00', $this->coach);

        $this->create('19:00', $this->coach)->assertSessionHasNoErrors(); // starts as the class ends
        $this->create('17:00', $this->coach)->assertSessionHasNoErrors(); // ends as the class starts
        $this->create('11:00', $this->coach)->assertSessionHasNoErrors(); // starts as the PT session ends
        $this->create('09:00', $this->coach)->assertSessionHasNoErrors(); // ends as the PT session starts
        $this->assertSame(5, $this->sessions());
    }

    public function test_a_class_without_a_trainer_never_clashes(): void
    {
        $this->classAt('18:00', $this->coach);
        $this->classAt('20:00', null);

        $this->create('18:00', null)->assertSessionHasNoErrors();
        $this->create('20:00', $this->coach)->assertSessionHasNoErrors();
        $this->assertSame(4, $this->sessions());
    }

    public function test_moving_a_class_onto_another_class_of_its_trainer_is_refused_and_nothing_changes(): void
    {
        $first = $this->classAt('18:00', $this->coach);
        $second = $this->classAt('20:00', $this->coach);

        $this->edit($second, '18:30', $this->coach)->assertSessionHasErrors(['starts_at' => 'المدرب لديه حصة أخرى في هذا الوقت: Zumba '.self::at('18:00')->format('Y/m/d').' 18:00–19:00.']);
        $this->assertEquals(self::at('20:00'), $this->asTenant($this->gym, fn () => $second->fresh()->starts_at));

        $this->edit($second, '19:00', $this->coach)->assertSessionHasNoErrors(); // back to back
        $this->edit($first, '17:30', $this->coach)->assertSessionHasNoErrors(); // over its own old time only
        $this->assertSame(['17:30', '19:00'], $this->asTenant($this->gym, fn () => ClassSession::orderBy('starts_at')->get()->map->starts_at->map->format('H:i')->all()));
    }

    public function test_moving_a_class_onto_a_pt_session_of_its_trainer_is_refused(): void
    {
        $class = $this->classAt('18:00', $this->coach);
        $this->ptAt('10:00', $this->coach);

        $this->edit($class, '10:30', $this->coach)->assertSessionHasErrors(['starts_at' => 'المدرب لديه جلسة تدريب شخصي في هذا الوقت: '.self::at('10:00')->format('Y/m/d').' 10:00–11:00.']);
        $this->assertEquals(self::at('18:00'), $this->asTenant($this->gym, fn () => $class->fresh()->starts_at));
    }

    public function test_giving_a_class_to_a_trainer_busy_at_that_time_is_refused(): void
    {
        $other = $this->coach('Other');
        $this->classAt('18:00', $this->coach);
        $theirs = $this->classAt('18:30', $other);
        $free = $this->classAt('12:00', $other);
        $this->ptAt('15:00', $this->coach);
        $atPt = $this->classAt('15:00', $other);

        $this->edit($theirs, '18:30', $this->coach)->assertSessionHasErrors('trainer_id');
        $this->edit($atPt, '15:00', $this->coach)->assertSessionHasErrors('trainer_id');
        $this->assertSame([$other->id, $other->id], $this->asTenant($this->gym, fn () => [$theirs->fresh()->trainer_id, $atPt->fresh()->trainer_id]));

        $this->edit($free, '12:00', $this->coach)->assertSessionHasNoErrors();
        $this->assertSame($this->coach->id, $this->asTenant($this->gym, fn () => $free->fresh()->trainer_id));
    }

    public function test_an_edit_that_keeps_the_time_and_the_trainer_is_not_held_by_an_older_clash(): void
    {
        // Two overlapping classes made before this rule (or by the weekly timetable): the room and capacity still change.
        $this->classAt('18:00', $this->coach);
        $older = $this->classAt('18:30', $this->coach);

        $this->edit($older, '18:30', $this->coach, 'Hall B')->assertSessionHasNoErrors();
        $this->assertSame('Hall B', $this->asTenant($this->gym, fn () => $older->fresh()->room));
    }
}
