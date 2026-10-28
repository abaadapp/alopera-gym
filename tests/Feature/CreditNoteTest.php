<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Partial refunds: the invoice keeps its total, a credit note lowers what is owed, money back is separate. */
class CreditNoteTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function invoice($club, string $price = '100'): Invoice
    {
        $member = $this->member($club);

        return $this->asTenant($club, fn () => app(InvoiceService::class)->issue($member, [['description' => 'Service', 'quantity' => 1, 'unit_price' => $price]]));
    }

    public function test_a_credit_note_on_an_unpaid_invoice_lowers_the_balance_and_keeps_the_invoice(): void
    {
        $club = $this->club();
        $invoice = $this->invoice($club);

        $this->asTenant($club, function () use ($invoice) {
            $note = app(CreditNoteService::class)->issue($invoice, 30, 'Agreed discount');
            $invoice = $invoice->fresh();

            $this->assertMatchesRegularExpression('/^CN-\d{4}-\d{6}$/', $note->number);
            $this->assertSame('100.000', $invoice->total, 'the original total never changes');
            $this->assertSame('30.000', $invoice->credited_total);
            $this->assertSame('70.000', $invoice->balance());
            $this->assertSame(Invoice::ISSUED, $invoice->status);
            $this->assertSame(0, Payment::where('kind', Payment::KIND_REFUND)->count(), 'nothing was paid, so nothing goes back');

            // Cannot credit more than the net value, and cancelling all of an unpaid invoice is a void.
            $this->expectException(BusinessRuleException::class);
            app(CreditNoteService::class)->issue($invoice, 70, 'All of it');
        });
    }

    public function test_refund_is_a_separate_capped_step_and_cannot_be_repeated(): void
    {
        $club = $this->club();
        $invoice = $this->invoice($club);

        $this->asTenant($club, function () use ($invoice) {
            app(PaymentService::class)->record($invoice, 100, 'cash');
            $note = app(CreditNoteService::class)->issue($invoice, 30, 'Partial refund');
            $invoice = $invoice->fresh();

            $this->assertSame(Invoice::PAID, $invoice->status);
            $this->assertSame('30.000', $invoice->refundDue(), 'issuing the note does not move money by itself');
            $this->assertSame('100.000', $invoice->paid_total);

            $refund = app(PaymentService::class)->refund($note, 30, 'cash');
            $this->assertSame($note->id, $refund->credit_note_id);
            $this->assertSame('70.000', $invoice->fresh()->paid_total);
            $this->assertSame('0.000', $invoice->fresh()->refundDue());
            $this->assertSame('30.000', $note->fresh()->refunded_total);

            try {
                app(PaymentService::class)->refund($note, 1, 'cash');
                $this->fail('A second refund on the same note must be refused.');
            } catch (BusinessRuleException $e) {
                $this->assertSame('refund.nothing', $e->rule);
            }
            $this->assertSame(1, Payment::where('kind', Payment::KIND_REFUND)->count());
        });
    }

    public function test_refund_cannot_exceed_what_was_overpaid_even_if_the_note_is_bigger(): void
    {
        $club = $this->club();
        $invoice = $this->invoice($club);

        $this->asTenant($club, function () use ($invoice) {
            app(PaymentService::class)->record($invoice, 40, 'cash');
            $note = app(CreditNoteService::class)->issue($invoice, 50, 'Price correction'); // net 50, paid 40: nothing overpaid
            $this->assertSame('10.000', $invoice->fresh()->balance());
            $this->assertSame('0.000', $invoice->fresh()->refundDue());

            $this->expectException(BusinessRuleException::class);
            app(PaymentService::class)->refund($note, 5, 'cash');
        });
    }

    public function test_a_note_that_settles_the_remainder_activates_the_membership(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 50]);

        $this->asTenant($club, function () use ($member, $plan) {
            $membership = app(MembershipService::class)->sell($member, $plan);
            $invoice = $membership->invoice()->first();
            app(PaymentService::class)->record($invoice, Money::sub($invoice->total, '10'), 'cash');
            $this->assertSame(Membership::PENDING, $membership->fresh()->status);

            app(CreditNoteService::class)->issue($invoice, 10, 'Discount on the remainder');

            $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
            $this->assertSame(Membership::ACTIVE, $membership->fresh()->status);
        });
    }

    public function test_issue_and_refund_over_http_with_permissions_and_audit(): void
    {
        $club = $this->club();
        $invoice = $this->invoice($club);
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice, 100, 'cash'));
        $finance = $this->staff($club, 'finance');

        $this->actingAs($this->staff($club, 'reception'))
            ->post(route('club.credit-notes.store', $invoice), ['amount' => 20, 'reason' => 'x'])->assertForbidden();

        $this->actingAs($finance)->post(route('club.credit-notes.store', $invoice), [
            'amount' => 20, 'reason' => 'Late class cancellation', 'refund_now' => true, 'method' => 'card',
        ])->assertSessionHasErrors('reference');

        $this->actingAs($finance)->post(route('club.credit-notes.store', $invoice), [
            'amount' => 20, 'reason' => 'Late class cancellation', 'refund_now' => true, 'method' => 'card', 'reference' => 'TRX-77',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($invoice) {
            $note = CreditNote::firstOrFail();
            $this->assertSame('20.000', $note->refunded_total);
            $this->assertSame('TRX-77', $note->refunds()->value('reference'));
            $this->assertSame('80.000', $invoice->fresh()->paid_total);
            $this->assertDatabaseHas('audit_logs', ['event' => 'invoice.credited']);
            $this->assertDatabaseHas('audit_logs', ['event' => 'payment.refunded']);
        });

        $this->actingAs($finance)->get(route('club.credit-notes.print', $this->asTenant($club, fn () => CreditNote::value('id'))))->assertOk();
    }

    public function test_outstanding_report_counts_net_of_credit_notes(): void
    {
        $club = $this->club();
        $invoice = $this->invoice($club);

        $this->asTenant($club, function () use ($invoice) {
            app(CreditNoteService::class)->issue($invoice, 25, 'Discount');
            $this->assertEquals(75, (float) app(ReportService::class)->dashboard()['kpis']['outstanding']);
        });
    }

    public function test_credit_notes_are_isolated_per_club(): void
    {
        $club = $this->club('A');
        $other = $this->club('B');
        $invoice = $this->invoice($club);
        $this->asTenant($club, function () use ($invoice) {
            app(PaymentService::class)->record($invoice, 100, 'cash');
            app(CreditNoteService::class)->issue($invoice, 10, 'x');
        });
        $noteId = $this->asTenant($club, fn () => CreditNote::value('id'));

        $this->actingAs($this->owner($other))->get(route('club.credit-notes.print', $noteId))->assertNotFound();
        $this->actingAs($this->owner($other))->post(route('club.credit-notes.refund', $noteId), ['amount' => 1, 'method' => 'cash'])->assertNotFound();
        $this->actingAs($this->owner($other))->post(route('club.credit-notes.store', $invoice->id), ['amount' => 1, 'reason' => 'x'])->assertNotFound();
    }
}
