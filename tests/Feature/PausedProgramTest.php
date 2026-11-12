<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSchedule;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * #140, the owner's decision: a paused program gets no new class (the hourly generator, a timetable save, a one-off
 * class) and takes no new booking (website, portal, desk). What it already has stays as it is: its classes, their
 * bookings, attendance, membership credits and invoices. Reactivated, it goes on from its free slots without making a
 * class twice. Other programs and other clubs are not touched.
 */
class PausedProgramTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function weekly(Tenant $club, Program $program, int $inDays = 2): ClassSchedule
    {
        return $this->asTenant($club, fn () => ClassSchedule::create([
            'program_id' => $program->id, 'weekday' => now()->addDays($inDays)->dayOfWeek, 'starts_at' => '18:00',
            'duration_minutes' => 60, 'capacity' => 12, 'valid_from' => today(),
        ]));
    }

    private function generate(Tenant $club, int $weeks = 3): int
    {
        return $this->asTenant($club, fn () => app(ScheduleService::class)->generate($weeks));
    }

    private function classesOf(Tenant $club, Program $program): int
    {
        return $this->asTenant($club, fn () => ClassSession::where('program_id', $program->id)->count());
    }

    private function pause(Tenant $club, Program $program, bool $active = false): void
    {
        $this->asTenant($club, fn () => $program->update(['is_active' => $active]));
    }

    private function bookingRefused(Tenant $club, Member $member, ClassSession $session, string $source): string
    {
        try {
            $this->asTenant($club, fn () => app(BookingService::class)->book($member, $session, $source));
        } catch (BusinessRuleException $e) {
            return $e->rule;
        }
        $this->fail("a $source booking on a paused program's class was accepted");
    }

    public function test_the_generator_makes_no_class_for_a_paused_program_and_the_others_go_on(): void
    {
        $club = $this->club();
        [$zumba, $yoga] = [$this->program($club), $this->program($club, ['name' => 'Yoga'])];
        $this->weekly($club, $zumba);
        $this->weekly($club, $yoga);
        $this->pause($club, $zumba);

        $this->assertGreaterThan(0, $this->generate($club));
        $this->assertSame(0, $this->classesOf($club, $zumba));
        $this->assertGreaterThan(0, $this->classesOf($club, $yoga), 'an active program is not touched');
    }

    public function test_saving_a_paused_programs_timetable_makes_no_class(): void
    {
        $club = $this->club();
        $zumba = $this->program($club);
        $this->pause($club, $zumba);
        $schedule = $this->weekly($club, $zumba);

        $this->asTenant($club, fn () => app(ScheduleService::class)->sync($schedule));

        $this->assertSame(0, $this->classesOf($club, $zumba));
    }

    public function test_reactivated_it_goes_on_from_its_free_slots_and_makes_no_class_twice(): void
    {
        $club = $this->club();
        $zumba = $this->program($club);
        $this->weekly($club, $zumba);
        $this->assertGreaterThan(0, $before = $this->generate($club, 1));

        $this->pause($club, $zumba);
        $this->assertSame(0, $this->generate($club, 3));
        $this->assertSame($before, $this->classesOf($club, $zumba), 'the classes made before the pause stay');

        $this->pause($club, $zumba, active: true);
        $more = $this->generate($club, 3);
        $this->assertGreaterThan(0, $more);
        $this->assertSame(0, $this->generate($club, 3), 'a second run makes nothing new');
        $this->assertSame($before + $more, $this->classesOf($club, $zumba));
        $slots = $this->asTenant($club, fn () => ClassSession::where('program_id', $zumba->id)->pluck('slot_starts_at')->map(fn ($s) => (string) $s));
        $this->assertSame($slots->count(), $slots->unique()->count(), 'no slot was made twice');
    }

    public function test_no_new_booking_from_the_portal_the_website_or_the_desk_but_existing_ones_stay(): void
    {
        $club = $this->club();
        $zumba = $this->program($club);
        $session = $this->classSession($club, $zumba);
        [$booked, $late] = [$this->member($club), $this->member($club)];
        $membership = $this->activeMembership($club, $booked, $this->plan($club, ['sessions_limit' => 8]));
        $this->activeMembership($club, $late);
        $kept = $this->asTenant($club, fn () => app(BookingService::class)->book($booked, $session, 'portal'));
        $usedBefore = $membership->fresh()->sessions_used;

        $this->pause($club, $zumba);

        foreach (['portal', 'website', 'reception'] as $source) {
            $this->assertSame('program.paused', $this->bookingRefused($club, $late, $session, $source));
        }
        $this->asTenant($club, function () use ($kept, $session, $membership, $usedBefore) {
            $this->assertSame(Booking::BOOKED, $kept->fresh()->status, 'the booking made before the pause stays');
            $this->assertSame(1, $session->fresh()->booked_count);
            $this->assertSame(ClassSession::SCHEDULED, $session->fresh()->status, 'the class stays');
            $this->assertSame($usedBefore, $membership->fresh()->sessions_used, 'no credit given back or taken');
            $this->assertSame(1, Booking::where('class_session_id', $session->id)->count());
        });
    }

    public function test_staff_still_see_and_manage_the_bookings_a_paused_program_has(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $reception = $this->staff($club, 'reception');
        $zumba = $this->program($club);
        $session = $this->classSession($club, $zumba);
        $later = $this->classSession($club, $zumba, ['starts_at' => now()->addDays(3)->setTime(9, 0)]);
        [$a, $b] = [$this->member($club), $this->member($club)];
        $this->activeMembership($club, $a);
        $this->activeMembership($club, $b);
        $first = $this->asTenant($club, fn () => app(BookingService::class)->book($a, $session));
        $second = $this->asTenant($club, fn () => app(BookingService::class)->book($b, $later));
        $this->pause($club, $zumba);

        $this->actingAs($reception)->get(route('club.sessions.show', $session))->assertOk();
        $this->actingAs($reception)->post(route('club.bookings.cancel', $second))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Booking::CANCELLED, $second->fresh()->status);
        $this->actingAs($owner)->post(route('club.bookings.store', $later), ['member_id' => $b->id])->assertSessionHasErrors();
        $this->assertSame(Booking::BOOKED, $first->fresh()->status);
        // Pausing a program is for whoever manages classes, not the desk.
        $this->actingAs($reception)->put(route('club.programs.update', $zumba), ['name' => 'Zumba', 'is_active' => true])->assertForbidden();
        $this->assertFalse($zumba->fresh()->is_active);
    }

    public function test_the_website_and_the_portal_offer_no_class_of_a_paused_program(): void
    {
        $club = $this->club();
        [$zumba, $yoga] = [$this->program($club), $this->program($club, ['name' => 'Yoga'])];
        $this->classSession($club, $zumba);
        $this->classSession($club, $yoga);
        $member = $this->member($club, ['password' => 'secret123']);
        $this->activeMembership($club, $member);
        $this->pause($club, $zumba);

        $this->get(route('site.schedule', ['club' => $club->slug]))->assertOk()
            ->assertInertia(fn ($page) => $page->has('sessions', 1)->where('sessions.0.program', 'Yoga'));
        $this->actingAs($member, 'member')->get(route('portal.classes', ['club' => $club->slug]))->assertOk()
            ->assertInertia(fn ($page) => $page->has('sessions', 1)->where('sessions.0.program', 'Yoga'));
        $zumbaClass = $this->asTenant($club, fn () => ClassSession::where('program_id', $zumba->id)->firstOrFail());
        $this->post(route('portal.classes.book', ['club' => $club->slug, 'session' => $zumbaClass->id]))->assertSessionHasErrors('booking');
    }

    public function test_no_one_off_class_is_added_to_a_paused_program(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$zumba, $yoga] = [$this->program($club), $this->program($club, ['name' => 'Yoga'])];
        $this->pause($club, $zumba);
        $one = ['starts_at' => now()->addDays(2)->setTime(11, 0)->format('Y-m-d H:i'), 'duration_minutes' => 60, 'capacity' => 10];

        $this->actingAs($owner)->post(route('club.sessions.store'), $one + ['program_id' => $zumba->id])->assertSessionHasErrors('program_id');
        $this->actingAs($owner)->post(route('club.sessions.store'), $one + ['program_id' => $yoga->id])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->classesOf($club, $zumba));
        $this->assertSame(1, $this->classesOf($club, $yoga));
    }

    public function test_pausing_a_program_touches_no_other_club(): void
    {
        [$mine, $theirs] = [$this->club(), $this->club('Other')];
        [$myZumba, $theirZumba] = [$this->program($mine), $this->program($theirs)];
        $this->weekly($mine, $myZumba);
        $this->weekly($theirs, $theirZumba);
        $theirClass = $this->classSession($theirs, $theirZumba);
        $theirMember = $this->member($theirs);
        $this->activeMembership($theirs, $theirMember);

        $this->pause($mine, $myZumba);

        $this->assertSame(0, $this->generate($mine));
        $this->assertGreaterThan(0, $this->generate($theirs));
        $this->asTenant($theirs, fn () => app(BookingService::class)->book($theirMember, $theirClass, 'portal'));
        $this->assertSame(1, $theirClass->fresh()->booked_count);
    }
}
