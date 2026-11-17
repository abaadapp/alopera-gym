<?php

namespace Tests\Feature;

use App\Core\Backup\TenantBackupService;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\GiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * #192: the club's archive carries its gifts — redeemed, refunded and waiting — with the links still working after a
 * restore (the token is kept encrypted, its hash for looking it up), and their invoices, members and credit notes.
 */
class GiftBackupTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_a_restore_brings_the_gifts_back_with_their_links_and_state(): void
    {
        $club = $this->club();
        $plan = $this->plan($club, ['price' => 30]);
        $member = $this->member($club);
        [$redeemed, $refunded, $waiting] = $this->asTenant($club, function () use ($plan, $member) {
            $gifts = app(GiftService::class);
            $pay = app(PaymentService::class);
            $make = fn () => $gifts->issue($plan, ['giver_name' => 'Sara', 'recipient_name' => 'Noor', 'recipient_phone' => '95551234'], 'staff');
            $redeemed = $make();
            $pay->record($redeemed->invoice, '30.000', 'cash');
            $gifts->redeem($redeemed->fresh(), $member, today()->toDateString(), 'staff');
            $refunded = $make();
            $pay->record($refunded->invoice, '30.000', 'cash');
            app(CreditNoteService::class)->issue($refunded->invoice->fresh(), '30.000', 'Refund');

            return [$redeemed->fresh(), $refunded->fresh(), $make()];
        });
        $tokens = $this->asTenant($club, fn () => GiftMembership::orderBy('id')->get()->map->linkToken()->all());
        $before = $this->asTenant($club, fn () => GiftMembership::orderBy('id')->get(['id', 'status', 'member_id', 'membership_id', 'refund_note_id', 'recipient_phone'])->toArray());

        $service = app(TenantBackupService::class);
        $file = $service->create($club);
        $this->asTenant($club, fn () => GiftMembership::query()->update(['status' => GiftMembership::CANCELLED]));

        $counts = $service->restore($club, Storage::disk('local')->path($service->path($club, $file)));

        $this->assertSame(3, $counts['gym_gift_memberships']);
        $this->asTenant($club, function () use ($before, $tokens, $redeemed, $refunded, $waiting) {
            $this->assertSame($before, GiftMembership::orderBy('id')->get(['id', 'status', 'member_id', 'membership_id', 'refund_note_id', 'recipient_phone'])->toArray());
            foreach ($tokens as $i => $token) {
                $this->assertSame([$redeemed->id, $refunded->id, $waiting->id][$i], GiftMembership::findByToken($token)?->id, 'The link works after the restore.');
            }
            // Numbering continues after the restore.
            $this->assertNotSame($waiting->number, app(GiftService::class)->issue(MembershipPlan::first(), ['giver_name' => 'A', 'recipient_name' => 'B'], 'staff')->number);
        });
    }
}
