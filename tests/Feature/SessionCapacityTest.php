<?php

namespace Tests\Feature;

use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Editing a single class session never sets its capacity below the bookings it already has. */
class SessionCapacityTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_capacity_below_the_confirmed_bookings_is_refused_and_the_bookings_stay(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $session = $this->classSession($club, null, ['capacity' => 3]);
        $this->asTenant($club, function () use ($club, $session) {
            foreach ([$this->member($club), $this->member($club)] as $member) {
                $this->activeMembership($club, $member);
                app(BookingService::class)->book($member, $session);
            }
        });
        $form = fn (int $capacity) => ['capacity' => $capacity, 'starts_at' => $session->starts_at->format('Y-m-d H:i')];

        $this->actingAs($owner)->put(route('club.sessions.update', $session), $form(1))
            ->assertSessionHasErrors(['capacity' => 'السعة أقل من عدد الحجوزات الحالي (2).']);
        $this->asTenant($club, function () use ($session) {
            $this->assertSame([3, 2], [$session->fresh()->capacity, $session->fresh()->booked_count]);
            $this->assertSame(2, Booking::where('class_session_id', $session->id)->where('status', Booking::BOOKED)->count());
        });

        // As many seats as bookings is allowed.
        $this->actingAs($owner)->put(route('club.sessions.update', $session), $form(2))->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame([2, 2], [$session->fresh()->capacity, $session->fresh()->booked_count]));
    }
}
