<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\PosService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentReview;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Owner's decision (PR #217, option C): an invoice whose Paymob money is under review (PaymentReview, open) gets no
 * credit note, in part or in full — from the credit-note form, a website order cancelled (paid, or part-credited), or a
 * point-of-sale sale voided — until the review is closed on Paymob's evidence. Other invoices, closed reviews, notes
 * already issued, permissions and branches stay as they were; refusing writes nothing and closes nothing.
 */
class CreditNotePaymentReviewTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'credit-review-hmac';

    private int $order = 7700;

    private function paymobClub(string $name = 'Club'): Tenant
    {
        Http::fake(['*/v1/intention/' => fn () => Http::response(['client_secret' => 'cs_c', 'intention_order_id' => ++$this->order], 201)]);
        $club = $this->club($name);
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));

        return $club;
    }

    /** @return array{0: Membership, 1: PaymentIntent} a 25.000 membership waiting for its online payment */
    private function pendingMembership(Tenant $club): array
    {
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        return $this->asTenant($club, function () use ($member, $plan) {
            $membership = app(MembershipService::class)->sell($member, $plan);

            return [$membership, app(OnlinePaymentService::class)->start($membership->invoice, 'portal')];
        });
    }

    /** Paymob's signed confirmation for $intent, $less baisa short of what was asked (0: exactly what was asked). */
    private function deliver(PaymentIntent $intent, int $less, int $transaction, string $outcome): void
    {
        $this->app->forgetScopedInstances();
        $obj = [
            'id' => $transaction, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount) - $less, 'success' => true, 'is_auth' => false,
            'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true,
            'integration_id' => 111, 'has_parent_transaction' => false, 'order' => ['id' => (int) $intent->provider_order_id, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-20T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'],
        ];
        $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, self::HMAC), ['type' => 'TRANSACTION', 'obj' => $obj])
            ->assertOk()->assertJson(['outcome' => $outcome]);
    }

    /** A review written as the webhook writes it, on any invoice (a paid one, a sale's): open unless said otherwise. */
    private function review(Tenant $club, int $invoiceId, string $status = PaymentReview::OPEN): PaymentReview
    {
        return $this->asTenant($club, function () use ($invoiceId, $status) {
            $invoice = Invoice::findOrFail($invoiceId);
            $intent = PaymentIntent::create([
                'invoice_id' => $invoice->id, 'provider' => 'paymob', 'reference' => 'PL-CREDREV-'.Str::upper(Str::random(8)),
                'amount' => $invoice->total, 'currency' => 'OMR', 'status' => PaymentIntent::EXPIRED, 'channel' => 'whatsapp', 'expires_at' => now()->subDays(2),
            ]);

            return PaymentReview::create([
                'payment_intent_id' => $intent->id, 'invoice_id' => $invoice->id, 'gateway' => 'paymob', 'transaction_id' => 'cr-'.Str::lower(Str::random(12)),
                'reason' => 'amount', 'expected_minor' => Money::toMinor($invoice->total), 'received_minor' => Money::toMinor($invoice->total) - 10,
                'expected_currency' => 'OMR', 'received_currency' => 'OMR', 'status' => $status,
            ] + ($status === PaymentReview::OPEN ? [] : ['closed_at' => now()]));
        });
    }

    /** @return array<string, mixed> everything a note or its refund would change */
    private function books(Tenant $club, int $invoiceId): array
    {
        return $this->asTenant($club, fn () => [
            'invoice' => Invoice::find($invoiceId)->only('status', 'paid_total', 'credited_total', 'total') + ['paid_at' => Invoice::find($invoiceId)->paid_at?->toIso8601String()],
            'payments' => Payment::where('invoice_id', $invoiceId)->orderBy('id')->get(['id', 'kind', 'status', 'amount', 'credit_used'])->toArray(),
            'notes' => CreditNote::where('invoice_id', $invoiceId)->orderBy('id')->get(['id', 'number', 'amount', 'tax_amount', 'refunded_total'])->toArray(),
            'journal' => JournalEntry::count(),
            'audit' => DB::table('audit_logs')->where('event', 'invoice.credited')->count(),
            'open_reviews' => PaymentReview::where('invoice_id', $invoiceId)->open()->count(),
        ]);
    }

    private function refusedBy(callable $issue): string
    {
        try {
            $issue();
        } catch (BusinessRuleException $e) {
            $this->assertSame(PaymentReview::CREDIT_REFUSED, $e->getMessage());

            return $e->rule;
        }
        $this->fail('a credit note was issued while its Paymob money is under review');
    }

    private function noteForm(string $amount, array $over = []): array
    {
        return ['amount' => $amount, 'reason' => 'خصم متفق عليه', 'client_key' => (string) Str::uuid()] + $over;
    }

    public function test_no_credit_note_in_part_or_in_full_from_the_invoice_screen_while_its_money_is_under_review(): void
    {
        $club = $this->paymobClub();
        $owner = $this->owner($club);
        [$membership, $intent] = $this->pendingMembership($club);
        $this->deliver($intent, 10, 670001, 'amount_mismatch');
        $before = $this->books($club, $membership->invoice_id);
        $this->assertSame(1, $before['open_reviews']);

        // In part, and in full (which, untouched and unpaid, would otherwise be sent to «إلغاء الفاتورة»).
        foreach (['5.000', '25.000'] as $amount) {
            $this->actingAs($owner)->from('/app')->post(route('club.credit-notes.store', $membership->invoice_id), $this->noteForm($amount))
                ->assertSessionHasErrors(['error' => PaymentReview::CREDIT_REFUSED]);
        }
        // The service itself, as any other caller would reach it.
        $this->assertSame('credit.payment_review', $this->refusedBy(fn () => $this->asTenant($club, fn () => app(CreditNoteService::class)->issue(Invoice::findOrFail($membership->invoice_id), '5.000', 'خصم'))));

        // Nothing written: no note, no entry, the invoice and its dues as they were; the review open, the membership waiting.
        $this->assertSame($before, $this->books($club, $membership->invoice_id));
        $this->asTenant($club, fn () => $this->assertSame(Membership::PENDING, $membership->fresh()->status));
    }

    public function test_a_paid_invoice_is_not_credited_with_its_refund_nor_the_rest_of_a_part_credited_one(): void
    {
        $club = $this->paymobClub();
        $owner = $this->owner($club);

        // Paid in cash, then a second Paymob attempt on it under review: the full note with its refund is refused.
        [$paid] = $this->pendingMembership($club);
        $this->asTenant($club, fn () => app(PaymentService::class)->record(Invoice::findOrFail($paid->invoice_id), '25.000', 'cash'));
        $this->review($club, $paid->invoice_id);
        $before = $this->books($club, $paid->invoice_id);
        $this->assertSame(Invoice::PAID, $before['invoice']['status']);
        $this->actingAs($owner)->from('/app')->post(route('club.credit-notes.store', $paid->invoice_id), $this->noteForm('25.000', ['refund_now' => true, 'method' => 'cash']))
            ->assertSessionHasErrors(['error' => PaymentReview::CREDIT_REFUSED]);
        $this->assertSame($before, $this->books($club, $paid->invoice_id), 'no note, no refund');

        // A note issued before the review stays as it is; the rest is not credited while the review is open.
        [$part] = $this->pendingMembership($club);
        $this->asTenant($club, fn () => app(CreditNoteService::class)->issue(Invoice::findOrFail($part->invoice_id), '5.000', 'خصم'));
        $this->review($club, $part->invoice_id);
        $before = $this->books($club, $part->invoice_id);
        $this->assertSame('5.000', $before['invoice']['credited_total']);
        $this->actingAs($owner)->from('/app')->post(route('club.credit-notes.store', $part->invoice_id), $this->noteForm('20.000'))
            ->assertSessionHasErrors(['error' => PaymentReview::CREDIT_REFUSED]);
        $this->assertSame($before, $this->books($club, $part->invoice_id));
    }

    public function test_cancelling_a_paid_or_part_credited_website_order_and_voiding_a_pos_sale_issue_no_note(): void
    {
        $club = $this->paymobClub();
        $this->owner($club);
        $product = $this->asTenant($club, function () {
            $product = Product::create(['name' => 'Protein bar', 'price' => 1.575, 'show_on_website' => true]);
            app(StockService::class)->move($product, 10, 'adjustment', null, 'opening', 0.8);

            return $product;
        });
        $order = function () use ($club, $product): array {
            $this->post(route('site.shop.order.store', ['club' => $club->slug]), [
                'items' => [['product_id' => $product->id, 'quantity' => 1]], 'name' => 'Online', 'phone' => '91234567', 'payment_method' => 'paymob',
            ])->assertRedirectContains('clientSecret=cs_c');

            return $this->asTenant($club, fn () => [Sale::latest('id')->firstOrFail(), PaymentIntent::latest('id')->firstOrFail()]);
        };

        // Paid online, then money under review on it: cancelling would credit it in full with its refund.
        [$paidSale, $intent] = $order();
        $this->deliver($intent, 0, 670101, 'processed');
        $this->review($club, $paidSale->invoice_id);
        $before = $this->books($club, $paidSale->invoice_id);
        $status = $this->asTenant($club, fn () => $paidSale->fresh()->status);
        $this->assertSame('credit.payment_review', $this->refusedBy(fn () => $this->asTenant($club, fn () => app(OrderService::class)->cancel($paidSale->fresh(), 'طلب العميل', 'paymob'))));
        $this->assertSame($before, $this->books($club, $paidSale->invoice_id));
        $this->assertSame($status, $this->asTenant($club, fn () => $paidSale->fresh()->status));

        // Not paid, part already credited: cancelling would credit the rest.
        [$partSale] = $order();
        $this->asTenant($club, fn () => app(CreditNoteService::class)->issue(Invoice::findOrFail($partSale->invoice_id), '0.500', 'خصم'));
        $this->review($club, $partSale->invoice_id);
        $before = $this->books($club, $partSale->invoice_id);
        $this->assertSame('credit.payment_review', $this->refusedBy(fn () => $this->asTenant($club, fn () => app(OrderService::class)->cancel($partSale->fresh(), 'طلب العميل'))));
        $this->assertSame($before, $this->books($club, $partSale->invoice_id));
        $this->assertSame(Sale::PENDING, $this->asTenant($club, fn () => $partSale->fresh()->status));

        // A point-of-sale sale voided: a full note with its refund.
        $pos = $this->asTenant($club, fn () => app(PosService::class)->checkout([['product_id' => $product->id, 'quantity' => 2]], 'cash'));
        $this->review($club, $pos->invoice_id);
        $before = $this->books($club, $pos->invoice_id);
        $this->assertSame('credit.payment_review', $this->refusedBy(fn () => $this->asTenant($club, fn () => app(PosService::class)->void($pos->fresh(), 'خطأ'))));
        $this->assertSame($before, $this->books($club, $pos->invoice_id));
        $this->assertSame(Sale::COMPLETED, $this->asTenant($club, fn () => $pos->fresh()->status));

        // Every unit stays where it was: 2 held by the orders, 2 sold at the point of sale.
        $this->assertSame('6.000', $this->asTenant($club, fn () => $product->fresh()->stock_quantity));
    }

    public function test_other_invoices_and_reviews_closed_on_evidence_are_credited_as_before(): void
    {
        $club = $this->paymobClub();
        $owner = $this->owner($club);
        [$reviewed] = $this->pendingMembership($club);
        $this->review($club, $reviewed->invoice_id);
        [$plain] = $this->pendingMembership($club);
        [$refunded] = $this->pendingMembership($club);
        $this->review($club, $refunded->invoice_id, PaymentReview::REFUNDED);
        [$notCollected] = $this->pendingMembership($club);
        $this->review($club, $notCollected->invoice_id, PaymentReview::NOT_COLLECTED);

        foreach ([$plain, $refunded, $notCollected] as $membership) {
            $this->actingAs($owner)->from('/app')->post(route('club.credit-notes.store', $membership->invoice_id), $this->noteForm('5.000'))->assertSessionHasNoErrors();
            $this->asTenant($club, fn () => $this->assertSame('5.000', Invoice::find($membership->invoice_id)->credited_total));
        }
        $this->actingAs($owner)->from('/app')->post(route('club.credit-notes.store', $reviewed->invoice_id), $this->noteForm('5.000'))
            ->assertSessionHasErrors(['error' => PaymentReview::CREDIT_REFUSED]);
        $this->asTenant($club, fn () => $this->assertSame(3, CreditNote::count()));
    }

    public function test_crediting_still_takes_its_permissions_and_the_invoices_branch_and_club(): void
    {
        $club = $this->paymobClub();
        $this->owner($club);
        [$membership] = $this->pendingMembership($club);
        $this->review($club, $membership->invoice_id);
        $before = $this->books($club, $membership->invoice_id);

        // No «الإشعارات الدائنة»: refused before anything is looked at, review or not.
        $this->actingAs($this->staff($club, 'reception'))->post(route('club.credit-notes.store', $membership->invoice_id), $this->noteForm('5.000'))->assertForbidden();

        [$here, $there] = $this->asTenant($club, fn () => [Branch::create(['name' => 'الخوض', 'is_active' => true]), Branch::create(['name' => 'صلالة', 'is_active' => true])]);
        $this->asTenant($club, fn () => Invoice::whereKey($membership->invoice_id)->update(['branch_id' => $here->id]));
        $this->asTenant($club, fn () => Role::create(['key' => 'crediter', 'name' => 'الإشعارات الدائنة', 'permissions' => ['invoices.view', 'invoices.credit']]));

        // A refund with the note needs «رد المبالغ»: refused before the service is reached.
        $this->actingAs($this->staff($club, 'crediter', ['branch_id' => $here->id]))->post(route('club.credit-notes.store', $membership->invoice_id), $this->noteForm('5.000', ['refund_now' => true, 'method' => 'cash']))->assertForbidden();

        // Another branch, and another club: the invoice is not theirs to see.
        $this->actingAs($this->staff($club, 'crediter', ['branch_id' => $there->id]))->post(route('club.credit-notes.store', $membership->invoice_id), $this->noteForm('5.000'))->assertNotFound();
        $other = $this->paymobClub('Other');
        $this->actingAs($this->owner($other))->post(route('club.credit-notes.store', $membership->invoice_id), $this->noteForm('5.000'))->assertNotFound();

        // Allowed and in the branch: refused for the review, nothing else.
        $this->actingAs($this->staff($club, 'crediter', ['branch_id' => $here->id]))->from('/app')->post(route('club.credit-notes.store', $membership->invoice_id), $this->noteForm('5.000'))
            ->assertSessionHasErrors(['error' => PaymentReview::CREDIT_REFUSED]);
        $after = $this->books($club, $membership->invoice_id);
        $this->assertSame([$before['invoice'], $before['notes'], $before['open_reviews']], [$after['invoice'], $after['notes'], $after['open_reviews']]);
    }

    public function test_only_the_credit_note_service_issues_a_note_or_lowers_what_is_owed(): void
    {
        // Every path that issues a credit note goes through CreditNoteService::issue, where the review is asked under the lock.
        $writers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            $code = $file->getExtension() === 'php' ? (string) file_get_contents($file->getPathname()) : '';
            if (preg_match('/CreditNote::(create|forceCreate|insert|query\(\)->create)\(|new CreditNote\b|[\'"]credited_total[\'"]\s*=>\s*[^\s0\'"]/', $code)) {
                $writers[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
        $this->assertSame(['app/Modules/Finance/Services/CreditNoteService.php'], $writers);
    }
}
