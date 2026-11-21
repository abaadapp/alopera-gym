<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\GiftService;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentReview;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Payments\Services\PaymentLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * An invoice whose Paymob money is under review (PaymentReview, open) is not voided — from the invoice screen, a
 * membership, gift or website order cancelled, or a payment link — since the money may be the customer's payment for it.
 * A closed review, or none, leaves voiding as it was; permissions and branches as they were; refusing moves no money.
 */
class InvoiceVoidPaymentReviewTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'void-review-hmac';

    private function paymobClub(string $name = 'Club'): Tenant
    {
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
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_v', 'intention_order_id' => 7700], 201)]);
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        return $this->asTenant($club, function () use ($member, $plan) {
            $membership = app(MembershipService::class)->sell($member, $plan);

            return [$membership, app(OnlinePaymentService::class)->start($membership->invoice, 'portal')];
        });
    }

    private function obj(PaymentIntent $intent, array $over = []): array
    {
        return array_replace([
            'id' => 660001, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount) - 10, 'success' => true, 'is_auth' => false,
            'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true,
            'integration_id' => 111, 'has_parent_transaction' => false, 'order' => ['id' => (int) $intent->provider_order_id, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-20T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'],
        ], $over);
    }

    /** Paymob's signed confirmation of less than asked: kept as an open review by the webhook itself. */
    private function mismatched(PaymentIntent $intent): void
    {
        $this->app->forgetScopedInstances();
        $obj = $this->obj($intent);
        $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, self::HMAC), ['type' => 'TRANSACTION', 'obj' => $obj])
            ->assertOk()->assertJson(['outcome' => 'amount_mismatch']);
    }

    /** A review written as the webhook writes it, on any invoice (links, gifts): open unless said otherwise. */
    private function review(Tenant $club, int $invoiceId, string $status = PaymentReview::OPEN): PaymentReview
    {
        return $this->asTenant($club, function () use ($invoiceId, $status) {
            $invoice = Invoice::findOrFail($invoiceId);
            $intent = PaymentIntent::create([
                'invoice_id' => $invoice->id, 'provider' => 'paymob', 'reference' => 'PL-VOIDREV-'.Str::upper(Str::random(8)),
                'amount' => $invoice->total, 'currency' => 'OMR', 'status' => PaymentIntent::EXPIRED, 'channel' => 'whatsapp', 'expires_at' => now()->subDays(2),
            ]);

            return PaymentReview::create([
                'payment_intent_id' => $intent->id, 'invoice_id' => $invoice->id, 'gateway' => 'paymob', 'transaction_id' => 'vr-'.Str::lower(Str::random(12)),
                'reason' => 'amount', 'expected_minor' => Money::toMinor($invoice->total), 'received_minor' => Money::toMinor($invoice->total) - 10,
                'expected_currency' => 'OMR', 'received_currency' => 'OMR', 'status' => $status,
            ] + ($status === PaymentReview::OPEN ? [] : ['closed_at' => now()]));
        });
    }

    /** @return array{status: string, paid: string, credited: string, total: string, payments: int, journal: int} */
    private function books(Tenant $club, int $invoiceId): array
    {
        return $this->asTenant($club, fn () => [
            'status' => Invoice::find($invoiceId)->status,
            'paid' => Invoice::find($invoiceId)->paid_total,
            'credited' => Invoice::find($invoiceId)->credited_total,
            'total' => Invoice::find($invoiceId)->total,
            'payments' => Payment::where('invoice_id', $invoiceId)->count(),
            'journal' => JournalEntry::count(),
        ]);
    }

    private function refusedBy(callable $cancel): string
    {
        try {
            $cancel();
        } catch (BusinessRuleException $e) {
            $this->assertSame(PaymentReview::VOID_REFUSED, $e->getMessage());

            return $e->rule;
        }
        $this->fail('cancelled while its Paymob money is under review');
    }

    public function test_an_invoice_with_open_review_is_not_voided_from_the_invoice_screen_nor_by_cancelling_its_membership(): void
    {
        $club = $this->paymobClub();
        $owner = $this->owner($club);
        [$membership, $intent] = $this->pendingMembership($club);
        $this->mismatched($intent);
        $before = $this->books($club, $membership->invoice_id);

        $this->actingAs($owner)->from('/app')->post(route('club.invoices.void', $membership->invoice_id), ['reason' => 'خطأ'])
            ->assertSessionHasErrors(['error' => PaymentReview::VOID_REFUSED]);
        $this->assertSame('invoice.payment_review', $this->refusedBy(fn () => $this->asTenant($club, fn () => app(MembershipService::class)->cancel($membership->fresh(), 'تراجع'))));

        // Refusing moved nothing: same invoice, no payment, no entry; the membership waits, the review stays open.
        $this->assertSame($before, $this->books($club, $membership->invoice_id));
        $this->asTenant($club, function () use ($membership) {
            $this->assertSame(Membership::PENDING, $membership->fresh()->status);
            $this->assertSame(1, PaymentReview::open()->count());
        });
    }

    public function test_a_payment_link_invoice_with_open_review_is_not_voided_nor_its_link_cancelled(): void
    {
        $club = $this->paymobClub();
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.payment-links.store'), ['amount' => '12.500', 'description' => 'حصص', 'valid_days' => 7, 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $link = $this->asTenant($club, fn () => PaymentLink::with('invoice')->sole());
        $this->review($club, $link->invoice_id);
        $before = $this->books($club, $link->invoice_id);

        // Cancelling the link ends the link only (R1): refused all the same, in the link's own words; voiding the invoice
        // is refused in the invoice's.
        $this->actingAs($owner)->from('/app')->post(route('club.payment-links.cancel', $link->id))->assertSessionHasErrors(['error' => PaymentLinks::REVIEW_REFUSED]);
        $this->actingAs($owner)->from('/app')->post(route('club.invoices.void', $link->invoice_id), ['reason' => 'خطأ'])->assertSessionHasErrors(['error' => PaymentReview::VOID_REFUSED]);

        $this->assertSame($before, $this->books($club, $link->invoice_id));
        $this->assertNull($this->asTenant($club, fn () => $link->fresh()->cancelled_at));
    }

    public function test_a_website_order_with_open_review_is_not_cancelled_by_staff_and_keeps_its_stock(): void
    {
        $club = $this->paymobClub();
        $this->owner($club);
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_o', 'intention_order_id' => 7800], 201)]);
        $product = $this->asTenant($club, function () {
            $product = Product::create(['name' => 'Protein bar', 'price' => 1.575, 'show_on_website' => true]);
            app(StockService::class)->move($product, 5, 'adjustment', null, 'opening', 0.8);

            return $product;
        });
        $this->post(route('site.shop.order.store', ['club' => $club->slug]), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'name' => 'Online', 'phone' => '91234567', 'payment_method' => 'paymob',
        ])->assertRedirectContains('clientSecret=cs_o');
        [$sale, $intent] = $this->asTenant($club, fn () => [Sale::latest('id')->firstOrFail(), PaymentIntent::latest('id')->firstOrFail()]);
        $this->mismatched($intent);
        $before = $this->books($club, $sale->invoice_id);

        $this->assertSame('invoice.payment_review', $this->refusedBy(fn () => $this->asTenant($club, fn () => app(OrderService::class)->cancel($sale->fresh(), 'طلب العميل'))));

        $this->assertSame($before, $this->books($club, $sale->invoice_id));
        $this->asTenant($club, function () use ($sale, $product) {
            $this->assertSame(Sale::PENDING, $sale->fresh()->status);
            $this->assertSame('4.000', $product->fresh()->stock_quantity, 'its unit stays held');
        });
    }

    public function test_a_gift_with_open_review_on_its_invoice_is_not_cancelled(): void
    {
        $club = $this->paymobClub();
        $this->owner($club);
        $plan = $this->plan($club, ['price' => 30, 'giftable' => true]);
        $gift = $this->asTenant($club, fn () => app(GiftService::class)->issue($plan, ['giver_name' => 'Sara', 'recipient_name' => 'Noor', 'recipient_phone' => '91234567'], 'staff'));
        $this->review($club, $gift->invoice_id);
        $before = $this->books($club, $gift->invoice_id);

        $this->assertSame('invoice.payment_review', $this->refusedBy(fn () => $this->asTenant($club, fn () => app(GiftService::class)->cancel($gift->fresh(), 'تراجع'))));

        $this->assertSame($before, $this->books($club, $gift->invoice_id));
        $this->assertNotSame(GiftMembership::CANCELLED, $this->asTenant($club, fn () => $gift->fresh()->status));
    }

    public function test_reviews_handed_over_for_pending_or_for_a_failed_recording_also_stop_the_void(): void
    {
        $club = $this->paymobClub();
        $owner = $this->owner($club);

        // «Pending» at the bank for a day: handed to staff when the customer asks again (OnlinePaymentService::awaitsBank).
        [$waiting, $intent] = $this->pendingMembership($club);
        $obj = $this->obj($intent, ['id' => 660101, 'amount_cents' => 25000, 'success' => false, 'pending' => true]);
        $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, self::HMAC), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
        $this->travel(25)->hours();
        try {
            $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start(Invoice::findOrFail($waiting->invoice_id), 'portal'));
        } catch (BusinessRuleException) {
            // refused: handed to staff
        }
        $this->asTenant($club, fn () => $this->assertSame('pending', PaymentReview::where('invoice_id', $waiting->invoice_id)->open()->sole()->reason));
        $this->actingAs($owner)->from('/app')->post(route('club.invoices.void', $waiting->invoice_id), ['reason' => 'خطأ'])->assertSessionHasErrors(['error' => PaymentReview::VOID_REFUSED]);
        $this->travelBack();

        // Matching money the books could not take (outcome error, 500): its review stops the void too.
        [$failed, $intent] = $this->pendingMembership($club);
        $fail = true;
        Payment::creating(function () use (&$fail) {
            if ($fail) {
                throw new \RuntimeException('database busy');
            }
        });
        $obj = $this->obj($intent, ['id' => 660102, 'amount_cents' => 25000]);
        $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, self::HMAC), ['type' => 'TRANSACTION', 'obj' => $obj])->assertStatus(500);
        $fail = false;
        $this->asTenant($club, fn () => $this->assertSame('error', PaymentReview::where('invoice_id', $failed->invoice_id)->open()->sole()->reason));
        $this->actingAs($owner)->from('/app')->post(route('club.invoices.void', $failed->invoice_id), ['reason' => 'خطأ'])->assertSessionHasErrors(['error' => PaymentReview::VOID_REFUSED]);

        foreach ([$waiting, $failed] as $membership) {
            $this->asTenant($club, fn () => $this->assertNotSame(Invoice::VOID, Invoice::find($membership->invoice_id)->status));
        }
    }

    public function test_without_a_review_or_with_a_closed_one_voiding_is_as_before(): void
    {
        $club = $this->paymobClub();
        $owner = $this->owner($club);
        [$plain] = $this->pendingMembership($club);
        [$reviewed] = $this->pendingMembership($club);
        $this->review($club, $reviewed->invoice_id, PaymentReview::REFUNDED);

        foreach ([$plain, $reviewed] as $membership) {
            $this->actingAs($owner)->from('/app')->post(route('club.invoices.void', $membership->invoice_id), ['reason' => 'خطأ'])->assertSessionHasNoErrors();
            $this->asTenant($club, fn () => $this->assertSame(Invoice::VOID, Invoice::find($membership->invoice_id)->status));
        }
    }

    public function test_voiding_still_takes_its_permission_and_the_invoices_branch_and_club(): void
    {
        $club = $this->paymobClub();
        $this->owner($club);
        [$membership] = $this->pendingMembership($club);
        $this->review($club, $membership->invoice_id);

        // No «إلغاء الفواتير»: refused before anything is looked at, review or not.
        $this->actingAs($this->staff($club, 'reception'))->post(route('club.invoices.void', $membership->invoice_id), ['reason' => 'خطأ'])->assertForbidden();

        // Another branch, and another club: the invoice is not theirs to see.
        [$here, $there] = $this->asTenant($club, fn () => [Branch::create(['name' => 'الخوض', 'is_active' => true]), Branch::create(['name' => 'صلالة', 'is_active' => true])]);
        $this->asTenant($club, fn () => Invoice::whereKey($membership->invoice_id)->update(['branch_id' => $here->id]));
        $this->asTenant($club, fn () => Role::create(['key' => 'voider', 'name' => 'ملغي الفواتير', 'permissions' => ['invoices.view', 'invoices.void']]));
        $this->actingAs($this->staff($club, 'voider', ['branch_id' => $there->id]))->post(route('club.invoices.void', $membership->invoice_id), ['reason' => 'خطأ'])->assertNotFound();
        $other = $this->paymobClub('Other');
        $this->actingAs($this->owner($other))->post(route('club.invoices.void', $membership->invoice_id), ['reason' => 'خطأ'])->assertNotFound();

        // Allowed and in the branch: refused for the review, nothing else.
        $this->actingAs($this->staff($club, 'voider', ['branch_id' => $here->id]))->from('/app')->post(route('club.invoices.void', $membership->invoice_id), ['reason' => 'خطأ'])
            ->assertSessionHasErrors(['error' => PaymentReview::VOID_REFUSED]);
        $this->asTenant($club, fn () => $this->assertSame(Invoice::ISSUED, Invoice::find($membership->invoice_id)->status));
    }

    public function test_only_invoice_service_writes_a_void_status(): void
    {
        // Every path that cancels an invoice goes through InvoiceService::void, where the review is asked under the lock.
        $writers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() === 'php' && preg_match("/'status'\s*=>\s*(Invoice::VOID|'void')/", (string) file_get_contents($file->getPathname()))) {
                $writers[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
        $this->assertSame(['app/Modules/Finance/Services/InvoiceService.php'], $writers);
    }
}
