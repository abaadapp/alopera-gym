<?php

namespace Tests\Feature;

use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * A membership's future bookings are moved (an upgrade) or released (a cancellation) after the money's — or the
 * cancellation's — transaction has committed, never inside it: bookings lock the class, then the membership; a payment
 * or a cancellation no longer locks a class while it holds the membership, so the two never deadlock (one of them was
 * the Paymob callback, ended with a 500). What is left to do is read from the bookings themselves — a booked future
 * class on a cancelled membership — so a crash or a failure after the commit is finished by gym:settle-bookings, and
 * running it twice moves or releases each booking once. Separate processes on PostgreSQL; the process holding a lock
 * goes on only once PostgreSQL shows the other blocked by it. Paymob is faked.
 */
class MembershipBookingsAfterCommitTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    private const HMAC = 'bookings-hmac';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_bk', 'intention_order_id' => 4993], 201)]);
    }

    /**
     * A member on a plan of 8 classes, booked on one tomorrow (counted), with an upgrade to 12 classes waiting for its
     * Paymob payment.
     *
     * @return array{club: Tenant, current: Membership, upgrade: Membership, intent: PaymentIntent, session: ClassSession, booking: Booking}
     */
    private function upgradeWithABooking(): array
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));
        $member = $this->member($club);
        $current = $this->activeMembership($club, $member, $this->plan($club, ['price' => 25, 'sessions_limit' => 8]));
        $session = $this->classSession($club);
        $booking = $this->asTenant($club, fn () => app(BookingService::class)->book($member, $session, 'reception'));
        $better = $this->plan($club, ['name' => 'Plus', 'price' => 40, 'sessions_limit' => 12]);
        [$upgrade, $intent] = $this->asTenant($club, function () use ($current, $better) {
            $upgrade = app(MembershipService::class)->upgrade($current->fresh(), $better);

            return [$upgrade, app(OnlinePaymentService::class)->start($upgrade->invoice, 'portal')];
        });
        $this->asTenant($club, fn () => $this->assertSame([true, 1], [(bool) $booking->consumed_session, $current->fresh()->sessions_used]));

        return compact('club', 'current', 'upgrade', 'intent', 'session', 'booking');
    }

    /** @return array<string, mixed> */
    private function obj(PaymentIntent $intent): array
    {
        return [
            'id' => 564001, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true,
            'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false,
            'is_3d_secure' => true, 'integration_id' => 111, 'has_parent_transaction' => false,
            'order' => ['id' => (int) $intent->provider_order_id, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-15T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ];
    }

    private static function deliver(array $obj): string
    {
        return app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], PaymobSignature::compute($obj, self::HMAC));
    }

    /** That membership row's own lock (its id the first binding; the tenant's id comes after). */
    private static function membershipLock(int $id): callable
    {
        return fn (string $sql, array $bindings) => str_contains($sql, 'from "gym_memberships" where "gym_memberships"."id" = ?') && ($bindings[0] ?? null) === $id;
    }

    /** Both finished; a refusal is a business rule, never a deadlock. */
    private function assertNoDeadlock(array $results): void
    {
        $json = json_encode($results, JSON_UNESCAPED_UNICODE);
        foreach ($results as $result) {
            $this->assertNotSame('not at the same time', $result['value'] ?? null, $json);
            $this->assertStringNotContainsString('40P01', (string) $result['error'], $json);
            $this->assertStringNotContainsStringIgnoringCase('deadlock', (string) $result['error'], $json);
            if (! $result['ok']) {
                $this->assertStringContainsString('BusinessRuleException', (string) $result['error'], $json);
            }
        }
    }

    /** Seats and counted classes agree with the bookings, and the books balance. */
    private function assertCounts(Tenant $club): void
    {
        $this->asTenant($club, function () {
            foreach (ClassSession::all() as $s) {
                $this->assertSame(Booking::where('class_session_id', $s->id)->where('status', Booking::BOOKED)->count(), (int) $s->booked_count, "seats of session {$s->id}");
            }
            foreach (Membership::all() as $m) {
                $this->assertSame(Booking::where('membership_id', $m->id)->where('consumed_session', true)->where('status', '!=', Booking::CANCELLED)->count(), (int) $m->sessions_used, "classes counted on membership {$m->id}");
            }
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'), 'the books balance');
        });
    }

    public function test_an_upgrade_payment_while_the_member_cancels_a_counted_booking_does_not_deadlock(): void
    {
        ['club' => $club, 'current' => $current, 'upgrade' => $upgrade, 'intent' => $intent, 'booking' => $booking] = $this->upgradeWithABooking();
        $obj = $this->obj($intent);

        $results = $this->concurrently([
            function (string $dir) use ($obj, $current) {
                self::holdUntilAnotherWaits($dir, self::membershipLock($current->id)); // the callback holds the replaced membership

                return [self::deliver($obj), is_file("$dir/blocked")];
            },
            function (string $dir) use ($club, $booking) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? $this->asTenant($club, fn () => app(BookingService::class)->cancel($booking, false)->status) : 'not at the same time';
            },
        ]);

        $this->assertNoDeadlock($results);
        $this->assertSame([[true, ['processed', true]], [true, Booking::CANCELLED]], array_map(fn ($r) => [$r['ok'], $r['value']], $results), json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([Membership::CANCELLED, Membership::ACTIVE, Booking::CANCELLED, 1],
            [$current->fresh()->status, $upgrade->fresh()->status, $booking->fresh()->status, Payment::where('gateway', 'paymob')->count()]));
        $this->assertCounts($club);
    }

    public function test_an_upgrade_payment_while_the_member_books_the_same_class_again_carries_her_booking_once(): void
    {
        ['club' => $club, 'current' => $current, 'upgrade' => $upgrade, 'intent' => $intent, 'session' => $session, 'booking' => $booking] = $this->upgradeWithABooking();
        $obj = $this->obj($intent);

        $results = $this->concurrently([
            function (string $dir) use ($obj, $current) {
                self::holdUntilAnotherWaits($dir, self::membershipLock($current->id));

                return [self::deliver($obj), is_file("$dir/blocked")];
            },
            function (string $dir) use ($club, $current, $session) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? $this->asTenant($club, fn () => app(BookingService::class)->book($current->member, $session, 'reception')->id) : 'not at the same time';
            },
        ]);

        $this->assertNoDeadlock($results);
        $this->assertSame([true, ['processed', true]], [$results[0]['ok'], $results[0]['value']], json_encode($results));
        $this->assertFalse($results[1]['ok'], 'already booked in that class: '.json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([$upgrade->id, true, Booking::BOOKED, 1],
            [$booking->fresh()->membership_id, (bool) $booking->fresh()->consumed_session, $booking->fresh()->status, Booking::count()]), 'carried to the upgrade, once');
        $this->assertCounts($club);
    }

    public function test_a_membership_cancelled_while_the_member_books_does_not_deadlock_and_releases_her_booking(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $membership = $this->activeMembership($club, $member, $this->plan($club, ['sessions_limit' => 8]));
        $session = $this->classSession($club);
        $booking = $this->asTenant($club, fn () => app(BookingService::class)->book($member, $session, 'reception'));

        $results = $this->concurrently([
            function (string $dir) use ($club, $membership) {
                self::holdUntilAnotherWaits($dir, self::membershipLock($membership->id)); // the cancellation holds the membership

                return [$this->asTenant($club, fn () => app(MembershipService::class)->cancel($membership, 'إلغاء')->status), is_file("$dir/blocked")];
            },
            function (string $dir) use ($club, $member, $session) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? $this->asTenant($club, fn () => app(BookingService::class)->book($member, $session, 'reception')->id) : 'not at the same time';
            },
        ]);

        $this->assertNoDeadlock($results);
        $this->assertSame([true, [Membership::CANCELLED, true]], [$results[0]['ok'], $results[0]['value']], json_encode($results));
        $this->assertFalse($results[1]['ok'], 'no usable membership any more: '.json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([Booking::CANCELLED, 0], [$booking->fresh()->status, (int) $session->fresh()->booked_count]));
        $this->assertCounts($club);
    }

    /** Inside a task: the process dies (SIGKILL, no shutdown) right after the first row lock on a class — after the commit. */
    private static function dieAtTheFirstClassLock(): void
    {
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'from "gym_class_sessions"') && str_contains($query->sql, 'for update')) {
                posix_kill(getmypid(), SIGKILL);
            }
        });
    }

    /** The upgrade paid, the server gone right after the payment committed, before its bookings were carried. */
    private function upgradePaidThenCrashed(): array
    {
        $case = $this->upgradeWithABooking();
        $obj = $this->obj($case['intent']);
        $results = $this->concurrently([function () use ($obj) {
            self::dieAtTheFirstClassLock();

            return self::deliver($obj);
        }]);
        $this->assertSame('killed before it finished', $results[0]['error'], json_encode($results));

        return $case + ['obj' => $obj];
    }

    public function test_a_crash_after_the_upgrade_payment_keeps_the_money_and_the_sweep_carries_the_bookings_once(): void
    {
        ['club' => $club, 'current' => $current, 'upgrade' => $upgrade, 'booking' => $booking, 'obj' => $obj] = $this->upgradePaidThenCrashed();

        // The money committed before the crash; nothing about the bookings was half-done.
        $this->asTenant($club, fn () => $this->assertSame([1, Membership::CANCELLED, Membership::ACTIVE, $current->id, Booking::BOOKED],
            [Payment::where('gateway', 'paymob')->count(), $current->fresh()->status, $upgrade->fresh()->status, $booking->fresh()->membership_id, $booking->fresh()->status]));
        // Paymob had no answer and sends it again: recorded once.
        $this->assertSame('duplicate', self::deliver($obj));

        $this->assertSame(0, Artisan::call('gym:settle-bookings'));
        $this->asTenant($club, fn () => $this->assertSame([$upgrade->id, Booking::BOOKED, 1, 0],
            [$booking->fresh()->membership_id, $booking->fresh()->status, $upgrade->fresh()->sessions_used, $current->fresh()->sessions_used]));
        Artisan::call('gym:settle-bookings'); // again: nothing more
        $this->asTenant($club, fn () => $this->assertSame([$upgrade->id, 1, 0, 1, 1], [$booking->fresh()->membership_id, $upgrade->fresh()->sessions_used,
            $current->fresh()->sessions_used, Payment::where('gateway', 'paymob')->count(), Booking::count()]));
        $this->assertCounts($club);
    }

    public function test_an_upgrade_settled_only_after_its_class_started_carries_the_booking_once(): void
    {
        // #175: the upgrade paid, the server gone before the bookings moved, and the sweep late: the class has started.
        ['club' => $club, 'current' => $current, 'upgrade' => $upgrade, 'session' => $session, 'booking' => $booking] = $this->upgradePaidThenCrashed();
        $this->travelTo($session->starts_at->addMinutes(10));

        $this->assertSame(0, Artisan::call('gym:settle-bookings'));
        $settled = fn () => [$booking->fresh()->membership_id, $booking->fresh()->status, (bool) $booking->fresh()->consumed_session,
            $upgrade->fresh()->sessions_used, $current->fresh()->sessions_used, (int) $session->fresh()->booked_count, Booking::count(), Payment::where('gateway', 'paymob')->count()];
        // On the upgrade she was booked under when it was decided, counted once there and no longer on the old period.
        $this->asTenant($club, fn () => $this->assertSame([$upgrade->id, Booking::BOOKED, true, 1, 0, 1, 1, 1], $settled()));

        Artisan::call('gym:settle-bookings'); // again: the same
        $this->asTenant($club, fn () => $this->assertSame([$upgrade->id, Booking::BOOKED, true, 1, 0, 1, 1, 1], $settled()));
        $this->assertCounts($club);
    }

    public function test_a_crash_after_a_cancellation_leaves_the_booking_for_the_sweep_to_release_once(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $membership = $this->activeMembership($club, $member, $this->plan($club, ['sessions_limit' => 8]));
        $session = $this->classSession($club);
        $booking = $this->asTenant($club, fn () => app(BookingService::class)->book($member, $session, 'reception'));

        $results = $this->concurrently([function () use ($club, $membership) {
            self::dieAtTheFirstClassLock();

            return $this->asTenant($club, fn () => app(MembershipService::class)->cancel($membership, 'إلغاء')->status);
        }]);
        $this->assertSame('killed before it finished', $results[0]['error'], json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([Membership::CANCELLED, Booking::BOOKED, 1], [$membership->fresh()->status, $booking->fresh()->status, (int) $session->fresh()->booked_count]));
        $this->assertSame(0, Artisan::call('gym:settle-bookings', ['--dry-run' => true]));
        $this->asTenant($club, fn () => $this->assertSame(Booking::BOOKED, $booking->fresh()->status), 'a dry run changes nothing');

        Artisan::call('gym:settle-bookings');
        Artisan::call('gym:settle-bookings');
        $this->asTenant($club, fn () => $this->assertSame([Booking::CANCELLED, 0, 0], [$booking->fresh()->status, (int) $session->fresh()->booked_count, $membership->fresh()->sessions_used]));
        $this->assertCounts($club);
    }

    public function test_when_the_step_after_the_commit_fails_the_payment_stands_and_the_sweep_finishes(): void
    {
        ['club' => $club, 'current' => $current, 'upgrade' => $upgrade, 'intent' => $intent, 'session' => $session, 'booking' => $booking] = $this->upgradeWithABooking();
        $obj = $this->obj($intent);

        $results = $this->concurrently([
            function (string $dir) use ($club, $session) {
                // Someone holds the class until the callback has finished (its step after the commit gives up meanwhile).
                return $this->asTenant($club, fn () => DB::transaction(function () use ($dir, $session) {
                    ClassSession::whereKey($session->id)->lockForUpdate()->first();
                    touch("$dir/inside");
                    self::waitFor("$dir/result-1.json");

                    return 'held';
                }));
            },
            function (string $dir) use ($obj) {
                self::waitFor("$dir/inside");
                DB::statement("set lock_timeout = '300ms'");

                return is_file("$dir/inside") ? self::deliver($obj) : 'not at the same time';
            },
        ]);

        $this->assertSame(['held', 'processed'], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([Membership::ACTIVE, $current->id], [$upgrade->fresh()->status, $booking->fresh()->membership_id]), 'paid; the bookings left as they were');

        Artisan::call('gym:settle-bookings');
        $this->asTenant($club, fn () => $this->assertSame($upgrade->id, $booking->fresh()->membership_id));
        $this->assertCounts($club);
    }

    public function test_two_sweeps_at_once_carry_each_booking_once(): void
    {
        ['club' => $club, 'current' => $current, 'upgrade' => $upgrade, 'session' => $session, 'booking' => $booking] = $this->upgradePaidThenCrashed();

        $results = $this->concurrently([
            function (string $dir) use ($session) {
                self::holdUntilAnotherWaits($dir, fn (string $sql, array $bindings) => str_contains($sql, 'from "gym_class_sessions"') && ($bindings[0] ?? null) === $session->id);

                return [Artisan::call('gym:settle-bookings'), is_file("$dir/blocked")];
            },
            function (string $dir) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? Artisan::call('gym:settle-bookings') : 'not at the same time';
            },
        ]);

        $this->assertNoDeadlock($results);
        $this->assertSame([[true, [0, true]], [true, 0]], array_map(fn ($r) => [$r['ok'], $r['value']], $results), json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([$upgrade->id, 1, 0, 1], [$booking->fresh()->membership_id, $upgrade->fresh()->sessions_used, $current->fresh()->sessions_used, (int) $session->fresh()->booked_count]));
        $this->assertCounts($club);
    }

    public function test_nothing_is_settled_when_the_cancellation_is_rolled_back(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $membership = $this->activeMembership($club, $member, $this->plan($club, ['sessions_limit' => 8]));
        $booking = $this->asTenant($club, fn () => app(BookingService::class)->book($member, $this->classSession($club), 'reception'));

        try {
            $this->asTenant($club, fn () => DB::transaction(function () use ($membership) {
                app(MembershipService::class)->cancel($membership, 'إلغاء');
                throw new \RuntimeException('a later step failed');
            }));
        } catch (\RuntimeException) {
        }

        // Rolled back: the membership stands, and the step after the commit never ran (no commit).
        $this->asTenant($club, fn () => $this->assertSame([Membership::ACTIVE, Booking::BOOKED, 1], [$membership->fresh()->status, $booking->fresh()->status, $membership->fresh()->sessions_used]));
        $this->assertCounts($club);
    }

    public function test_bookings_left_on_a_replaced_membership_are_not_carried_to_an_upgrade_cancelled_since(): void
    {
        ['club' => $club, 'current' => $current, 'upgrade' => $upgrade, 'booking' => $booking] = $this->upgradePaidThenCrashed();
        $this->asTenant($club, fn () => app(MembershipService::class)->cancel($upgrade->fresh(), 'إلغاء الترقية'));

        Artisan::call('gym:settle-bookings');
        // The upgrade is no longer there to take it: released, its seat and its counted class given back.
        $this->asTenant($club, fn () => $this->assertSame([Booking::CANCELLED, $current->id, 0, 0],
            [$booking->fresh()->status, $booking->fresh()->membership_id, $current->fresh()->sessions_used, $upgrade->fresh()->sessions_used]));
        $this->assertCounts($club);
    }

    public function test_a_class_that_started_before_the_sweep_is_settled_from_the_moment_of_the_cancellation(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $membership = $this->activeMembership($club, $member, $this->plan($club, ['sessions_limit' => 8]));
        $session = $this->classSession($club); // tomorrow 18:00
        $booking = $this->asTenant($club, fn () => app(BookingService::class)->book($member, $session, 'reception'));
        $results = $this->concurrently([function () use ($club, $membership) {
            self::dieAtTheFirstClassLock();

            return $this->asTenant($club, fn () => app(MembershipService::class)->cancel($membership, 'إلغاء')->status);
        }]);
        $this->assertSame('killed before it finished', $results[0]['error'], json_encode($results));

        // The sweep comes only once the class has started: it was after the cancellation, so it is released all the same.
        $this->travelTo($session->starts_at->addMinutes(10));
        Artisan::call('gym:settle-bookings');
        $this->asTenant($club, fn () => $this->assertSame([Booking::CANCELLED, 0, 0], [$booking->fresh()->status, (int) $session->fresh()->booked_count, $membership->fresh()->sessions_used]));
        $this->assertCounts($club);
    }

    public function test_a_class_already_closed_before_the_sweep_is_left_as_history(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $membership = $this->activeMembership($club, $member, $this->plan($club, ['sessions_limit' => 8]));
        $session = $this->classSession($club);
        $booking = $this->asTenant($club, fn () => app(BookingService::class)->book($member, $session, 'reception'));
        $results = $this->concurrently([function () use ($club, $membership) {
            self::dieAtTheFirstClassLock();

            return $this->asTenant($club, fn () => app(MembershipService::class)->cancel($membership, 'إلغاء')->status);
        }]);
        $this->assertSame('killed before it finished', $results[0]['error'], json_encode($results));

        $this->travelTo($session->ends_at->addHours(2)); // closed an hour after its end
        $this->asTenant($club, fn () => app(BookingService::class)->closeFinishedSessions()); // marked absent, as every hour
        $status = $this->asTenant($club, fn () => $booking->fresh()->status);
        $this->assertNotSame(Booking::BOOKED, $status);

        Artisan::call('gym:settle-bookings');
        $this->asTenant($club, fn () => $this->assertSame($status, $booking->fresh()->status, 'a closed class is not rewritten'));
    }

    public function test_an_old_booking_left_on_a_cancelled_membership_is_counted_by_the_dry_run_and_released_once(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $membership = $this->activeMembership($club, $member, $this->plan($club, ['sessions_limit' => 8]));
        $session = $this->classSession($club, null, ['starts_at' => now()->addDays(3)->setTime(18, 0)]);
        $booking = $this->asTenant($club, fn () => app(BookingService::class)->book($member, $session, 'reception'));
        // As data from before: cancelled weeks ago, its future booking never released.
        $this->asTenant($club, fn () => $membership->forceFill(['status' => Membership::CANCELLED, 'cancelled_at' => now()->subWeeks(3), 'cancel_reason' => 'قديم'])->save());

        $this->assertSame(0, Artisan::call('gym:settle-bookings', ['--dry-run' => true]));
        $this->assertStringContainsString('bookings_left=1', Artisan::output());
        $this->asTenant($club, fn () => $this->assertSame(Booking::BOOKED, $booking->fresh()->status), 'a dry run changes nothing');

        Artisan::call('gym:settle-bookings');
        Artisan::call('gym:settle-bookings', ['--dry-run' => true]);
        $this->assertStringContainsString('bookings_left=0', Artisan::output());
        $this->asTenant($club, fn () => $this->assertSame([Booking::CANCELLED, 0, 0], [$booking->fresh()->status, (int) $session->fresh()->booked_count, $membership->fresh()->sessions_used]));
        $this->assertCounts($club);
    }

    public function test_freezing_the_replaced_membership_while_the_upgrade_payment_comes_in_does_not_deadlock(): void
    {
        ['club' => $club, 'current' => $current, 'upgrade' => $upgrade, 'intent' => $intent, 'booking' => $booking] = $this->upgradeWithABooking();
        $obj = $this->obj($intent);

        $results = $this->concurrently([
            function (string $dir) use ($club, $current) {
                self::holdUntilAnotherWaits($dir, self::membershipLock($current->id)); // the freeze holds it

                return [$this->asTenant($club, fn () => app(MembershipService::class)->freeze($current, 7, 'سفر')->status), is_file("$dir/blocked")];
            },
            function (string $dir) use ($obj) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? self::deliver($obj) : 'not at the same time';
            },
        ]);

        $this->assertNoDeadlock($results);
        // Frozen first (its bookings released then), then the upgrade replaces it: the payment applied.
        $this->assertSame([[true, [Membership::FROZEN, true]], [true, 'processed']], array_map(fn ($r) => [$r['ok'], $r['value']], $results), json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([Membership::CANCELLED, Membership::ACTIVE, Booking::CANCELLED],
            [$current->fresh()->status, $upgrade->fresh()->status, $booking->fresh()->status]));
        $this->assertCounts($club);
    }
}
