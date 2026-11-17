<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\GiftService;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * #192 at the same moment, in separate processes on PostgreSQL: the recipient redeems from the link while a member of
 * staff redeems from the panel. The gift's invoice and the gift are locked first, so the two are read one after the
 * other: one membership, one zero invoice, no extra payment, and the second is told the gift was used.
 */
class GiftRedeemConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    private function redeem(Tenant $club, GiftMembership $gift, Member $member, string $via): \Closure
    {
        return fn () => $this->asTenant($club, fn () => app(GiftService::class)->redeem($gift->fresh(), $member->fresh(), today()->toDateString(), $via)->membership_id);
    }

    public function test_the_recipient_and_staff_redeeming_at_once_make_one_membership(): void
    {
        $club = $this->club();
        $plan = $this->plan($club);
        $recipient = $this->member($club);
        $walkIn = $this->member($club);
        $gift = $this->asTenant($club, function () use ($plan) {
            $gift = app(GiftService::class)->issue($plan, ['giver_name' => 'Sara', 'recipient_name' => 'Noor'], 'site');
            app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash');

            return $gift->fresh();
        });

        $results = $this->concurrently([
            function (string $dir) use ($club, $gift, $recipient) {
                self::pauseAfter('insert into "gym_memberships"', $dir, 1.0); // redeemed, not committed

                return ($this->redeem($club, $gift, $recipient, 'link'))();
            },
            function (string $dir) use ($club, $gift, $walkIn) {
                self::waitFor("$dir/inside");

                return ($this->redeem($club, $gift, $walkIn, 'staff'))();
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($results[1]['ok'], 'redeemed twice: '.json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('استُخدمت هذه الهدية من قبل', (string) $results[1]['error']);

        $this->asTenant($club, function () use ($gift, $recipient) {
            $this->assertSame(1, Membership::count());
            $this->assertSame($recipient->id, Membership::first()->member_id);
            $this->assertSame(GiftMembership::REDEEMED, $gift->fresh()->status);
            $this->assertSame(2, Invoice::count(), 'The gift invoice and one zero invoice.');
            $this->assertSame(1, Payment::count());
        });
    }
}
