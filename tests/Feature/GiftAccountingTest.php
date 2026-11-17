<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Services\GiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * #192 in the books: a gift sold and not used is a liability (deferred revenue), not revenue. It becomes membership
 * revenue when redeemed, expired-gift income when its window passes, and a credit note takes it back out of the
 * liability. VAT is charged once, on the gift's invoice. Every amount is posted once, and a replay changes nothing.
 */
class GiftAccountingTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function balance(Tenant $club, string $key): string
    {
        return $this->asTenant($club, fn () => app(FinanceReports::class)->balanceOf($key));
    }

    private function assertBooks(Tenant $club, array $expected): void
    {
        foreach ($expected as $key => $amount) {
            $this->assertSame($amount, $this->balance($club, $key), $key);
        }
        $this->asTenant($club, fn () => $this->assertSame((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit'), 'debits equal credits'));
    }

    private function paidGift(Tenant $club, string $price = '30'): GiftMembership
    {
        $plan = $this->plan($club, ['price' => $price]);

        return $this->asTenant($club, function () use ($plan) {
            $gift = app(GiftService::class)->issue($plan, ['giver_name' => 'Sara', 'recipient_name' => 'Noor'], 'staff');
            app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash');

            return $gift->fresh();
        });
    }

    private function vatClub(): Tenant
    {
        $club = $this->club();
        $club->forceFill(['settings' => array_merge($club->settings ?? [], ['vat_registered' => true, 'vat_rate' => 5])])->save();

        return $club->fresh();
    }

    public function test_a_paid_gift_is_a_liability_until_it_is_redeemed_then_membership_revenue(): void
    {
        $club = $this->club();
        $gift = $this->paidGift($club);
        $this->assertBooks($club, ['cash' => '30.000', 'receivable' => '0.000', 'gift_liability' => '30.000',
            'revenue_membership' => '0.000', 'revenue_other' => '0.000']);

        $this->asTenant($club, fn () => app(GiftService::class)->redeem($gift, $this->member($club), today()->toDateString(), 'link'));
        $this->assertBooks($club, ['cash' => '30.000', 'gift_liability' => '0.000', 'revenue_membership' => '30.000', 'receivable' => '0.000']);
    }

    public function test_vat_is_charged_once_on_the_gift_invoice_and_redemption_moves_only_the_net(): void
    {
        $club = $this->vatClub();
        $gift = $this->paidGift($club, '100');
        $this->asTenant($club, fn () => $this->assertSame('105.000', $gift->invoice->total));
        $this->assertBooks($club, ['cash' => '105.000', 'vat_payable' => '5.000', 'gift_liability' => '100.000']);

        $this->asTenant($club, fn () => app(GiftService::class)->redeem($gift, $this->member($club), today()->toDateString(), 'staff'));
        $this->assertBooks($club, ['vat_payable' => '5.000', 'gift_liability' => '0.000', 'revenue_membership' => '100.000']);
    }

    public function test_an_expired_gift_leaves_the_liability_as_expired_gift_income(): void
    {
        $club = $this->club();
        $gift = $this->paidGift($club);
        $this->travelTo(today()->addDays(91));

        Artisan::call('gym:daily-memberships');

        $this->asTenant($club, fn () => $this->assertSame(GiftMembership::EXPIRED, $gift->fresh()->status));
        $this->assertBooks($club, ['gift_liability' => '0.000', 'revenue_gift_breakage' => '30.000', 'revenue_membership' => '0.000']);
    }

    public function test_a_full_refund_of_an_unused_gift_comes_out_of_the_liability_not_revenue(): void
    {
        $club = $this->vatClub();
        $gift = $this->paidGift($club, '100');

        $this->asTenant($club, function () use ($gift) {
            $note = app(CreditNoteService::class)->issue($gift->invoice->fresh(), '105.000', 'Buyer changed their mind');
            app(PaymentService::class)->refund($note, '105.000', 'cash');
            $this->assertSame(GiftMembership::REFUNDED, $gift->fresh()->status);
            $this->assertSame($note->id, $gift->fresh()->refund_note_id);
        });

        $this->assertBooks($club, ['cash' => '0.000', 'receivable' => '0.000', 'vat_payable' => '0.000', 'gift_liability' => '0.000',
            'sales_returns' => '0.000', 'revenue_gift_breakage' => '0.000']);
    }

    public function test_a_partial_refund_ends_the_gift_and_what_is_kept_is_income(): void
    {
        $club = $this->club();
        $gift = $this->paidGift($club);

        $this->asTenant($club, function () use ($gift) {
            $note = app(CreditNoteService::class)->issue($gift->invoice->fresh(), '10.000', 'Part refund');
            app(PaymentService::class)->refund($note, '10.000', 'cash');
        });

        $this->asTenant($club, fn () => $this->assertSame(GiftMembership::REFUNDED, $gift->fresh()->status, 'Part of the money went back: the gift is not used.'));
        $this->assertBooks($club, ['cash' => '20.000', 'gift_liability' => '0.000', 'revenue_gift_breakage' => '20.000', 'sales_returns' => '0.000']);
    }

    public function test_a_credit_note_after_redemption_comes_out_of_revenue_like_any_membership(): void
    {
        $club = $this->club();
        $gift = $this->paidGift($club);

        $this->asTenant($club, function () use ($club, $gift) {
            app(GiftService::class)->redeem($gift, $this->member($club), today()->toDateString(), 'link');
            $this->travel(1)->minutes();
            app(CreditNoteService::class)->issue($gift->invoice->fresh(), '5.000', 'Goodwill');
            $this->assertSame(GiftMembership::REDEEMED, $gift->fresh()->status);
        });

        $this->assertBooks($club, ['gift_liability' => '0.000', 'revenue_membership' => '30.000', 'sales_returns' => '5.000']);
    }

    public function test_the_books_are_rebuilt_the_same_by_the_sync_and_a_replay_adds_nothing(): void
    {
        $club = $this->club();
        $redeemed = $this->paidGift($club);
        $this->paidGift($club); // still owed
        $refunded = $this->paidGift($club);
        $this->asTenant($club, function () use ($club, $redeemed, $refunded) {
            app(GiftService::class)->redeem($redeemed, $this->member($club), today()->toDateString(), 'link');
            app(CreditNoteService::class)->issue($refunded->invoice->fresh(), '30.000', 'Refund');
        });
        $expected = ['gift_liability' => '30.000', 'revenue_membership' => '30.000', 'receivable' => '-30.000', 'cash' => '90.000'];
        $this->assertBooks($club, $expected);
        $entries = $this->asTenant($club, fn () => JournalEntry::count());

        Artisan::call('accounting:sync');
        $this->asTenant($club, fn () => $this->assertSame($entries, JournalEntry::count(), 'Nothing posted twice.'));

        $this->asTenant($club, function () {
            JournalLine::query()->delete();
            JournalEntry::query()->delete();
        });
        Artisan::call('accounting:sync');
        $this->assertBooks($club, $expected);
        $this->asTenant($club, fn () => $this->assertSame($entries, JournalEntry::count()));
    }
}
