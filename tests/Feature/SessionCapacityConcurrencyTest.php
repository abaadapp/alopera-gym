<?php

namespace Tests\Feature;

use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Services\BookingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * Staff lower a class's capacity while a member books its last seat, in separate processes on PostgreSQL: the edit
 * waits for the booking's lock on the session row and then counts that booking, so a class never ends with more
 * bookings than seats (before, it ended with capacity 1 and 2 booked).
 */
class SessionCapacityConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** @return array<string, list<string>> the messages the request was refused with, by field */
    private static function errors(): array
    {
        $errors = session('errors');

        // In a forked request the flashed bag comes back as its serialised array.
        return $errors instanceof ViewErrorBag ? $errors->getBag('default')->toArray() : (array) data_get($errors, 'default.messages', []);
    }

    public function test_lowering_capacity_while_a_member_books_never_leaves_more_bookings_than_seats(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $session = $this->classSession($club, null, ['capacity' => 2]);
        [$first, $second] = [$this->member($club), $this->member($club)];
        $this->activeMembership($club, $first);
        $this->activeMembership($club, $second);
        $this->asTenant($club, fn () => app(BookingService::class)->book($first, $session));

        $results = $this->concurrently([
            // The member books the last seat, and is slowed down while she holds the session's row lock.
            function (string $dir) use ($club, $second, $session) {
                $held = false;
                DB::listen(function ($query) use ($dir, &$held) {
                    if (! $held && str_contains($query->sql, 'from "gym_class_sessions"') && str_contains($query->sql, 'for update')) {
                        $held = true;
                        file_put_contents("$dir/inside", '1');
                        usleep(1_000_000);
                    }
                });

                return $this->asTenant($club, fn () => app(BookingService::class)->book($second, $session)->id);
            },
            // Meanwhile staff lower the capacity to 1: one booking is all they see on the page.
            function (string $dir) use ($owner, $session) {
                self::waitFor("$dir/inside");
                $response = $this->actingAs($owner)->put(route('club.sessions.update', $session), [
                    'capacity' => 1, 'starts_at' => $session->starts_at->format('Y-m-d H:i'),
                ]);

                return ['status' => $response->getStatusCode(), 'errors' => self::errors()];
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($results[1]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->asTenant($club, function () use ($session, $results) {
            $fresh = ClassSession::findOrFail($session->id);
            $this->assertLessThanOrEqual($fresh->capacity, $fresh->booked_count, 'capacity '.$fresh->capacity.', booked '.$fresh->booked_count);
            $this->assertSame([2, 2], [$fresh->capacity, $fresh->booked_count]);
            $this->assertSame(2, Booking::where('class_session_id', $session->id)->where('status', Booking::BOOKED)->count());
            $this->assertSame(['capacity' => ['السعة أقل من عدد الحجوزات الحالي (2).']], $results[1]['value']['errors'], json_encode($results, JSON_UNESCAPED_UNICODE));
        });
    }
}
