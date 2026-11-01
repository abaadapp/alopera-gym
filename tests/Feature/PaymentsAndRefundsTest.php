<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Member;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Money that stays where it went and can be put right: a payment keeps the account it went into; an online payment
 * beyond what is owed is the customer's credit, applied or paid back once; a bank or Paymob refund waits until it is
 * confirmed made, holding its amount; a payment entered by mistake is cancelled with its entry reversed.
 */
class PaymentsAndRefundsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function invoiceFor($club, ?Member $member, string $price): Invoice
    {
        return $this->asTenant($club, fn () => app(InvoiceService::class)->issue($member, [['description' => 'خدمة', 'unit_price' => $price]], ['type' => 'other'], $member ? null : 'عميل'));
    }

    private function balance($club, string $key): string
    {
        return $this->asTenant($club, fn () => app(FinanceReports::class)->balanceOf($key));
    }

    private function assertBooksBalance(Tenant $club): void
    {
        $this->asTenant($club, fn () => $this->assertSame((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit')));
    }

    public function test_a_payment_and_an_expense_keep_the_account_their_money_moved_through(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $invoice = $this->invoiceFor($club, null, '40');
        [$drawer, $bank] = $this->asTenant($club, fn () => [
            Account::create(['parent_id' => app(ChartOfAccounts::class)->account('cash_and_banks')->id, 'code' => '1110', 'name' => 'صندوق الاستقبال', 'type' => 'asset', 'normal_side' => 'debit', 'kind' => 'cash', 'is_active' => true]),
            app(ChartOfAccounts::class)->account('bank'),
        ]);

        // A cash payment cannot land in a bank account; into the reception's drawer it can.
        $pay = fn (array $extra) => $this->actingAs($owner)->post(route('club.payments.store', $invoice), ['amount' => '40', 'method' => 'cash', 'client_key' => (string) Str::uuid()] + $extra);
        $pay(['account_id' => $bank->id])->assertSessionHasErrors('account_id');
        $pay(['account_id' => $drawer->id])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($drawer) {
            $payment = Payment::sole();
            $this->assertSame($drawer->id, $payment->account_id);
            $this->assertSame('40.000', app(FinanceReports::class)->balance($drawer));
        });

        // The finance settings pointing cash elsewhere later moves nothing that was recorded.
        $this->asTenant($club, fn () => $club->forceFill(['settings' => array_merge($club->settings ?? [], ['finance' => ['receipt' => ['cash' => $bank->id]]])])->save());
        $before = $this->asTenant($club, fn () => JournalEntry::count());
        Artisan::call('accounting:sync');
        $this->asTenant($club, fn () => $this->assertSame($before, JournalEntry::count()));
        $this->assertSame('0.000', $this->balance($club, 'cash'));

        // An expense paid from the drawer is taken from it; once recorded with a key, it is recorded once.
        $expense = ['description' => 'ماء', 'amount' => '3', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash', 'account_id' => $drawer->id, 'client_key' => (string) Str::uuid()];
        $this->actingAs($owner)->post(route('club.expenses.store'), $expense)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.expenses.store'), $expense)->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame([1, $drawer->id, '37.000'], [Expense::count(), Expense::sole()->account_id, app(FinanceReports::class)->balance($drawer)]));
        $this->actingAs($owner)->post(route('club.expenses.store'), ['payment_method' => 'bank_transfer', 'client_key' => (string) Str::uuid()] + $expense)->assertSessionHasErrors('account_id');
        $this->assertBooksBalance($club);
    }

    public function test_an_online_payment_beyond_what_is_owed_is_credit_applied_or_paid_back_once(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);
        $first = $this->invoiceFor($club, $member, '50');
        $second = $this->invoiceFor($club, $member, '25');
        $stranger = $this->invoiceFor($club, $this->member($club), '25');

        // Paid 30 at the desk, then the customer pays the original 50 online: 20 settles it, 30 is their credit.
        $online = $this->asTenant($club, function () use ($first) {
            app(PaymentService::class)->record($first, '30', 'cash');

            return app(PaymentService::class)->recordGatewayPayment($first->fresh(), '50', 'paymob', 'TX-1')['payment'];
        });
        $this->asTenant($club, function () use ($first, $online) {
            $this->assertSame([Invoice::PAID, '30.000', '30.000'], [$first->fresh()->status, $online->fresh()->credit_amount, $online->fresh()->creditLeft()]);
            $this->assertNotNull($online->fresh()->receipt_number);
        });
        // The receivable is the two other invoices only (25 + 25): the first is settled, its extra is owed back.
        $this->assertSame(['30.000', '50.000'], [$this->balance($club, 'customer_credits'), $this->balance($club, 'receivable')]);

        // Applied to another invoice of theirs (once, with the same key), not to someone else's.
        $apply = fn (Invoice $to, string $amount, ?string $key = null) => $this->actingAs($owner)->post(route('club.payments.allocate', $online), ['invoice_id' => $to->id, 'amount' => $amount, 'client_key' => $key ?? (string) Str::uuid()]);
        $apply($stranger, '10')->assertSessionHasErrors('invoice_id');
        $key = (string) Str::uuid();
        $apply($second, '10', $key)->assertSessionHasNoErrors();
        $apply($second, '10', $key)->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame([Invoice::PARTIALLY_PAID, '10.000', '20.000'], [$second->fresh()->status, $second->fresh()->paid_total, $online->fresh()->creditLeft()]));
        $this->assertSame('20.000', $this->balance($club, 'customer_credits'));

        // Paid back through Paymob: waits for confirmation, holding the amount; then made, once.
        $refund = fn (string $amount, string $method = 'paymob') => $this->actingAs($owner)->post(route('club.payments.refund-credit', $online), ['amount' => $amount, 'method' => $method, 'client_key' => (string) Str::uuid()]);
        $refund('25')->assertSessionHasErrors('amount');
        $refund('20')->assertSessionHasNoErrors();
        $refund('1', 'cash')->assertSessionHasErrors('amount');
        $pending = $this->asTenant($club, fn () => Payment::where('from_credit', true)->sole());
        $this->assertSame([Payment::PENDING, '20.000'], [$pending->status, $this->balance($club, 'customer_credits')]);

        $this->actingAs($owner)->post(route('club.payments.confirm', $pending), ['reference' => 'PMB-RF-7'])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($pending, $online) {
            $this->assertSame([Payment::COMPLETED, 'PMB-RF-7', '0.000'], [$pending->fresh()->status, $pending->fresh()->reference, $online->fresh()->creditLeft()]);
        });
        $this->assertSame(['0.000', '30.000'], [$this->balance($club, 'customer_credits'), $this->balance($club, 'paymob_clearing')]);
        $this->assertBooksBalance($club);
    }

    public function test_a_bank_or_paymob_refund_waits_until_it_is_made_and_holds_its_amount(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $invoice = $this->invoiceFor($club, null, '100');
        $note = $this->asTenant($club, function () use ($invoice) {
            app(PaymentService::class)->record($invoice, '100', 'cash');

            return app(CreditNoteService::class)->issue($invoice->fresh(), '40', 'خصم متفق عليه');
        });
        $refund = fn (array $data) => $this->actingAs($owner)->post(route('club.credit-notes.refund', $note), $data + ['client_key' => (string) Str::uuid()]);

        // No online payment on it: nothing to refund through Paymob.
        $refund(['amount' => '40', 'method' => 'paymob'])->assertSessionHasErrors('method');

        // By bank transfer: recorded, waiting, out of the books, its amount held.
        $refund(['amount' => '40', 'method' => 'bank_transfer'])->assertSessionHasNoErrors();
        $waiting = $this->asTenant($club, fn () => Payment::where('kind', Payment::KIND_REFUND)->sole());
        $this->asTenant($club, function () use ($waiting, $invoice, $note) {
            $this->assertSame([Payment::PENDING, '100.000', '0.000'], [$waiting->status, $invoice->fresh()->paid_total, $note->fresh()->refunded_total]);
            $this->assertNull($waiting->receipt_number);
        });
        $this->assertSame('100.000', $this->balance($club, 'cash'));
        $refund(['amount' => '1', 'method' => 'cash'])->assertSessionHasErrors('amount');

        // Cancelled: the amount is free again, and paid in cash it is made at once.
        $this->actingAs($owner)->post(route('club.payments.reject', $waiting), ['reason' => 'العميل فضّل النقد'])->assertSessionHasNoErrors();
        $refund(['amount' => '40', 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(['60.000', '40.000'], [$invoice->fresh()->paid_total, $note->fresh()->refunded_total]));
        $this->assertSame('60.000', $this->balance($club, 'cash'));
        $this->assertBooksBalance($club);
    }

    public function test_a_payment_entered_by_mistake_is_cancelled_with_its_entry_reversed(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $finance = $this->staff($club, 'finance');
        $invoice = $this->invoiceFor($club, null, '60');
        $payment = $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice, '60', 'cash'));
        $this->assertSame('60.000', $this->balance($club, 'cash'));

        // Its own permission (not given to the finance role), and a reason.
        $this->actingAs($finance)->post(route('club.payments.cancel', $payment), ['reason' => 'سُجّلت نقدًا وهي بطاقة'])->assertForbidden();
        $this->actingAs($owner)->post(route('club.payments.cancel', $payment), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post(route('club.payments.cancel', $payment), ['reason' => 'سُجّلت نقدًا وهي بطاقة'])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($payment, $invoice) {
            $this->assertSame(Payment::CANCELLED, $payment->fresh()->status);
            $this->assertSame([Invoice::ISSUED, '60.000'], [$invoice->fresh()->status, $invoice->fresh()->balance()]);
            $this->assertSame(1, JournalEntry::where(['source_type' => 'payment', 'source_id' => $payment->id, 'source_event' => 'cancel'])->count());
            $this->assertSame(1, DB::table('audit_logs')->where('event', 'payment.cancelled')->count());
        });
        $this->assertSame(['0.000', '60.000'], [$this->balance($club, 'cash'), $this->balance($club, 'receivable')]);
        $this->actingAs($owner)->post(route('club.payments.cancel', $payment), ['reason' => 'مرة أخرى'])->assertSessionHasErrors();
        // The invoice's paper beside it no longer lists it among the payments (the page loads every payment).
        $receipt = $this->asTenant($club, fn () => $payment->fresh()->receipt_number);
        $this->actingAs($owner)->get(route('club.invoices.show', $invoice))->assertOk()
            ->assertInertia(fn ($page) => $page->where('paper.html', fn ($html) => ! str_contains($html, $receipt))->where('invoice.payments.0.status', Payment::CANCELLED));
        Artisan::call('accounting:sync');
        $this->assertSame('0.000', $this->balance($club, 'cash'));

        // An online payment arrived for real: it is refunded, not cancelled.
        $online = $this->asTenant($club, fn () => app(PaymentService::class)->recordGatewayPayment($invoice->fresh(), '60', 'paymob', 'TX-9')['payment']);
        $this->actingAs($owner)->post(route('club.payments.cancel', $online), ['reason' => 'خطأ في التسجيل'])->assertSessionHasErrors();
        $this->assertBooksBalance($club);
    }

    public function test_opening_the_pay_link_twice_gives_the_same_checkout(): void
    {
        $club = $this->club();
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 9911], 201)]);
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'h', 'integration_ids' => '111'],
        ]));
        $invoice = $this->invoiceFor($club, $this->member($club), '25');

        $this->asTenant($club, function () use ($invoice) {
            $first = app(OnlinePaymentService::class)->start($invoice, 'portal');
            $again = app(OnlinePaymentService::class)->start($invoice->fresh(), 'whatsapp');
            $this->assertSame($first->id, $again->id);
            $this->assertSame(1, PaymentIntent::count());

            // What is owed changed (part paid at the desk): a new checkout for the new amount.
            app(PaymentService::class)->record($invoice, '5', 'cash');
            $this->assertNotSame($first->id, app(OnlinePaymentService::class)->start($invoice->fresh(), 'portal')->id);
        });
        Http::assertSentCount(2);
    }
}
