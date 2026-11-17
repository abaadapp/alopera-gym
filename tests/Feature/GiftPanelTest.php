<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * #192 in the club panel: «هدايا الاشتراكات» — who may see, issue, collect, redeem and cancel; a gift usable only once
 * paid in full; redemption for an existing or a new member, once; the plan's «متاحة للإهداء» switch and the window.
 */
class GiftPanelTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function roleWith(Tenant $club, array $permissions): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.uniqid(), 'name' => 'Custom', 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception');
        $user->forceFill(['role_id' => $role->id])->save();

        return $user->fresh();
    }

    private function issue(User $user, MembershipPlan $plan, array $data = []): TestResponse
    {
        return $this->actingAs($user)->post(route('club.gifts.store'), $data + [
            'membership_plan_id' => $plan->id, 'giver_name' => 'Sara', 'giver_phone' => '91234567', 'recipient_name' => 'Noor',
            'message' => 'Happy birthday', 'payment_method' => 'cash',
        ]);
    }

    private function onlyGift(Tenant $club): GiftMembership
    {
        return $this->asTenant($club, fn () => GiftMembership::with('invoice')->sole());
    }

    public function test_staff_see_and_do_only_what_their_permissions_allow(): void
    {
        $club = $this->club();
        $plan = $this->plan($club);
        $this->actingAs($this->staff($club, 'reception'))->get(route('club.gifts.index'))->assertForbidden();

        $viewer = $this->roleWith($club, ['gifts.view']);
        $this->actingAs($viewer)->get(route('club.gifts.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Gym/Gifts/Index')->where('plans', [])->where('methods', []));
        $this->issue($viewer, $plan)->assertForbidden();

        // May issue, but not take money: «لاحقًا» only.
        $issuer = $this->roleWith($club, ['gifts.view', 'gifts.create']);
        $this->issue($issuer, $plan)->assertForbidden();
        $this->issue($issuer, $plan, ['payment_method' => 'later'])->assertRedirect();
        $gift = $this->onlyGift($club);
        $this->assertSame(GiftMembership::PENDING, $gift->status);
        $this->actingAs($issuer)->post(route('club.gifts.pay', $gift), ['method' => 'cash'])->assertForbidden();
        $this->actingAs($issuer)->post(route('club.gifts.redeem', $gift), ['mode' => 'existing', 'member_id' => $this->member($club)->id, 'starts_on' => today()->toDateString()])->assertForbidden();
        $this->actingAs($issuer)->post(route('club.gifts.cancel', $gift), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($issuer)->get(route('club.gifts.members', ['q' => 'Me']))->assertForbidden();
    }

    public function test_a_gift_paid_at_the_desk_is_ready_to_share_and_one_left_for_later_is_not(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $plan = $this->plan($club, ['price' => 30]);

        $this->issue($owner, $plan)->assertSessionHasNoErrors();
        $gift = $this->onlyGift($club);
        $this->assertSame(GiftMembership::PAID, $gift->status);
        $this->assertSame(Invoice::PAID, $gift->invoice->status);
        $this->assertSame('96891234567', $gift->giver_phone, 'Stored normalized, as Paymob and WhatsApp need it.');

        $this->actingAs($owner)->get(route('club.gifts.show', $gift))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Gym/Gifts/Show')
                ->where('gift.status', 'paid')->where('gift.status_label_en', 'Paid — not used')
                ->where('link', fn ($link) => str_contains($link, '/gift/') && str_contains($link, $this->asTenant($club, fn () => $gift->fresh()->linkToken())))
                ->where('whatsapp', fn ($url) => str_starts_with($url, 'https://wa.me/?text=')));

        // The list never carries the link's token.
        $index = $this->actingAs($owner)->get(route('club.gifts.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString($this->asTenant($club, fn () => $gift->fresh()->linkToken()), $index);
    }

    public function test_a_bank_transfer_waits_for_finance_and_a_later_gift_is_collected_from_its_page(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $plan = $this->plan($club, ['price' => 30]);

        $this->issue($owner, $plan, ['payment_method' => 'bank_transfer'])->assertSessionHasNoErrors();
        $transfer = $this->onlyGift($club);
        $this->assertSame(GiftMembership::PENDING, $transfer->status, 'A transfer awaiting confirmation issues nothing.');
        $this->actingAs($owner)->get(route('club.gifts.show', $transfer))
            ->assertInertia(fn ($page) => $page->where('link', null)->where('pending_transfer', true));
        $this->asTenant($club, fn () => app(PaymentService::class)->confirm(Payment::where('invoice_id', $transfer->invoice_id)->sole()));
        $this->asTenant($club, fn () => $this->assertSame(GiftMembership::PAID, $transfer->fresh()->status));

        $this->issue($owner, $plan, ['payment_method' => 'later']);
        $later = $this->asTenant($club, fn () => GiftMembership::latest('id')->first());
        $this->assertSame(GiftMembership::PENDING, $later->status);
        $this->actingAs($owner)->post(route('club.gifts.pay', $later), ['method' => 'card'])->assertSessionHasErrors('reference');
        $this->actingAs($owner)->post(route('club.gifts.pay', $later), ['method' => 'cash'])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(GiftMembership::PAID, $later->fresh()->status));
        $this->actingAs($owner)->post(route('club.gifts.pay', $later), ['method' => 'cash'])->assertSessionHasErrors('error');
        $this->asTenant($club, fn () => $this->assertSame(1, Payment::where('invoice_id', $later->invoice_id)->count(), 'Collected once.'));
    }

    public function test_staff_redeem_for_an_existing_member_once_and_the_log_says_who(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->issue($owner, $this->plan($club));
        $gift = $this->onlyGift($club);
        $member = $this->member($club);
        $start = today()->addDays(5)->toDateString();

        $this->actingAs($owner)->post(route('club.gifts.redeem', $gift), ['mode' => 'existing', 'member_id' => $member->id, 'starts_on' => $start])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->asTenant($club, function () use ($gift, $owner, $start) {
            $gift = $gift->fresh();
            $this->assertSame(GiftMembership::REDEEMED, $gift->status);
            $this->assertSame($owner->id, $gift->redeemed_by);
            $this->assertSame('staff', $gift->redeemed_via);
            $this->assertSame(Membership::SCHEDULED, $gift->membership->status);
            $this->assertSame($start, $gift->membership->starts_on->toDateString());
            $this->assertDatabaseHas('audit_logs', ['event' => 'gift.redeemed', 'subject_id' => $gift->id, 'actor_id' => $owner->id]);
        });

        $this->actingAs($owner)->post(route('club.gifts.redeem', $gift), ['mode' => 'new', 'full_name' => 'Second', 'phone' => '92223333', 'starts_on' => today()->toDateString()])
            ->assertSessionHasErrors(['error' => 'استُخدمت هذه الهدية من قبل.']);
        $this->asTenant($club, function () {
            $this->assertSame(1, Membership::count());
            $this->assertFalse(Member::where('full_name', 'Second')->exists(), 'A refused redemption leaves no new member behind.');
        });
    }

    public function test_staff_redeem_for_a_new_member_created_on_the_spot(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->issue($owner, $this->plan($club));
        $gift = $this->onlyGift($club);

        $this->actingAs($owner)->post(route('club.gifts.redeem', $gift), [
            'mode' => 'new', 'full_name' => 'Noor Gift', 'phone' => '93334444', 'starts_on' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($gift) {
            $member = Member::where('full_name', 'Noor Gift')->sole();
            $this->assertSame($member->id, $gift->fresh()->member_id);
            $this->assertSame(Membership::ACTIVE, $member->memberships()->sole()->status);
            $this->assertSame('0.000', $member->memberships()->sole()->invoice->total);
        });
    }

    public function test_cancelling_keeps_the_record_and_voids_only_an_unpaid_invoice(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $plan = $this->plan($club);
        $this->issue($owner, $plan, ['payment_method' => 'later']);
        $gift = $this->onlyGift($club);

        $this->actingAs($owner)->post(route('club.gifts.cancel', $gift), [])->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post(route('club.gifts.cancel', $gift), ['reason' => 'Mistake'])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($gift) {
            $this->assertSame(GiftMembership::CANCELLED, $gift->fresh()->status);
            $this->assertSame(Invoice::VOID, $gift->invoice->fresh()->status);
            $this->assertSame('Mistake', $gift->fresh()->cancel_reason);
        });
        $this->actingAs($owner)->get(route('club.gifts.index', ['filter' => 'cancelled']))
            ->assertInertia(fn ($page) => $page->has('gifts.data', 1)->where('gifts.data.0.status_label', 'ملغاة'));
    }

    public function test_only_plans_open_to_gifts_can_be_issued_and_the_switch_is_saved_from_the_plan_form(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $plan = $this->plan($club);

        $this->actingAs($owner)->put(route('club.plans.update', $plan), [
            'name' => $plan->name, 'allows_gym' => true, 'allows_classes' => true, 'duration_value' => 1, 'duration_unit' => 'month',
            'price' => 25, 'giftable' => false, 'is_active' => true, 'is_public' => true,
        ])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertFalse($plan->fresh()->giftable));

        $this->issue($owner, $plan->fresh())->assertSessionHasErrors('membership_plan_id');
        $this->actingAs($owner)->get(route('club.gifts.index'))->assertInertia(fn ($page) => $page->where('plans', []));
    }

    public function test_the_redemption_window_is_a_club_setting(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->actingAs($owner)->get(route('club.settings.edit'))->assertInertia(fn ($page) => $page->where('rules.gift_redeem_days', 90));

        $club->forceFill(['settings' => array_merge($club->settings ?? [], ['gift_redeem_days' => 45])])->save();
        $this->issue($owner, $this->plan($club));
        $this->assertSame(today()->addDays(45)->toDateString(), $this->onlyGift($club)->expires_on->toDateString());
    }

    public function test_another_clubs_gift_is_not_found(): void
    {
        $club = $this->club('A');
        $other = $this->club('B');
        $this->issue($this->owner($club), $this->plan($club));
        $gift = $this->onlyGift($club);

        $this->actingAs($this->owner($other))->get(route('club.gifts.show', $gift))->assertNotFound();
        $this->actingAs($this->owner($other))->post(route('club.gifts.redeem', $gift), ['mode' => 'new', 'full_name' => 'X', 'phone' => '94445555', 'starts_on' => today()->toDateString()])->assertNotFound();
        $this->asTenant($club, fn () => $this->assertSame(GiftMembership::PAID, $gift->fresh()->status));
    }
}
