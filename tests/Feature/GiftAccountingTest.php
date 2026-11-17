<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
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

    public function test_a_gift_not_used_is_never_refunded_in_part_nothing_is_written_and_it_stays_usable(): void
    {
        $club = $this->club();
        $gift = $this->paidGift($club);

        $this->asTenant($club, function () use ($gift) {
            try {
                app(CreditNoteService::class)->issue($gift->invoice->fresh(), '10.000', 'Part refund', ['method' => 'cash']);
                $this->fail('A part of an unused gift was refunded.');
            } catch (BusinessRuleException $e) {
                $this->assertSame('gift.partial_refund', $e->rule);
                $this->assertSame('amount', $e->field);
            }
            $this->assertSame(0, CreditNote::count());
            $this->assertSame(0, Payment::where('kind', Payment::KIND_REFUND)->count());
            $this->assertSame(GiftMembership::PAID, $gift->fresh()->status);
            $this->assertSame(Invoice::PAID, $gift->invoice->fresh()->status);
        });
        $this->assertBooks($club, ['cash' => '30.000', 'gift_liability' => '30.000', 'revenue_gift_breakage' => '0.000', 'sales_returns' => '0.000']);
    }

    public function test_an_expired_gift_is_refunded_only_in_full_and_its_income_is_taken_back_once(): void
    {
        $club = $this->club();
        $gift = $this->paidGift($club);
        $this->travelTo(today()->addDays(91));
        Artisan::call('gym:daily-memberships');

        $this->asTenant($club, function () use ($gift) {
            try {
                app(CreditNoteService::class)->issue($gift->invoice->fresh(), '5.000', 'Goodwill part');
                $this->fail('A part of an expired gift was refunded.');
            } catch (BusinessRuleException $e) {
                $this->assertSame('gift.partial_refund', $e->rule);
            }
            $note = app(CreditNoteService::class)->issue($gift->invoice->fresh(), '30.000', 'Goodwill');
            app(PaymentService::class)->refund($note, '30.000', 'cash');
            $this->assertSame(GiftMembership::EXPIRED, $gift->fresh()->status, 'Its value already left the liability when it expired.');
        });

        $this->assertBooks($club, ['cash' => '0.000', 'gift_liability' => '0.000', 'revenue_gift_breakage' => '30.000', 'sales_returns' => '30.000']);
        $entries = $this->asTenant($club, fn () => JournalEntry::count());
        Artisan::call('accounting:sync');
        $this->asTenant($club, fn () => $this->assertSame($entries, JournalEntry::count(), 'No second expiry or close entry.'));
    }

    public function test_a_gift_waiting_for_the_rest_is_refunded_in_full_and_cancelled(): void
    {
        $club = $this->club();
        $plan = $this->plan($club, ['price' => 30]);
        $gift = $this->asTenant($club, function () use ($plan) {
            $gift = app(GiftService::class)->issue($plan, ['giver_name' => 'Sara', 'recipient_name' => 'Noor'], 'staff');
            app(PaymentService::class)->record($gift->invoice, '10.000', 'cash');

            return $gift->fresh();
        });

        $this->asTenant($club, function () use ($gift) {
            try {
                app(CreditNoteService::class)->issue($gift->invoice->fresh(), '10.000', 'Part');
                $this->fail('Refunded in part while waiting.');
            } catch (BusinessRuleException $e) {
                $this->assertSame('gift.partial_refund', $e->rule);
            }
            app(CreditNoteService::class)->issue($gift->invoice->fresh(), '30.000', 'Changed mind', ['method' => 'cash']);
            $this->assertSame(GiftMembership::CANCELLED, $gift->fresh()->status);
            $this->assertSame('invoice_credited', $gift->fresh()->cancel_reason);
            $this->assertSame(Invoice::CREDITED, $gift->invoice->fresh()->status);
        });
        $this->assertBooks($club, ['cash' => '0.000', 'receivable' => '0.000', 'gift_liability' => '0.000', 'sales_returns' => '0.000']);
    }

    public function test_cancelling_a_mistaken_payment_sends_the_gift_back_to_waiting_but_not_once_it_was_used(): void
    {
        $club = $this->club();
        $waiting = $this->paidGift($club);
        $used = $this->paidGift($club);

        $this->asTenant($club, function () use ($club, $waiting, $used) {
            app(PaymentService::class)->cancel(Payment::where('invoice_id', $waiting->invoice_id)->sole(), 'Recorded on the wrong invoice');
            $waiting = $waiting->fresh();
            $this->assertSame(GiftMembership::PENDING, $waiting->status);
            $this->assertNull($waiting->paid_at);
            $this->assertNull($waiting->expires_on);

            app(GiftService::class)->redeem($used, $this->member($club), today()->toDateString(), 'staff');
            try {
                app(PaymentService::class)->cancel(Payment::where('invoice_id', $used->invoice_id)->sole(), 'Mistake');
                $this->fail('The payment of a used gift was cancelled.');
            } catch (BusinessRuleException $e) {
                $this->assertSame('gift.redeemed_payment', $e->rule);
            }
            $this->assertSame(Payment::COMPLETED, Payment::where('invoice_id', $used->invoice_id)->sole()->status, 'Rolled back.');
            $this->assertSame(Invoice::PAID, $used->invoice->fresh()->status);
        });
        $this->assertBooks($club, ['cash' => '30.000', 'gift_liability' => '30.000', 'revenue_membership' => '30.000']);
    }

    public function test_a_gift_whose_invoice_was_paid_without_its_event_is_repaired_nightly_from_the_day_it_was_paid(): void
    {
        $club = $this->club();
        $plan = $this->plan($club, ['price' => 30]);
        $gift = $this->asTenant($club, function () use ($plan) {
            $gift = app(GiftService::class)->issue($plan, ['giver_name' => 'Sara', 'recipient_name' => 'Noor'], 'staff');
            // As after a rollback: the invoice was paid by code that did not know gifts.
            $gift->invoice->forceFill(['status' => Invoice::PAID, 'paid_total' => $gift->invoice->total, 'paid_at' => now()->subDays(3)])->saveQuietly();

            return $gift->fresh();
        });
        $this->assertSame(GiftMembership::PENDING, $gift->status);

        Artisan::call('gym:daily-memberships');
        Artisan::call('gym:daily-memberships');

        $this->asTenant($club, function () use ($gift) {
            $gift = $gift->fresh();
            $this->assertSame(GiftMembership::PAID, $gift->status);
            $this->assertSame(today()->subDays(3)->addDays(90)->toDateString(), $gift->expires_on->toDateString());
            $this->assertSame(1, AuditLog::where('event', 'gift.paid')->where('subject_id', $gift->id)->count());
        });
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
