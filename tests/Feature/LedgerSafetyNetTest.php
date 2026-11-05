<?php

namespace Tests\Feature;

use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A finance record stands even when its posting fails; the books catch up later. These pin down that the miss is
 * seen (the bell of whoever keeps the books) and that the daily sync is not stopped by one record it cannot post.
 */
class LedgerSafetyNetTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function invoice(Tenant $club, string $price, string $type): Invoice
    {
        $member = $this->member($club);

        return $this->asTenant($club, fn () => app(InvoiceService::class)->issue($member, [['description' => 'Line', 'quantity' => 1, 'unit_price' => $price]], ['type' => $type]));
    }

    /** Stands in for any posting failure: the revenue account of membership invoices refuses postings. */
    private function breakMembershipRevenue(Tenant $club, bool $broken = true): void
    {
        $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('revenue_membership')->update(['is_active' => ! $broken]));
    }

    private function entries(Tenant $club, string $type, int $id): int
    {
        return $this->asTenant($club, fn () => JournalEntry::where(['source_type' => $type, 'source_id' => $id])->count());
    }

    public function test_a_posting_that_fails_reaches_the_bell_and_the_daily_sync_posts_it_once(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->asTenant($club, fn () => app(ChartOfAccounts::class)->ensure());
        $this->breakMembershipRevenue($club);

        $invoice = $this->invoice($club, '90', 'membership');

        // The invoice stands; its entry is missing, and the people who keep the books are told.
        $this->assertSame(Invoice::ISSUED, $invoice->fresh()->status);
        $this->assertSame(0, $this->entries($club, 'invoice', $invoice->id));
        $alert = $this->asTenant($club, fn () => StaffNotification::where('user_id', $owner->id)->where('key', 'ledger:invoice:'.$invoice->id)->first());
        $this->assertNotNull($alert, 'the owner is told the invoice is not in the books');
        $this->assertStringContainsString($invoice->number, $alert->text);
        $this->assertNull($alert->read_at);

        $this->breakMembershipRevenue($club, false);
        $this->assertSame(0, Artisan::call('accounting:sync'));
        $this->assertSame(0, Artisan::call('accounting:sync'));

        $this->assertSame(1, $this->entries($club, 'invoice', $invoice->id));
        $this->assertNotNull($alert->fresh()->read_at, 'once posted, the alert is settled');
        $this->asTenant($club, function () {
            $this->assertSame('90.000', app(FinanceReports::class)->balanceOf('receivable'));
            $this->assertSame((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit'));
        });
    }

    public function test_one_record_the_sync_cannot_post_does_not_keep_the_rest_out_of_the_books(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $stuck = $this->invoice($club, '50', 'membership');
        $sale = $this->invoice($club, '30', 'pos');
        $payment = $this->asTenant($club, fn () => app(PaymentService::class)->record($sale, 30, 'cash'));
        // As if the books had not existed yet, and one record cannot be posted.
        $this->asTenant($club, function () {
            JournalLine::query()->delete();
            JournalEntry::query()->delete();
        });
        $this->breakMembershipRevenue($club);

        $code = Artisan::call('accounting:sync');

        $this->assertSame(1, $code, 'the run says something was left out');
        $this->assertStringContainsString('1 failed', Artisan::output());
        $this->assertSame(0, $this->entries($club, 'invoice', $stuck->id));
        $this->assertSame(1, $this->entries($club, 'invoice', $sale->id));
        $this->assertSame(1, $this->entries($club, 'payment', $payment->id));
        $this->asTenant($club, fn () => $this->assertTrue(StaffNotification::where('user_id', $owner->id)->where('key', 'ledger:invoice:'.$stuck->id)->exists()));
    }
}
