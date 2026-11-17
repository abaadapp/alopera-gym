<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingPeriods;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Services\GiftService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * #192, before merging: a month does not close while a gift's move out of the liability that belongs to it is not in
 * the books (its posting failed, or the daily run that expires it did not happen), and a gift withdrawn by a credit
 * note never reads as paid back before finance records — and, where it waits, confirms — the refund.
 */
class GiftPeriodCloseTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function paidGift(Tenant $club, string $method = 'cash'): GiftMembership
    {
        $plan = $this->plan($club, ['price' => '30']);

        return $this->asTenant($club, function () use ($plan, $method) {
            $gift = app(GiftService::class)->issue($plan, ['giver_name' => 'Sara', 'recipient_name' => 'Noor', 'recipient_phone' => '91234567'], 'staff');
            $payment = app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), $method);
            $payment->status === Payment::PENDING && app(PaymentService::class)->confirm($payment);

            return $gift->fresh();
        });
    }

    private function lastMonth(): CarbonImmutable
    {
        return CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();
    }

    private function close(Tenant $club, CarbonImmutable $month): array
    {
        return $this->asTenant($club, fn () => app(AccountingPeriods::class)->closeThrough($month->format('Y-m'), $this->owner($club)));
    }

    private function assertNothingClosed(Tenant $club): void
    {
        $this->asTenant($club, function () {
            $this->assertSame(0, AccountingPeriod::where('status', AccountingPeriod::CLOSED)->count());
            $this->assertNull(app(AccountingPeriods::class)->lockedThrough());
        });
    }

    public function test_a_failed_gift_posting_stops_the_closing_and_once_posted_the_month_closes_with_it(): void
    {
        $club = $this->club();
        $month = $this->lastMonth();
        // The books refuse gift entries for now (a posting failure): the redemption stands, the entry does not.
        $failing = true;
        JournalEntry::creating(function (JournalEntry $entry) use (&$failing) {
            if ($failing && $entry->source_type === 'gift') {
                throw new RuntimeException('ledger unavailable');
            }
        });

        $this->travelTo($month->addDays(4));
        $gift = $this->paidGift($club);
        $this->travelTo($month->addDays(9)->setTime(11, 0));
        $this->asTenant($club, fn () => app(GiftService::class)->redeem($gift, $this->member($club), today()->toDateString(), 'staff'));
        $this->travelBack();

        $this->asTenant($club, function () use ($gift) {
            $this->assertSame(GiftMembership::REDEEMED, $gift->fresh()->status);
            $this->assertSame(0, JournalEntry::where('source_type', 'gift')->count());
        });

        // The month's checks name it as not in the books yet.
        $checks = $this->asTenant($club, fn () => app(AccountingPeriods::class)->checks($month->format('Y-m')));
        $this->assertStringContainsString($gift->number, implode(' ', $checks['pending']));

        // The closing tries to post it, fails again, and closes nothing.
        try {
            $this->close($club, $month);
            $this->fail('a month closed without a gift redeemed in it');
        } catch (BusinessRuleException $e) {
            $this->assertSame('period.errors', $e->rule);
            $this->assertStringContainsString($gift->number, $e->getMessage());
            $this->assertStringContainsString('لم تدخل الدفاتر بعد المزامنة', $e->getMessage());
        }
        $this->assertNothingClosed($club);

        // The books take it again: the closing posts it on its own date, in the month, and closes.
        $failing = false;
        $this->assertCount(1, $this->close($club, $month));
        $this->asTenant($club, function () use ($gift, $month) {
            $entry = JournalEntry::where(['source_type' => 'gift', 'source_id' => $gift->id, 'source_event' => 'redeem'])->sole();
            $this->assertSame($month->addDays(9)->toDateString(), CarbonImmutable::parse($entry->entry_date)->toDateString());
            $this->assertSame('0.000', app(FinanceReports::class)->balanceOf('gift_liability'));
            $this->assertSame('30.000', app(FinanceReports::class)->balanceOf('revenue_membership'));
        });
    }

    public function test_a_gift_past_its_window_but_not_marked_expired_stops_the_closing_until_the_daily_run(): void
    {
        $club = $this->club();
        $month = $this->lastMonth();
        $this->travelTo($month->addDays(1));
        $gift = $this->paidGift($club);
        $this->travelBack();
        // The daily run missed it: its window ended mid-month and it still reads paid, its income not in the books.
        $this->asTenant($club, fn () => $gift->fresh()->forceFill(['expires_on' => $month->addDays(9)->toDateString()])->save());

        $checks = $this->asTenant($club, fn () => app(AccountingPeriods::class)->checks($month->format('Y-m')));
        $this->assertStringContainsString($gift->number, implode(' ', $checks['errors']));

        try {
            $this->close($club, $month);
            $this->fail('a month closed with a gift past its window still paid');
        } catch (BusinessRuleException $e) {
            $this->assertSame('period.errors', $e->rule);
            $this->assertStringContainsString('انتهت مهلتها', $e->getMessage());
            $this->assertStringContainsString($gift->number, $e->getMessage());
        }
        $this->assertNothingClosed($club);

        // The daily run expires it and posts what is left of it as income (today, an open month); the month closes.
        Artisan::call('gym:daily-memberships');
        $this->assertCount(1, $this->close($club, $month));
        $this->asTenant($club, function () use ($gift) {
            $this->assertSame(GiftMembership::EXPIRED, $gift->fresh()->status);
            $entry = JournalEntry::where(['source_type' => 'gift', 'source_id' => $gift->id, 'source_event' => 'expire'])->sole();
            $this->assertSame(today()->toDateString(), CarbonImmutable::parse($entry->entry_date)->toDateString());
            $this->assertSame('30.000', app(FinanceReports::class)->balanceOf('revenue_gift_breakage'));
        });
    }

    public function test_a_gift_whose_window_ends_after_the_month_does_not_stop_its_closing(): void
    {
        $club = $this->club();
        $month = $this->lastMonth();
        $this->travelTo($month->addDays(1));
        $gift = $this->paidGift($club);
        $this->travelBack();
        // Its window ends on the month's last day: the run that expires it is the next day's, in the next month.
        $this->asTenant($club, fn () => $gift->fresh()->forceFill(['expires_on' => $month->endOfMonth()->toDateString()])->save());

        $this->assertCount(1, $this->close($club, $month));
    }

    public function test_a_gift_withdrawn_but_not_paid_back_closes_with_a_warning_the_books_have_its_note(): void
    {
        $club = $this->club();
        $month = $this->lastMonth();
        $this->travelTo($month->addDays(2));
        $gift = $this->paidGift($club);
        $note = $this->asTenant($club, fn () => app(CreditNoteService::class)->issue($gift->invoice->fresh(), '30.000', 'Buyer changed their mind'));
        $this->travelBack();

        $checks = $this->asTenant($club, fn () => app(AccountingPeriods::class)->checks($month->format('Y-m')));
        $this->assertSame([], $checks['errors']);
        $this->assertSame([], $checks['pending']);
        $this->assertStringContainsString($gift->number, implode(' ', $checks['warnings']));
        $this->assertStringContainsString('لم يكتمل ردّ مبلغها', implode(' ', $checks['warnings']));

        // Paid back: the warning goes.
        $this->asTenant($club, fn () => app(PaymentService::class)->refund($note, '30.000', 'cash'));
        $checks = $this->asTenant($club, fn () => app(AccountingPeriods::class)->checks($month->format('Y-m')));
        $this->assertStringNotContainsString($gift->number, implode(' ', $checks['warnings']));
        $this->assertCount(1, $this->close($club, $month));
    }

    public function test_a_credit_note_withdraws_the_gift_and_the_money_reads_back_only_once_refunded_and_confirmed(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        // Paid into the bank, so a refund from it can be confirmed.
        $gift = $this->paidGift($club, 'bank_transfer');
        $note = $this->asTenant($club, fn () => app(CreditNoteService::class)->issue($gift->invoice->fresh(), '30.000', 'Buyer changed their mind'));
        $state = fn () => $this->asTenant($club, fn () => $gift->fresh()->refundState());

        // The note alone: withdrawn, the buyer still owed the money.
        $this->asTenant($club, fn () => $this->assertSame(GiftMembership::REFUNDED, $gift->fresh()->status));
        $this->assertSame('أُلغيت بإشعار دائن', GiftMembership::STATUS_LABELS[GiftMembership::REFUNDED]);
        $this->assertSame(['due', '30.000', '0.000', '0.000'], [$state()['state'], $state()['due'], $state()['pending'], $state()['refunded']]);
        $this->actingAs($owner)->get(route('club.gifts.show', $gift))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('gift.status', 'refunded')
            ->where('gift.status_label', 'أُلغيت بإشعار دائن')
            ->where('gift.refund.state', 'due')
            ->where('gift.refund.due', '30.000')
            ->where('gift.refund.credit_note', $note->number));

        // A refund by bank transfer waits for confirmation: not paid back yet.
        $refund = $this->asTenant($club, fn () => app(PaymentService::class)->refund($note, '30.000', 'bank_transfer'));
        $this->assertSame(Payment::PENDING, $refund->status);
        $this->assertSame(['pending', '0.000', '30.000', '0.000'], [$state()['state'], $state()['due'], $state()['pending'], $state()['refunded']]);
        $this->actingAs($owner)->get(route('club.gifts.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('gifts.data.0.refund.state', 'pending'));

        // Confirmed: paid back.
        $this->asTenant($club, fn () => app(PaymentService::class)->confirm($refund->fresh()));
        $this->assertSame(['done', '0.000', '0.000', '30.000'], [$state()['state'], $state()['due'], $state()['pending'], $state()['refunded']]);

        // A gift not withdrawn has no money-back state.
        $other = $this->paidGift($club);
        $this->asTenant($club, fn () => $this->assertNull($other->fresh()->refundState()));
    }
}
