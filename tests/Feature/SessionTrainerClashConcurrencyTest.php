<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSession;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Gym\Models\TrainerAvailability;
use App\Modules\Gym\Services\PtService;
use Carbon\CarbonImmutable;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * One trainer, two bookings of her time at the same moment, in separate processes on PostgreSQL: two one-off classes,
 * or a class and a personal-training session. The first is slowed down after it saves, before it commits; the second
 * waits for the trainer's lock (the one PtService::schedule takes), then sees the first and is refused.
 */
class SessionTrainerClashConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** @return array{0: Tenant, 1: User, 2: Program, 3: Trainer} */
    private function gym(): array
    {
        $club = $this->club();
        $program = $this->program($club);
        $trainer = $this->asTenant($club, function () {
            $trainer = Trainer::create(['name' => 'Coach']);
            foreach (range(0, 6) as $day) {
                TrainerAvailability::create(['trainer_id' => $trainer->id, 'weekday' => $day, 'starts_at' => '06:00', 'ends_at' => '23:00']);
            }

            return $trainer;
        });

        return [$club, $this->owner($club), $program, $trainer];
    }

    /** @return array{status: int, errors: array<string, list<string>>} */
    private function createClass(User $owner, Program $program, Trainer $trainer, CarbonImmutable $at): array
    {
        $response = $this->actingAs($owner)->post(route('club.sessions.store'), [
            'program_id' => $program->id, 'trainer_id' => $trainer->id, 'starts_at' => $at->format('Y-m-d H:i'),
            'duration_minutes' => 60, 'capacity' => 10,
        ]);
        $errors = session('errors');

        // In a forked request the flashed bag comes back as its serialised array.
        return ['status' => $response->getStatusCode(), 'errors' => $errors instanceof ViewErrorBag
            ? $errors->getBag('default')->toArray() : (array) data_get($errors, 'default.messages', [])];
    }

    private function package(Tenant $club, Trainer $trainer): int
    {
        return $this->asTenant($club, function () use ($club, $trainer) {
            $package = PtPackage::create(['name' => 'PT', 'sessions_count' => 4, 'session_minutes' => 60, 'price' => 40, 'validity_days' => 60]);
            $subscription = app(PtService::class)->sell($this->member($club), $package, $trainer);
            app(PaymentService::class)->record($subscription->invoice()->first(), $subscription->price, 'cash');

            return $subscription->id;
        });
    }

    public function test_two_one_off_classes_for_one_trainer_at_once_are_not_both_made(): void
    {
        [$club, $owner, $program, $trainer] = $this->gym();
        $ten = CarbonImmutable::tomorrow()->setTime(10, 0);

        $results = $this->concurrently([
            function (string $dir) use ($owner, $program, $trainer, $ten) {
                self::pauseAfter('insert into "gym_class_sessions"', $dir, 1.0);

                return $this->createClass($owner, $program, $trainer, $ten);
            },
            function (string $dir) use ($owner, $program, $trainer, $ten) {
                self::waitFor("$dir/inside");

                return $this->createClass($owner, $program, $trainer, $ten->addMinutes(30));
            },
        ]);

        $this->assertSame(1, $this->asTenant($club, fn () => ClassSession::where('trainer_id', $trainer->id)->count()), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $results[0]['value']['errors'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('المدرب لديه حصة أخرى في هذا الوقت', json_encode($results[1]['value']['errors']['trainer_id'] ?? [], JSON_UNESCAPED_UNICODE));
    }

    public function test_a_class_made_while_a_pt_session_is_being_booked_for_the_same_trainer_is_refused(): void
    {
        [$club, $owner, $program, $trainer] = $this->gym();
        $subscription = $this->package($club, $trainer);
        $ten = CarbonImmutable::tomorrow()->setTime(10, 0);

        $results = $this->concurrently([
            function (string $dir) use ($club, $subscription, $ten) {
                self::pauseAfter('insert into "gym_pt_sessions"', $dir, 1.0);

                return $this->asTenant($club, fn () => app(PtService::class)->schedule(PtSubscription::findOrFail($subscription), $ten)->id);
            },
            function (string $dir) use ($owner, $program, $trainer, $ten) {
                self::waitFor("$dir/inside");

                return $this->createClass($owner, $program, $trainer, $ten->addMinutes(30));
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $this->asTenant($club, fn () => ClassSession::count()), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('المدرب لديه جلسة تدريب شخصي في هذا الوقت', json_encode($results[1]['value']['errors']['trainer_id'] ?? [], JSON_UNESCAPED_UNICODE));
    }

    public function test_a_pt_session_booked_while_a_class_is_being_made_for_the_same_trainer_is_refused(): void
    {
        [$club, $owner, $program, $trainer] = $this->gym();
        $subscription = $this->package($club, $trainer);
        $ten = CarbonImmutable::tomorrow()->setTime(10, 0);

        $results = $this->concurrently([
            function (string $dir) use ($owner, $program, $trainer, $ten) {
                self::pauseAfter('insert into "gym_class_sessions"', $dir, 1.0);

                return $this->createClass($owner, $program, $trainer, $ten);
            },
            function (string $dir) use ($club, $subscription, $ten) {
                self::waitFor("$dir/inside");

                return $this->asTenant($club, fn () => app(PtService::class)->schedule(PtSubscription::findOrFail($subscription), $ten->addMinutes(30))->id);
            },
        ]);

        $this->assertSame([], $results[0]['value']['errors'] ?? ['no answer'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $this->asTenant($club, fn () => PtSession::count()), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('المدرب لديه موعد آخر في هذا الوقت', (string) $results[1]['error']);
    }
}
