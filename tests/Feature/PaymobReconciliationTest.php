<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Branch;
use App\Models\Role;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Payments\Events\OnlinePaymentFailed;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentReview;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Paymob confirmed money (signed, live, captured) that does not match what was asked — less or more, another currency,
 * another signed order, an integration the club did not enter — is never recorded on the invoice and activates nothing,
 * but it is not lost either: one review per Paymob transaction, the reviewers told what was expected and what came, the
 * customer told it is being checked, and no second checkout, reminder or automatic cancellation meanwhile. A «pending»
 * from the bank is neither a failure nor money.
 */
class PaymobReconciliationTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'recon-hmac-secret';

    private function paymobClub(): Tenant
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111, 222'],
        ]));

        return $club;
    }

    /** @return array{0: Membership, 1: PaymentIntent} a 25.000 membership waiting for its online payment */
    private function pending(Tenant $club, array $member = []): array
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_r', 'intention_order_id' => 7700], 201)]);
        $member = $this->member($club, $member);
        $plan = $this->plan($club, ['price' => 25]);

        return $this->asTenant($club, function () use ($member, $plan) {
            $membership = app(MembershipService::class)->sell($member, $plan);

            return [$membership, app(OnlinePaymentService::class)->start($membership->invoice, 'portal')];
        });
    }

    /** @return array<string, mixed> Paymob's transaction for $intent: captured, 25.000 OMR, Visa */
    private function obj(PaymentIntent $intent, array $over = []): array
    {
        return array_replace_recursive([
            'id' => 880001, 'pending' => false, 'amount_cents' => 25000, 'success' => true, 'is_auth' => false, 'is_capture' => false,
            'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true, 'integration_id' => 111,
            'has_parent_transaction' => false, 'order' => ['id' => 7700, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-20T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'data' => ['message' => 'Approved'], 'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'],
        ], $over);
    }

    private function deliver(array $obj, ?string $secret = null)
    {
        $this->app->forgetScopedInstances(); // each callback is a request of its own

        return $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, $secret ?? self::HMAC), ['type' => 'TRANSACTION', 'obj' => $obj]);
    }

    /** @return array{payments: int, journal: int, paid: string, status: string, membership: string, intent: string} */
    private function books(Tenant $club, Membership $membership, PaymentIntent $intent): array
    {
        return $this->asTenant($club, fn () => [
            'payments' => Payment::where('invoice_id', $membership->invoice_id)->count(),
            'journal' => JournalEntry::count(),
            'paid' => Invoice::find($membership->invoice_id)->paid_total,
            'status' => Invoice::find($membership->invoice_id)->status,
            'membership' => $membership->fresh()->status,
            'intent' => $intent->fresh()->status,
        ]);
    }

    private function alerts(Tenant $club): int
    {
        return StaffNotification::withoutGlobalScopes()->where('tenant_id', $club->id)->where('key', 'like', 'payment-review:%')->count();
    }

    public function test_a_matching_payment_is_recorded_as_before_and_opens_no_review(): void
    {
        $club = $this->paymobClub();
        $this->owner($club);
        [$membership, $intent] = $this->pending($club);

        $this->deliver($this->obj($intent))->assertOk()->assertJson(['outcome' => 'processed']);

        $this->asTenant($club, function () use ($membership) {
            $this->assertSame(Membership::ACTIVE, $membership->fresh()->status);
            $this->assertSame(0, PaymentReview::count());
        });
        $this->assertSame(0, $this->alerts($club));
    }

    public function test_less_or_more_than_asked_is_kept_once_for_review_and_nothing_is_recorded_or_activated(): void
    {
        $club = $this->paymobClub();
        $this->owner($club);
        [$membership, $intent] = $this->pending($club);
        $before = $this->books($club, $membership, $intent);

        // 4.990 short of nothing: one baisa less than asked, as a rounding by the card network would make it.
        $less = $this->obj($intent, ['amount_cents' => 24990]);
        $this->deliver($less)->assertOk()->assertJson(['outcome' => 'amount_mismatch']);
        $this->deliver($less)->assertOk()->assertJson(['outcome' => 'amount_mismatch']); // Paymob sent it again
        $this->deliver($this->obj($intent, ['id' => 880002, 'amount_cents' => 25010]))->assertOk()->assertJson(['outcome' => 'amount_mismatch']);

        $this->assertSame($before, $this->books($club, $membership, $intent), 'no payment, no entry, nothing activated, the attempt still open');
        $this->assertSame([Invoice::ISSUED, Membership::PENDING, PaymentIntent::PENDING], [$before['status'], $before['membership'], $before['intent']]);
        $this->asTenant($club, function () {
            $reviews = PaymentReview::orderBy('id')->get();
            $this->assertCount(2, $reviews, 'one review per Paymob transaction, however often it comes');
            $this->assertSame([['amount', 25000, 24990, 'OMR', 'Visa'], ['amount', 25000, 25010, 'OMR', 'Visa']],
                $reviews->map(fn ($r) => [$r->reason, $r->expected_minor, $r->received_minor, $r->received_currency, $r->card_brand])->all());
            // What staff need, never the card's number or a secret.
            $this->assertStringNotContainsString('2346', json_encode($reviews->first()->getAttributes()));
            $this->assertStringNotContainsString(self::HMAC, json_encode($reviews->first()->getAttributes()));
        });
        $this->assertSame(2, $this->alerts($club), 'one alert per review, not per callback');
    }

    public function test_confirmed_money_of_another_currency_order_or_integration_is_kept_for_review(): void
    {
        $club = $this->paymobClub();
        $this->owner($club);
        [$membership, $intent] = $this->pending($club);

        $this->deliver($this->obj($intent, ['id' => 880101, 'currency' => 'USD']))->assertOk()->assertJson(['outcome' => 'amount_mismatch']);
        $this->deliver($this->obj($intent, ['id' => 880102, 'integration_id' => 999]))->assertOk()->assertJson(['outcome' => 'integration_mismatch']);
        $this->deliver($this->obj($intent, ['id' => 880103, 'order' => ['id' => 1234]]))->assertOk()->assertJson(['outcome' => 'order_mismatch']);

        $this->asTenant($club, function () use ($membership) {
            $this->assertSame(['currency', 'integration', 'order'], PaymentReview::orderBy('id')->pluck('reason')->all());
            $this->assertSame(['999', '1234'], [PaymentReview::where('reason', 'integration')->value('integration_id'), PaymentReview::where('reason', 'order')->value('provider_order_id')]);
            $this->assertSame(0, Payment::where('invoice_id', $membership->invoice_id)->count());
            $this->assertSame(Membership::PENDING, $membership->fresh()->status);
        });
    }

    public function test_an_unsigned_declined_authorized_or_refund_callback_opens_no_review(): void
    {
        $club = $this->paymobClub();
        $this->owner($club);
        [, $intent] = $this->pending($club);

        $this->deliver($this->obj($intent, ['amount_cents' => 1]), 'someone-else')->assertStatus(401);
        $this->deliver($this->obj($intent, ['id' => 880201, 'integration_id' => 999, 'success' => false]))->assertOk();
        $this->deliver($this->obj($intent, ['id' => 880202, 'amount_cents' => 1, 'is_auth' => true]))->assertOk()->assertJson(['outcome' => 'authorized_only']);
        $this->deliver($this->obj($intent, ['id' => 880203, 'amount_cents' => 1, 'is_refund' => true]))->assertOk()->assertJson(['outcome' => 'ignored_refund']);

        $this->asTenant($club, fn () => $this->assertSame(0, PaymentReview::count()));
        $this->assertSame(0, $this->alerts($club));
    }

    public function test_while_under_review_no_second_checkout_no_reminder_and_the_customer_is_told_it_is_being_checked(): void
    {
        $club = $this->paymobClub();
        $this->owner($club);
        $this->asTenant($club, fn () => $club->forceFill(['settings' => array_merge($club->fresh()->settings ?? [], ['dues_reminders_enabled' => true, 'dues_reminder_before_days' => [3], 'dues_reminder_max' => 3])])->save());
        $this->asTenant($club, function () {
            TenantIntegration::create(['provider' => 'whatsapp', 'is_enabled' => true, 'credentials' => ['phone_number_id' => 'PNID-7', 'access_token' => 'EAAG-secret'], 'public_identifier' => 'PNID-7']);
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => 'approved']);
        });
        [$membership, $intent] = $this->pending($club, ['whatsapp_opt_in' => true]);
        Queue::fake();

        $this->deliver($this->obj($intent, ['amount_cents' => 24990]))->assertOk();

        $this->get(route('site.pay.return', ['club' => $club->slug, 'reference' => $intent->reference]))
            ->assertInertia(fn ($p) => $p->component('Site/PaymentReturn')->where('status', 'review'));
        $this->getJson(route('site.pay.status', ['club' => $club->slug, 'reference' => $intent->reference]))->assertExactJson(['status' => 'review', 'held' => false]);

        try {
            $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start(Invoice::find($membership->invoice_id), 'portal'));
            $this->fail('a second checkout opened');
        } catch (BusinessRuleException $e) {
            $this->assertSame(['paymob.review', PaymentReview::CUSTOMER_MESSAGE], [$e->rule, $e->getMessage()]);
        }

        $this->asTenant($club, fn () => Invoice::whereKey($membership->invoice_id)->update(['due_date' => today()->addDays(3)]));
        Artisan::call('finance:remind-dues');
        $this->asTenant($club, fn () => $this->assertSame(0, Message::where(['related_id' => $membership->invoice_id, 'template_key' => 'invoice_due_soon'])->count(), 'not asked to pay again'));
    }

    public function test_the_alert_goes_to_whoever_reviews_payments_in_that_branch_with_the_amounts_and_references(): void
    {
        $club = $this->paymobClub();
        $owner = $this->owner($club);
        $reception = $this->staff($club, 'reception');
        $finance = $this->staff($club, 'finance');
        $manager = $this->staff($club, 'manager');
        $trainer = $this->staff($club, 'trainer');
        [$membership, $intent] = $this->pending($club);

        $this->deliver($this->obj($intent, ['amount_cents' => 24990]))->assertOk();

        $alerts = StaffNotification::withoutGlobalScopes()->where('tenant_id', $club->id)->where('key', 'like', 'payment-review:%')->get();
        $this->assertSame([$owner->id], $alerts->pluck('user_id')->all(), 'payments.review only: the owner unless granted by name');
        $this->assertNotContains($reception->id, $alerts->pluck('user_id'));
        foreach ([$finance, $manager, $trainer] as $user) {
            $this->assertFalse($user->fresh()->can('payments.review'), $user->name.' does not review payments by default');
        }
        $text = $alerts->first()->text;
        $number = $this->asTenant($club, fn () => Invoice::find($membership->invoice_id)->number);
        foreach (['25.000 OMR', '24.990 OMR', PaymentReview::REASONS['amount'], '880001', $intent->reference, $number] as $part) {
            $this->assertStringContainsString($part, $text, $part);
        }
        $this->assertLessThan(300, mb_strlen($text), 'whole, within the alert\'s length');
        $this->assertSame(route('club.invoices.show', $membership->invoice_id), $alerts->first()->url);
    }

    public function test_a_review_that_cannot_be_written_is_not_acknowledged_and_the_resend_writes_it_once(): void
    {
        $club = $this->paymobClub();
        $this->owner($club);
        [, $intent] = $this->pending($club);
        $fail = true;
        PaymentReview::creating(function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('disk full');
            }
        });

        $this->deliver($this->obj($intent, ['amount_cents' => 24990]))->assertStatus(500);
        $this->asTenant($club, fn () => $this->assertSame(0, PaymentReview::count()));

        $fail = false;
        $this->deliver($this->obj($intent, ['amount_cents' => 24990]))->assertOk()->assertJson(['outcome' => 'amount_mismatch']);
        $this->deliver($this->obj($intent, ['amount_cents' => 24990]))->assertOk();
        $this->asTenant($club, fn () => $this->assertSame(1, PaymentReview::count()));
        $this->assertSame(1, $this->alerts($club));
    }

    public function test_a_pending_from_the_bank_is_no_failure_and_no_second_checkout_opens_until_its_result(): void
    {
        $club = $this->paymobClub();
        $this->owner($club);
        [$membership, $intent] = $this->pending($club);
        Event::fake([OnlinePaymentFailed::class]);

        $this->deliver($this->obj($intent, ['id' => 880301, 'success' => false, 'pending' => true]))->assertOk()->assertJson(['outcome' => 'pending']);

        Event::assertNotDispatched(OnlinePaymentFailed::class);
        $this->assertSame(PaymentIntent::PENDING, $intent->fresh()->status, 'not «failed»: the bank has not decided');
        $this->assertSame(0, StaffNotification::withoutGlobalScopes()->where('tenant_id', $club->id)->where('key', 'like', 'payment-failed%')->count());
        $this->getJson(route('site.pay.status', ['club' => $club->slug, 'reference' => $intent->reference]))->assertJson(['status' => 'pending']);

        // The page's time and its late window are over: still no new checkout while the bank holds the first.
        $this->travelTo($intent->expires_at->copy()->addMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES + 1));
        try {
            $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start(Invoice::find($membership->invoice_id), 'portal'));
            $this->fail('a second checkout opened while the bank still processed the first');
        } catch (BusinessRuleException $e) {
            $this->assertSame('paymob.pending', $e->rule);
        }

        // Its result comes: the money is recorded once (held for review, its page being over), nothing lost.
        $this->deliver($this->obj($intent, ['id' => 880301]))->assertOk()->assertJson(['outcome' => 'held_for_review']);
        $this->asTenant($club, fn () => $this->assertSame(1, Payment::where('invoice_id', $membership->invoice_id)->count()));
    }

    public function test_a_website_order_under_review_is_not_cancelled_by_the_scheduler_until_its_review_is_closed(): void
    {
        $club = $this->paymobClub();
        $owner = $this->owner($club);
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
        $this->assertSame('1.575', $intent->amount);

        // Charged 1.570, as a card network rounding a single baisa would charge it.
        $this->deliver($this->obj($intent, ['amount_cents' => 1570, 'order' => ['id' => 7800]]))->assertOk()->assertJson(['outcome' => 'amount_mismatch']);
        $this->asTenant($club, fn () => $sale->forceFill(['reserved_until' => now()->subMinutes(30)])->save());
        Artisan::call('sales:expire-orders');

        $this->asTenant($club, function () use ($sale, $product) {
            $this->assertSame(Sale::PENDING, $sale->fresh()->status, 'the money is being checked: the order stays');
            $this->assertNotSame(Invoice::VOID, Invoice::find($sale->invoice_id)->status);
            $this->assertSame('4.000', $product->fresh()->stock_quantity, 'its unit stays held');
        });

        $review = $this->asTenant($club, fn () => PaymentReview::sole());
        $this->actingAs($owner)->post(route('club.payment-reviews.close', $review->id), ['note' => 'رُدّ المبلغ من لوحة Paymob'])->assertSessionHasNoErrors();
        Artisan::call('sales:expire-orders');
        $this->asTenant($club, function () use ($sale, $product) {
            $this->assertSame(Sale::CANCELLED, $sale->fresh()->status, 'closed: the scheduler goes on as before');
            $this->assertSame('5.000', $product->fresh()->stock_quantity);
        });
    }

    public function test_closing_a_review_takes_its_permission_and_branch_moves_no_money_and_is_audited(): void
    {
        $club = $this->paymobClub();
        $owner = $this->owner($club);
        $reception = $this->staff($club, 'reception');
        [$membership, $intent] = $this->pending($club);
        $this->deliver($this->obj($intent, ['amount_cents' => 24990]))->assertOk();
        $review = $this->asTenant($club, fn () => PaymentReview::sole());

        $this->actingAs($reception)->post(route('club.payment-reviews.close', $review->id), ['note' => 'تم'])->assertForbidden();

        // A reviewer tied to another branch does not see this invoice's review.
        [$here, $there] = $this->asTenant($club, fn () => [Branch::create(['name' => 'الخوض', 'is_active' => true]), Branch::create(['name' => 'صلالة', 'is_active' => true])]);
        $this->asTenant($club, fn () => Invoice::whereKey($membership->invoice_id)->update(['branch_id' => $here->id]));
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'payments-reviewer', 'name' => 'مراجع الدفعات', 'permissions' => ['invoices.view', 'payments.review']]));
        $elsewhere = $this->staff($club, 'payments-reviewer', ['branch_id' => $there->id]);
        $this->actingAs($elsewhere)->post(route('club.payment-reviews.close', $review->id), ['note' => 'تمت المعالجة'])->assertNotFound();
        $this->assertNotNull($role);

        $this->actingAs($owner)->post(route('club.payment-reviews.close', $review->id), ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($owner)->post(route('club.payment-reviews.close', $review->id), ['note' => 'رُدّ المبلغ من لوحة Paymob'])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($review, $membership, $owner) {
            $this->assertSame([PaymentReview::CLOSED, $owner->id, 'رُدّ المبلغ من لوحة Paymob'], [$review->fresh()->status, $review->fresh()->closed_by, $review->fresh()->close_note]);
            $this->assertSame(0, Payment::where('invoice_id', $membership->invoice_id)->count(), 'closing moves no money');
            $this->assertSame(1, DB::table('audit_logs')->where('event', 'payment.review_closed')->count());
            $this->assertSame(1, DB::table('audit_logs')->where('event', 'payment.review_opened')->count());
        });

        // Closed: the invoice can be paid online again.
        $again = $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start(Invoice::find($membership->invoice_id), 'portal'));
        $this->assertNotNull($again->checkout_url);
        $this->assertSame(1, PaymentWebhookEvent::where('outcome', 'amount_mismatch')->count());
    }
}
