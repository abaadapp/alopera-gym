<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\BookingService;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * A classes-only plan (#70, «صلاحيات الباقة») at the same moment, in separate processes on PostgreSQL. Its credit is
 * spent once: two bookings at once on its last class leave one booking and the credit used once, and the same class
 * booked twice at once is booked once. The membership row and the class row are read under their locks.
 */
class PlanPermissionsConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** @return array{Tenant, Member, Membership} */
    private function classesOnly(int $sessions): array
    {
        $club = $this->club();
        $member = $this->member($club);
        $membership = $this->activeMembership($club, $member, $this->plan($club, ['type' => 'classes', 'sessions_limit' => $sessions]));

        return [$club, $member, $membership];
    }

    private function booking(Tenant $club, Member $member, ClassSession $session): \Closure
    {
        return fn () => $this->asTenant($club, fn () => app(BookingService::class)->book($member->fresh(), $session, 'portal')->id);
    }

    public function test_two_bookings_at_once_on_the_last_class_of_the_credit_book_one(): void
    {
        [$club, $member, $membership] = $this->classesOnly(1);
        $first = $this->classSession($club, null, ['starts_at' => now()->addDay()->setTime(18, 0)]);
        $second = $this->classSession($club, null, ['starts_at' => now()->addDays(2)->setTime(18, 0)]);

        $results = $this->concurrently([
            function (string $dir) use ($club, $member, $first) {
                self::pauseAfter('update "gym_memberships" set "sessions_used"', $dir, 1.0); // credit spent, not committed

                return ($this->booking($club, $member, $first))();
            },
            function (string $dir) use ($club, $member, $second) {
                self::waitFor("$dir/inside");

                return ($this->booking($club, $member, $second))();
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($results[1]['ok'], 'a second booking spent the same credit: '.json_encode($results, JSON_UNESCAPED_UNICODE));
        // Refused by the rule once the first was saved, not by a timeout: it waited on the membership's lock.
        $this->assertStringContainsString('تم استهلاك جميع حصص الباقة.', (string) $results[1]['error']);
        $this->asTenant($club, function () use ($membership) {
            $this->assertSame(1, Booking::where('status', Booking::BOOKED)->count());
            $this->assertSame(1, $membership->fresh()->sessions_used);
        });
    }

    public function test_the_same_class_booked_twice_at_once_is_booked_once(): void
    {
        [$club, $member, $membership] = $this->classesOnly(5);
        $session = $this->classSession($club, null, ['starts_at' => now()->addDay()->setTime(18, 0)]);

        $results = $this->concurrently([
            function (string $dir) use ($club, $member, $session) {
                self::pauseAfter('insert into "gym_bookings"', $dir, 1.0); // booked, not committed

                return ($this->booking($club, $member, $session))();
            },
            function (string $dir) use ($club, $member, $session) {
                self::waitFor("$dir/inside");

                return ($this->booking($club, $member, $session))();
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($results[1]['ok'], 'the class was booked twice: '.json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('العضو محجوز في هذه الحصة بالفعل.', (string) $results[1]['error']);
        $this->asTenant($club, function () use ($membership) {
            $this->assertSame(1, Booking::count());
            $this->assertSame(1, $membership->fresh()->sessions_used);
        });
    }
}
