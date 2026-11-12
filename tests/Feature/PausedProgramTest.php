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
use Illuminate\Support\Facades\DB;
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

    private const PAUSED = 'هذا البرنامج موقوف، فلا حجوزات جديدة على حصصه.';

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
        $this->actingAs($owner)->post(route('club.bookings.store', $later), ['member_id' => $b->id])->assertSessionHasErrors(['booking' => self::PAUSED]);
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

    public function test_a_one_off_class_tells_a_paused_program_from_one_that_does_not_exist(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$zumba, $yoga] = [$this->program($club), $this->program($club, ['name' => 'Yoga'])];
        $theirs = $this->program($elsewhere = $this->club('Other'));
        $this->pause($club, $zumba);
        $one = ['starts_at' => now()->addDays(2)->setTime(11, 0)->format('Y-m-d H:i'), 'duration_minutes' => 60, 'capacity' => 10];
        $paused = 'البرنامج موقوف؛ لا تُضاف له حصص جديدة.';

        $this->actingAs($owner)->post(route('club.sessions.store'), $one + ['program_id' => $zumba->id])->assertSessionHasErrors(['program_id' => $paused]);
        // Not this club's, not there at all, not a number: "does not exist", never "paused".
        foreach ([$theirs->id, 999999, 'abc'] as $missing) {
            $this->actingAs($owner)->post(route('club.sessions.store'), $one + ['program_id' => $missing])
                ->assertSessionHasErrors(['program_id' => 'القيمة المختارة في البرنامج غير موجودة.'])
                ->assertSessionDoesntHaveErrors(['program_id' => $paused]);
        }
        $this->actingAs($owner)->post(route('club.sessions.store'), $one + ['program_id' => $yoga->id])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->classesOf($club, $zumba));
        $this->assertSame(1, $this->classesOf($club, $yoga));
        $this->assertSame(0, $this->asTenant($elsewhere, fn () => ClassSession::count()));
    }

    public function test_direct_requests_book_no_class_of_a_paused_program_and_an_active_one_books_as_before(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $reception = $this->staff($club, 'reception');
        [$zumba, $yoga] = [$this->program($club), $this->program($club, ['name' => 'Yoga'])];
        [$paused, $active] = [$this->classSession($club, $zumba), $this->classSession($club, $yoga)];
        [$member, $other] = [$this->member($club, ['password' => 'secret123']), $this->member($club)];
        $this->activeMembership($club, $member);
        $this->activeMembership($club, $other);
        $this->pause($club, $zumba);
        $book = route('portal.classes.book', ['club' => $club->slug, 'session' => $paused->id]);
        $bookInEnglish = str_replace('/c/'.$club->slug.'/', '/c/'.$club->slug.'/en/', parse_url($book, PHP_URL_PATH));

        // The member's own request, in Arabic and in English, and the desk's, whoever sends it: refused in the service.
        $this->actingAs($member, 'member')->post($book)->assertSessionHasErrors(['booking' => self::PAUSED]);
        $this->actingAs($member, 'member')->post($bookInEnglish)->assertSessionHasErrors(['booking' => 'This programme is paused: its classes take no new bookings.']);
        foreach ([$owner, $reception] as $staff) {
            $this->actingAs($staff)->post(route('club.bookings.store', $paused), ['member_id' => $member->id])->assertSessionHasErrors(['booking' => self::PAUSED]);
        }
        $this->asTenant($club, function () use ($paused) {
            $this->assertSame(0, Booking::where('class_session_id', $paused->id)->count());
            $this->assertSame(0, $paused->fresh()->booked_count);
        });

        // An active program's class books as it always did, from the portal and from the desk.
        $this->actingAs($member, 'member')->post(route('portal.classes.book', ['club' => $club->slug, 'session' => $active->id]))->assertSessionHasNoErrors();
        $this->actingAs($reception)->post(route('club.bookings.store', $active), ['member_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame(2, $this->asTenant($club, fn () => $active->fresh()->booked_count));
    }

    public function test_the_bookings_a_paused_program_has_cancel_and_give_their_credit_back_as_before(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $zumba = $this->program($club);
        [$one, $two, $three] = [
            $this->classSession($club, $zumba, ['starts_at' => now()->addDays(2)->setTime(18, 0)]),
            $this->classSession($club, $zumba, ['starts_at' => now()->addDays(3)->setTime(18, 0)]),
            $this->classSession($club, $zumba, ['starts_at' => now()->addDays(4)->setTime(18, 0)]),
        ];
        $member = $this->member($club, ['password' => 'secret123']);
        $membership = $this->activeMembership($club, $member, $this->plan($club, ['sessions_limit' => 8]));
        [$first, $second, $third] = array_map(fn ($s) => $this->asTenant($club, fn () => app(BookingService::class)->book($member, $s, 'portal')), [$one, $two, $three]);
        $this->assertSame(3, $membership->fresh()->sessions_used);

        $this->pause($club, $zumba);
        $this->assertSame(3, $membership->fresh()->sessions_used, 'pausing gives back or takes no credit');

        // Her own cancellation in the portal, the desk's, and the whole class cancelled: each gives its credit back.
        $this->actingAs($member, 'member')->post(route('portal.bookings.cancel', ['club' => $club->slug, 'booking' => $first->id]))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.bookings.cancel', $second))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.sessions.cancel', $three), ['reason' => 'سفر المدربة'])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($first, $second, $third, $one, $two, $three, $membership) {
            foreach ([$first, $second, $third] as $booking) {
                $this->assertSame(Booking::CANCELLED, $booking->fresh()->status);
            }
            foreach ([$one, $two] as $session) {
                $this->assertSame(0, $session->fresh()->booked_count);
            }
            $this->assertSame(ClassSession::CANCELLED, $three->fresh()->status);
            $this->assertSame(0, $membership->fresh()->sessions_used, 'every credit came back, once');
        });
    }

    public function test_the_hourly_command_makes_no_class_for_a_paused_program_and_goes_on_for_the_others(): void
    {
        [$mine, $theirs] = [$this->club(), $this->club('Other')];
        [$zumba, $yoga, $theirZumba] = [$this->program($mine), $this->program($mine, ['name' => 'Yoga']), $this->program($theirs)];
        $this->weekly($mine, $zumba);
        $this->weekly($mine, $yoga);
        $this->weekly($theirs, $theirZumba);
        $this->pause($mine, $zumba);

        // What the scheduler runs every hour (routes/console.php), for every club.
        $this->artisan('gym:sessions')->assertSuccessful();

        $this->assertSame(0, $this->classesOf($mine, $zumba));
        $this->assertGreaterThan(0, $this->classesOf($mine, $yoga), 'an active program of the same club');
        $this->assertGreaterThan(0, $this->classesOf($theirs, $theirZumba), 'another club');
    }

    public function test_pausing_changes_no_invoice_payment_membership_or_ledger_entry(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $zumba = $this->program($club);
        $this->weekly($club, $zumba);
        $session = $this->classSession($club, $zumba);
        [$member, $late] = [$this->member($club), $this->member($club)];
        $this->activeMembership($club, $member, $this->plan($club, ['sessions_limit' => 8]));
        $this->activeMembership($club, $late);
        $this->asTenant($club, fn () => app(BookingService::class)->book($member, $session, 'portal'));
        $tables = ['invoices', 'invoice_items', 'payments', 'payment_allocations', 'credit_notes', 'gym_memberships',
            'gym_membership_events', 'gym_membership_freezes', 'gym_accounts', 'gym_journal_entries', 'gym_journal_lines'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
        $before = $snapshot();
        $this->assertNotEmpty($before['invoices'], 'the memberships were invoiced');
        $this->assertNotEmpty($before['payments'], 'and paid');

        // The pause, the bookings it refuses and the generator after it.
        $this->pause($club, $zumba);
        $this->bookingRefused($club, $late, $session, 'portal');
        $this->actingAs($owner)->post(route('club.bookings.store', $session), ['member_id' => $late->id])->assertSessionHasErrors(['booking' => self::PAUSED]);
        $this->assertSame(0, $this->generate($club));

        $this->assertEquals($before, $snapshot(), 'pausing a program alone moves no money and changes no membership');
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
