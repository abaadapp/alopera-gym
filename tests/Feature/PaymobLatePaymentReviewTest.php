<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Models\AuditLog;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Confirmed Paymob money that can no longer simply pay its invoice — the checkout's time over (by our clock when the
 * callback is handled), the invoice or the order closed, a membership no longer waiting, ended, or overlapping another
 * period — is recorded in full and held for review: nothing applied, nothing activated, no stock moved, no second
 * checkout, no dues reminder; staff apply it to its own invoice or pay it back, each with a reason. Paymob is faked.
 */
class PaymobLatePaymentReviewTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'late-hmac-secret';

    private int $nextOrder = 7300;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*/v1/intention/' => fn () => Http::response(['client_secret' => 'cs_late', 'intention_order_id' => $this->nextOrder++], 201)]);
    }

    private function paymobClub(): Tenant
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));

        return $club;
    }

    /** @return array{0: Membership, 1: PaymentIntent} a membership waiting for its online payment */
    private function pending(Tenant $club, ?Member $member = null): array
    {
        $member ??= $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        return $this->asTenant($club, function () use ($member, $plan) {
            $membership = app(MembershipService::class)->sell($member, $plan);

            return [$membership, app(OnlinePaymentService::class)->start($membership->invoice, 'portal')];
        });
    }

    /** @return array<string, mixed> a successful, captured sale of the intent's amount, signed fields as Paymob sends them */
    private function obj(PaymentIntent $intent, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 560001, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true,
            'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false,
            'is_3d_secure' => true, 'integration_id' => 111, 'has_parent_transaction' => false,
            'order' => ['id' => (int) $intent->provider_order_id, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-14T10:00:00.123456', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ], $overrides);
    }

    private function deliver(array $obj): string
    {
        $this->app->forgetScopedInstances();

        return $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, self::HMAC), ['type' => 'TRANSACTION', 'obj' => $obj])
            ->assertOk()->json('outcome');
    }

    private function heldPayment(Tenant $club): Payment
    {
        return $this->asTenant($club, fn () => Payment::where('gateway', 'paymob')->sole());
    }

    /** Recorded in full, applied to nothing, activated nothing, told once, in balanced books. */
    private function assertHeld(Tenant $club, Membership $membership, PaymentIntent $intent, string $reason): Payment
    {
        $payment = $this->heldPayment($club);
        $this->asTenant($club, function () use ($membership, $intent, $reason, $payment) {
            $this->assertSame('25.000', $payment->amount);
            $this->assertSame('25.000', $payment->credit_amount, 'nothing applied');
            $this->assertSame('25.000', $payment->creditLeft());
            $invoice = Invoice::findOrFail($membership->invoice_id);
            $this->assertSame('0.000', $invoice->paid_total, 'the invoice is not paid by it');
            $this->assertNotSame(Invoice::PAID, $invoice->status);
            $this->assertSame(PaymentIntent::PAID, $intent->fresh()->status, 'the money is confirmed');
            $this->assertSame($payment->id, $intent->fresh()->payment_id);
            $audit = AuditLog::where('event', 'payment.gateway_held')->sole();
            $this->assertSame($reason, $audit->properties['reason']);
            $this->assertSame('2026-11-14T10:00:00.123456', $audit->properties['gateway_time'], 'Paymob\'s own time kept as sent, not interpreted');
            $this->assertSame(1, StaffNotification::where('key', 'payment-credit:'.$payment->id)->distinct('user_id')->count('user_id'), 'told once');
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'), 'the books balance');
        });

        return $payment;
    }

    // ── what is held ──────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_payment_in_time_is_applied_and_activates_as_before(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club);

        $this->assertSame('processed', $this->deliver($this->obj($intent)));
        $this->asTenant($club, function () use ($membership) {
            $payment = Payment::where('gateway', 'paymob')->sole();
            $this->assertSame('0.000', $payment->credit_amount);
            $this->assertSame(Invoice::PAID, Invoice::findOrFail($membership->invoice_id)->status);
            $this->assertSame(Membership::ACTIVE, $membership->fresh()->status);
            $this->assertSame(0, AuditLog::where('event', 'payment.gateway_held')->count());
            $this->assertSame(0, StaffNotification::where('key', 'like', 'payment-credit:%')->count());
        });
    }

    public function test_a_confirmation_after_the_checkout_ended_is_held_not_activated_and_once_only(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club);
        $this->travelTo($intent->expires_at->copy()->addSecond());

        $this->assertSame('held_for_review', $this->deliver($this->obj($intent)));
        $this->assertSame('duplicate', $this->deliver($this->obj($intent)), 'delivered again');
        $this->assertHeld($club, $membership, $intent, 'checkout_expired');
        $this->asTenant($club, fn () => $this->assertSame(Membership::PENDING, $membership->fresh()->status, 'not activated'));
    }

    public function test_a_membership_that_ended_is_held_even_when_paid_in_time_and_its_dates_are_not_touched(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club);
        $this->asTenant($club, fn () => $membership->forceFill(['starts_on' => today()->subDays(40), 'ends_on' => today()->subDay()])->save());

        $this->assertSame('held_for_review', $this->deliver($this->obj($intent)));
        $this->assertHeld($club, $membership, $intent, 'membership_ended');
        $this->asTenant($club, function () use ($membership) {
            $this->assertSame(Membership::PENDING, $membership->fresh()->status, 'not expired by a payment');
            $this->assertSame(today()->subDay()->toDateString(), $membership->fresh()->ends_on->toDateString());
        });
    }

    public function test_a_membership_no_longer_waiting_for_its_payment_is_held(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club);
        $this->asTenant($club, fn () => $membership->forceFill(['status' => Membership::SCHEDULED])->save());

        $this->assertSame('held_for_review', $this->deliver($this->obj($intent)));
        $this->assertHeld($club, $membership, $intent, 'membership_not_pending');
    }

    public function test_another_period_of_the_member_covering_the_same_days_holds_the_payment(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club);
        // Given at the desk after the online sale (a sale itself never overlaps: assertNoOverlap).
        $this->asTenant($club, fn () => $membership->replicate()->forceFill(['status' => Membership::ACTIVE])->save());

        $this->assertSame('held_for_review', $this->deliver($this->obj($intent)));
        $this->assertHeld($club, $membership, $intent, 'membership_overlap');
        $this->asTenant($club, fn () => $this->assertSame(Membership::PENDING, $membership->fresh()->status));
    }

    public function test_an_upgrade_and_a_renewal_paid_in_time_go_ahead_as_before(): void
    {
        $club = $this->paymobClub();
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);
        $better = $this->plan($club, ['price' => 40]);
        $current = $this->asTenant($club, function () use ($member, $plan) {
            $m = app(MembershipService::class)->sell($member, $plan);
            app(PaymentService::class)->record($m->invoice, $m->invoice->balance(), 'cash');

            return $m->fresh();
        });
        $this->assertSame(Membership::ACTIVE, $current->status);

        // An upgrade covers the current period by design: it replaces it (activate()), it is not held.
        [$upgrade, $intent] = $this->asTenant($club, function () use ($current, $better) {
            $u = app(MembershipService::class)->upgrade($current, $better);

            return [$u, app(OnlinePaymentService::class)->start($u->invoice, 'portal')];
        });
        $this->assertSame('processed', $this->deliver($this->obj($intent, ['amount_cents' => Money::toMinor($intent->amount)])));
        $this->asTenant($club, function () use ($upgrade, $current) {
            $this->assertSame(Membership::ACTIVE, $upgrade->fresh()->status);
            $this->assertSame(Membership::CANCELLED, $current->fresh()->status);
        });

        // A renewal starts after the period it follows: no overlap, not held.
        [$renewal, $next] = $this->asTenant($club, function () use ($member) {
            $r = app(MembershipService::class)->renew($member);

            return [$r, app(OnlinePaymentService::class)->start($r->invoice, 'portal')];
        });
        $this->assertSame('processed', $this->deliver($this->obj($next, ['id' => 560002])));
        $this->asTenant($club, fn () => $this->assertSame(Membership::SCHEDULED, $renewal->fresh()->status));
    }

    public function test_an_order_whose_hold_is_over_is_held_its_stock_is_not_taken_and_its_hold_is_not_reopened(): void
    {
        $club = $this->paymobClub();
        $product = $this->asTenant($club, function () {
            $p = Product::create(['name' => 'Bar', 'price' => 1.5, 'show_on_website' => true]);
            app(StockService::class)->move($p, 5, 'adjustment', null, 'opening', 0.8);

            return $p->fresh();
        });
        $this->post(route('site.shop.order.store', ['club' => $club->slug]), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'name' => 'Online', 'phone' => '91234567', 'payment_method' => 'paymob',
        ])->assertRedirectContains('clientSecret=cs_late');
        [$sale, $intent] = $this->asTenant($club, fn () => [Sale::latest('id')->firstOrFail(), PaymentIntent::latest('id')->firstOrFail()]);
        $held = $this->asTenant($club, fn () => (string) $product->fresh()->stock_quantity);
        // The hold is over (the checkout itself is still open: the order's own window decides).
        $this->asTenant($club, fn () => $sale->forceFill(['reserved_until' => now()->subMinute()])->save());

        $this->assertSame('held_for_review', $this->deliver($this->obj($intent, ['amount_cents' => Money::toMinor($intent->amount)])));
        $this->asTenant($club, function () use ($sale, $product, $held) {
            $this->assertNull($sale->fresh()->paid_at, 'the order is not paid');
            $this->assertSame($held, (string) $product->fresh()->stock_quantity, 'no stock moved');
            $this->assertSame(Invoice::ISSUED, Invoice::findOrFail($sale->invoice_id)->status);
            $this->assertSame('order_closed', AuditLog::where('event', 'payment.gateway_held')->sole()->properties['reason']);
        });

        // Released as any order past its window: stock back, invoice voided, the money still the customer's.
        $this->travel(10)->minutes();
        Artisan::call('sales:expire-orders');
        $this->asTenant($club, function () use ($sale, $product) {
            $this->assertSame(Sale::CANCELLED, $sale->fresh()->status);
            $this->assertSame('5.000', Money::normalize($product->fresh()->stock_quantity));
            $this->assertSame(Invoice::VOID, Invoice::findOrFail($sale->invoice_id)->status);
            $this->assertSame('1.500', Payment::where('gateway', 'paymob')->sole()->creditLeft());
        });
    }

    public function test_money_for_a_voided_invoice_is_held_as_before_and_said_so(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club);
        $this->asTenant($club, fn () => app(MembershipService::class)->cancel($membership, 'غيّر رأيه'));

        $this->assertSame('held_for_review', $this->deliver($this->obj($intent)));
        $this->asTenant($club, function () use ($membership) {
            $this->assertSame(Invoice::VOID, Invoice::findOrFail($membership->invoice_id)->status);
            $this->assertSame(Membership::CANCELLED, $membership->fresh()->status);
            $this->assertSame('25.000', Payment::where('gateway', 'paymob')->sole()->creditLeft());
            $this->assertSame('invoice_closed', AuditLog::where('event', 'payment.gateway_held')->sole()->properties['reason']);
        });
    }

    public function test_an_unverified_or_uncaptured_callback_after_the_window_is_never_credit(): void
    {
        $club = $this->paymobClub();
        [, $intent] = $this->pending($club);
        $this->travelTo($intent->expires_at->copy()->addMinute());

        $this->assertSame('authorized_only', $this->deliver($this->obj($intent, ['is_auth' => true])));
        $this->assertSame('amount_mismatch', $this->deliver($this->obj($intent, ['id' => 560003, 'amount_cents' => 1])));
        $this->assertSame('failed_payment', $this->deliver($this->obj($intent, ['id' => 560004, 'success' => false])));
        $this->postJson(route('webhooks.paymob').'?hmac=forged', ['type' => 'TRANSACTION', 'obj' => $this->obj($intent, ['id' => 560005])])->assertStatus(401);
        $this->asTenant($club, fn () => $this->assertSame(0, Payment::count(), 'only signed, bound, captured money of the right amount is recorded'));
    }

    // ── nothing asks the customer to pay again ───────────────────────────────────────────────────────────────────

    public function test_no_second_checkout_while_confirmed_money_is_held_on_the_invoice(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club);
        $this->travelTo($intent->expires_at->copy()->addSecond());
        $this->deliver($this->obj($intent));

        $this->asTenant($club, function () use ($membership) {
            try {
                app(OnlinePaymentService::class)->start($membership->invoice->fresh(), 'portal');
                $this->fail('a second checkout was opened for money already held');
            } catch (BusinessRuleException $e) {
                $this->assertSame('paymob.held', $e->rule);
            }
            $this->assertSame(1, PaymentIntent::count());
        });
    }

    public function test_no_dues_reminder_for_an_invoice_whose_money_is_held(): void
    {
        $club = $this->paymobClub();
        $member = $this->member($club, ['whatsapp_opt_in' => true]);
        [$membership, $intent] = $this->pending($club, $member);
        $other = $this->asTenant($club, function () use ($member) {
            $i = app(InvoiceService::class)->issue($member, [['description' => 'خدمة', 'unit_price' => 10]], ['type' => 'other']);
            $i->forceFill(['due_date' => today()->addDays(3)])->save();

            return $i;
        });
        $this->asTenant($club, fn () => Invoice::whereKey($membership->invoice_id)->update(['due_date' => today()->addDays(3)]));
        $this->asTenant($club, fn () => $club->forceFill(['settings' => array_merge($club->fresh()->settings ?? [], ['dues_reminders_enabled' => true, 'dues_reminder_before_days' => [3], 'dues_reminder_max' => 3])])->save());
        $this->asTenant($club, function () {
            TenantIntegration::create(['provider' => 'whatsapp', 'is_enabled' => true, 'credentials' => ['phone_number_id' => 'PNID-9', 'access_token' => 'EAAG-secret'], 'public_identifier' => 'PNID-9']);
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => 'approved']);
        });
        Queue::fake();
        $this->travelTo($intent->expires_at->copy()->addSecond());
        $this->deliver($this->obj($intent));

        Artisan::call('finance:remind-dues');
        $this->asTenant($club, function () use ($membership, $other) {
            $this->assertSame(0, Message::where(['related_id' => $membership->invoice_id, 'template_key' => 'invoice_due_soon'])->count(), 'the customer paid: not asked again');
            $this->assertSame(1, Message::where(['related_id' => $other->id, 'template_key' => 'invoice_due_soon'])->count(), 'other invoices as before');
        });
    }

    public function test_the_return_page_says_received_and_under_review_only_for_money_held(): void
    {
        $club = $this->paymobClub();
        [, $late] = $this->pending($club);
        [, $onTime] = $this->pending($club);
        $this->deliver($this->obj($onTime, ['id' => 560010]));
        $this->travelTo($late->expires_at->copy()->addSecond());
        $this->deliver($this->obj($late, ['id' => 560011]));

        $page = fn (PaymentIntent $i) => $this->get(route('site.pay.return', ['club' => $club->slug, 'reference' => $i->reference]));
        $page($late)->assertInertia(fn ($p) => $p->where('status', PaymentIntent::PAID)->where('held', true));
        $page($onTime)->assertInertia(fn ($p) => $p->where('status', PaymentIntent::PAID)->where('held', false));
        $this->getJson(route('site.pay.status', ['club' => $club->slug, 'reference' => $late->reference]))->assertExactJson(['status' => 'paid', 'held' => true]);
    }

    // ── staff decide, each by their own permission, with a reason ────────────────────────────────────────────────

    public function test_approving_held_money_applies_it_to_its_own_invoice_and_activates_with_who_when_and_why(): void
    {
        $club = $this->paymobClub();
        $owner = $this->owner($club);
        [$membership, $intent] = $this->pending($club);
        $this->travelTo($intent->expires_at->copy()->addSecond());
        $this->deliver($this->obj($intent));
        $payment = $this->heldPayment($club);
        $apply = fn (array $extra) => $this->actingAs($owner)->post(route('club.payments.allocate', $payment), $extra + [
            'invoice_id' => $membership->invoice_id, 'amount' => '25.000', 'client_key' => (string) Str::uuid(),
        ]);

        // Only this invoice is offered while it is open.
        $this->actingAs($owner)->get(route('club.invoices.show', $membership->invoice_id))
            ->assertInertia(fn ($p) => $p->where('creditTargets', [['id' => $membership->invoice_id, 'number' => $membership->invoice->number, 'balance' => '25.000']]));

        $apply([])->assertSessionHasErrors('reason');
        $this->asTenant($club, fn () => $this->assertSame(Membership::PENDING, $membership->fresh()->status));
        $apply(['reason' => 'دفع العميل قبل انتهاء الصفحة بثوانٍ'])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($membership, $payment, $owner) {
            $this->assertSame(Invoice::PAID, Invoice::findOrFail($membership->invoice_id)->status);
            $this->assertSame(Membership::ACTIVE, $membership->fresh()->status);
            $this->assertSame('0.000', $payment->fresh()->creditLeft());
            $audit = AuditLog::where('event', 'payment.credit_applied')->sole();
            $this->assertSame('دفع العميل قبل انتهاء الصفحة بثوانٍ', $audit->properties['reason']);
            $this->assertSame($owner->id, (int) $audit->actor_id, 'who');
            $this->assertNotNull($audit->created_at, 'when');
            $this->assertSame(0, StaffNotification::where('key', 'payment-credit:'.$payment->id)->whereNull('read_at')->count(), 'the alert is settled');
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }

    public function test_held_money_goes_to_no_other_invoice_while_its_own_is_open_by_any_path(): void
    {
        $club = $this->paymobClub();
        $owner = $this->owner($club);
        $member = $this->member($club);
        [$membership, $intent] = $this->pending($club, $member);
        $other = $this->asTenant($club, fn () => app(InvoiceService::class)->issue($member, [['description' => 'خدمة', 'unit_price' => 10]], ['type' => 'other']));
        $this->travelTo($intent->expires_at->copy()->addSecond());
        $this->deliver($this->obj($intent));
        $payment = $this->heldPayment($club);

        $this->actingAs($owner)->post(route('club.payments.allocate', $payment), [
            'invoice_id' => $other->id, 'amount' => '10', 'reason' => 'تجربة', 'client_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('invoice_id');
        $this->asTenant($club, function () use ($payment, $other) {
            try {
                app(PaymentService::class)->allocate($payment, $other, '10', 'من الخدمة مباشرة');
                $this->fail('held money was applied to another invoice');
            } catch (BusinessRuleException $e) {
                $this->assertSame('credit.own_invoice_open', $e->rule);
            }
            $this->assertSame('0.000', $other->fresh()->paid_total);
        });

        // Its own invoice withdrawn (the sale cancelled): a person may then apply it elsewhere, with a reason.
        $this->asTenant($club, fn () => app(MembershipService::class)->cancel($membership->fresh(), 'أُعيد البيع بتواريخ جديدة'));
        $this->actingAs($owner)->post(route('club.payments.allocate', $payment), [
            'invoice_id' => $other->id, 'amount' => '10', 'reason' => 'نُقل إلى فاتورة الخدمة', 'client_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame([Invoice::PAID, '15.000'], [$other->fresh()->status, $payment->fresh()->creditLeft()]));
    }

    public function test_paying_held_money_back_through_paymob_waits_for_confirmation_and_is_not_shown_as_returned(): void
    {
        $club = $this->paymobClub();
        $owner = $this->owner($club);
        [$membership, $intent] = $this->pending($club);
        $this->travelTo($intent->expires_at->copy()->addSecond());
        $this->deliver($this->obj($intent));
        $payment = $this->heldPayment($club);
        $refund = fn (array $extra) => $this->actingAs($owner)->post(route('club.payments.refund-credit', $payment), $extra + [
            'amount' => '25.000', 'method' => 'paymob', 'client_key' => (string) Str::uuid(),
        ]);

        $refund([])->assertSessionHasErrors('reason');
        $refund(['reason' => 'طلب العميل استرداد المبلغ'])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($payment, $membership) {
            $back = Payment::where('refund_of_id', $payment->id)->sole();
            $this->assertSame(Payment::PENDING, $back->status, 'waits until finance sees it made in Paymob');
            $this->assertNull($back->receipt_number);
            $this->assertSame('0.000', $payment->fresh()->credit_used, 'not counted as paid back yet');
            $this->assertSame('0.000', $payment->fresh()->creditLeft(), 'but held: neither applied nor paid back twice');
            $this->assertSame('طلب العميل استرداد المبلغ', AuditLog::where('event', 'payment.refund_requested')->sole()->properties['reason']);
            $this->assertSame(Membership::PENDING, $membership->fresh()->status);
            try {
                app(PaymentService::class)->allocate($payment->fresh(), Invoice::findOrFail($membership->invoice_id), '25', 'بعد طلب الرد');
                $this->fail('money being paid back was applied too');
            } catch (BusinessRuleException $e) {
                $this->assertSame('credit.amount', $e->rule);
            }
        });
    }

    public function test_cash_bank_transfer_and_desk_credits_are_untouched(): void
    {
        $club = $this->paymobClub();
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        $this->asTenant($club, function () use ($member, $plan) {
            $first = app(MembershipService::class)->sell($member, $plan);
            $transfer = app(PaymentService::class)->record($first->invoice, '25.000', 'bank_transfer', ['reference' => 'TR-1']);
            app(PaymentService::class)->confirm($transfer);
            $this->assertSame(Membership::ACTIVE, $first->fresh()->status);

            // Desk credit (a transfer confirmed after the invoice was paid in cash) is applied elsewhere without a
            // reason, as before.
            $invoice = app(InvoiceService::class)->issue($member, [['description' => 'خدمة', 'unit_price' => '10']], ['type' => 'other']);
            $other = app(InvoiceService::class)->issue($member, [['description' => 'خدمة', 'unit_price' => '10']], ['type' => 'other']);
            $credit = app(PaymentService::class)->record($invoice, '10', 'bank_transfer');
            app(PaymentService::class)->record($invoice->fresh(), '10', 'cash');
            app(PaymentService::class)->confirm($credit);
            app(PaymentService::class)->allocate($credit->fresh(), $other, '10');
            $this->assertSame(Invoice::PAID, $other->fresh()->status);
            $this->assertSame(0, AuditLog::where('event', 'payment.gateway_held')->count());
        });
    }
}
