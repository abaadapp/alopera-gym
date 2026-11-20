<?php

namespace Tests\Feature;

use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\GiftService;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\MemberNotifier;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Payment links v2 (owner's decisions, 2026-11-20): each link its own secure address; one live link per invoice; a new
 * one once the last is over or cancelled, while the payment allows it; an ended link never opens a page; every link
 * kept; a link for any issued invoice, and with the invoice from the editor; the invoice's message follows its live
 * link. The money stays the invoice's: paid once, only by a signed, matching callback.
 */
class PaymentLinksV2Test extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'v2-hmac-secret';

    /** @return array{0: Tenant, 1: User} */
    private function ready(): array
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test_secret', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));

        return [$club, $this->owner($club)];
    }

    private function holding(Tenant $club, array $permissions): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.Str::lower(Str::random(8)), 'name' => 'مخصص', 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception');
        $user->forceFill(['role_id' => $role->id])->save();

        return $user->fresh();
    }

    /** An invoice issued from the editor, on credit. */
    private function invoice(Tenant $club, User $by, array $over = []): Invoice
    {
        $this->actingAs($by)->post(route('club.invoices.store'), array_replace([
            'customer_mode' => 'new', 'customer_name' => 'شركة الواحة', 'customer_phone' => '96891234567', 'issue' => 1,
            'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(15)->toDateString(), 'payment_method' => 'credit',
            'lines' => [['description' => 'تأجير القاعة', 'quantity' => 1, 'unit_price' => 20, 'discount' => 0]],
            'client_key' => (string) Str::uuid(),
        ], $over))->assertSessionHasNoErrors();

        return $this->asTenant($club, fn () => Invoice::latest('id')->firstOrFail());
    }

    private function openLink(User $by, int $invoiceId, array $over = [])
    {
        return $this->actingAs($by)->post(route('club.payment-links.open', $invoiceId), $over + ['valid_days' => 7, 'client_key' => (string) Str::uuid()]);
    }

    /** @return list<PaymentLink> */
    private function links(Tenant $club, int $invoiceId): array
    {
        return $this->asTenant($club, fn () => PaymentLink::with('invoice')->where('invoice_id', $invoiceId)->orderBy('id')->get()->all());
    }

    private function linkUrl(Tenant $club, PaymentLink $link): string
    {
        return route('site.pay.link', ['club' => $club->slug, 'token' => $link->token]);
    }

    private function payThrough(Tenant $club, PaymentLink $link)
    {
        auth()->logout();

        return $this->post(route('site.pay.link.start', ['club' => $club->slug, 'token' => $link->token]));
    }

    private function payInvoiceUrl(Tenant $club, Invoice $invoice)
    {
        auth()->logout();

        return $this->post(route('site.pay.start', ['club' => $club->slug, 'token' => $invoice->public_token]));
    }

    private function intents(Tenant $club, int $invoiceId): int
    {
        return $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $invoiceId)->count());
    }

    private function paymobSays(PaymentIntent $intent, int $order, array $over = []): void
    {
        $obj = array_replace_recursive([
            'id' => random_int(100000, 999999), 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true, 'is_auth' => false,
            'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true,
            'integration_id' => 111, 'has_parent_transaction' => false, 'order' => ['id' => $order, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-20T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ], $over);
        $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, self::HMAC), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
    }

    private function lastIntent(Tenant $club, int $invoiceId): PaymentIntent
    {
        return $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $invoiceId)->latest('id')->firstOrFail());
    }

    private function audit(Tenant $club, string $event): array
    {
        return DB::table('audit_logs')->where('tenant_id', $club->id)->where('event', $event)->orderBy('id')->pluck('properties')->map(fn ($p) => json_decode((string) $p, true))->all();
    }

    public function test_after_a_cancel_a_new_link_has_its_own_address_and_the_old_one_never_opens_a_page(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_v2a', 'intention_order_id' => 7101], 201)]);
        [$club, $owner] = $this->ready();
        $invoice = $this->invoice($club, $owner);
        $this->openLink($owner, $invoice->id, ['valid_days' => 1])->assertSessionHasNoErrors();
        [$first] = $this->links($club, $invoice->id);
        $this->actingAs($owner)->post(route('club.payment-links.cancel', $first->id))->assertSessionHasNoErrors();

        // No live link: neither its address nor the invoice's opens a page until a new link is made (no link is made by
        // asking). The invoice owes as before.
        auth()->logout();
        $this->get($this->linkUrl($club, $first))->assertOk()->assertInertia(fn ($p) => $p->component('Site/Pay')->where('closed', fn ($m) => str_contains($m, 'أُلغي')));
        $this->get(route('site.pay.show', ['club' => $club->slug, 'token' => $invoice->public_token]))->assertInertia(fn ($p) => $p->where('closed', fn ($m) => str_contains($m, 'أُلغي')));
        $this->payThrough($club, $first);
        $this->payInvoiceUrl($club, $invoice);
        Http::assertNothingSent();
        $this->assertSame(['20.000', Invoice::ISSUED], [$invoice->fresh()->balance(), $invoice->fresh()->status]);

        // A new link of the same invoice: its own address; the first stays, cancelled.
        $this->openLink($owner, $invoice->id, ['valid_days' => 3])->assertSessionHasNoErrors();
        [$first, $second] = $this->links($club, $invoice->id);
        $this->assertNotSame($first->token, $second->token);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', (string) $second->token);
        $this->assertEqualsWithDelta(now()->addDays(3)->getTimestamp(), $second->expires_at->getTimestamp(), 5);
        $this->asTenant($club, fn () => $this->assertSame([PaymentLink::CANCELLED, PaymentLink::PENDING], [$first->status(), $second->status()]));

        // Only the new one opens a page; the old one opens nothing, even now that a page of the invoice is open.
        $this->payThrough($club, $first);
        Http::assertNothingSent();
        $this->payThrough($club, $second)->assertRedirect();
        $this->payThrough($club, $first);
        Http::assertSentCount(1);
        $this->assertSame(1, $this->intents($club, $invoice->id));

        // Paid through it: once, at the invoice, by the signed matching callback (sent twice).
        $intent = $this->lastIntent($club, $invoice->id);
        $this->paymobSays($intent, 7101, ['id' => 710001]);
        $this->paymobSays($intent, 7101, ['id' => 710001]);
        $this->assertSame([Invoice::PAID, '20.000'], [$invoice->fresh()->status, $invoice->fresh()->paid_total]);
        $this->assertSame(1, $this->asTenant($club, fn () => Payment::where('invoice_id', $invoice->id)->count()));

        // The invoice's page: the live link (paid) and every link it had, newest first.
        $this->actingAs($owner)->get(route('club.invoices.show', $invoice->id))->assertInertia(fn ($p) => $p
            ->where('paymentLink.live.id', $second->id)->where('paymentLink.live.status', 'paid')->where('paymentLink.live.payment.transaction', '710001')
            ->where('paymentLink.history.0.id', $second->id)->where('paymentLink.history.1.id', $first->id)->where('paymentLink.history.1.status', 'cancelled')
            ->where('paymentLink.history.1.payment', null)->where('paymentLink.can_open', false));
    }

    public function test_one_live_link_per_invoice_and_one_past_its_time_is_replaced_by_the_new_one(): void
    {
        Http::fake();
        [$club, $owner] = $this->ready();
        $invoice = $this->invoice($club, $owner);
        $this->openLink($owner, $invoice->id, ['valid_days' => 1])->assertSessionHasNoErrors();

        // While it runs: no second link (cancel it first).
        $this->openLink($owner, $invoice->id)->assertSessionHasErrors('error');
        $this->assertStringContainsString('رابط دفع نشط', session('errors')->first('error'));
        $this->assertCount(1, $this->links($club, $invoice->id));

        // The database holds that too: a second live row of the invoice is refused.
        $this->assertThrows(fn () => $this->asTenant($club, fn () => DB::transaction(fn () => PaymentLink::create([
            'invoice_id' => $invoice->id, 'token' => Str::random(48), 'description' => 'x', 'expires_at' => now()->addDay(),
        ]))), QueryException::class);

        // Past its time (no page was opened from it): the new link replaces it, which is kept, «replaced».
        $this->travel(25)->hours();
        [$first] = $this->links($club, $invoice->id);
        $this->asTenant($club, fn () => $this->assertSame(PaymentLink::EXPIRED, $first->status()));
        $this->openLink($owner, $invoice->id)->assertSessionHasNoErrors();
        [$first, $second] = $this->links($club, $invoice->id);
        $this->assertNotNull($first->replaced_at);
        $this->asTenant($club, fn () => $this->assertSame([PaymentLink::REPLACED, PaymentLink::PENDING], [$first->status(), $second->status()]));
        $this->assertSame($first->id, $this->audit($club, 'payment_link.created')[1]['replaces']);
        $this->assertSame($first->id, $this->audit($club, 'payment_link.replaced')[0]['link']);

        // The replaced one's address: the invoice shown, why it is not payable, and no page.
        auth()->logout();
        $this->get($this->linkUrl($club, $first))->assertInertia(fn ($p) => $p->where('closed', fn ($m) => str_contains($m, 'حلّ محله')));
        $this->payThrough($club, $first);
        Http::assertNothingSent();
        $this->assertSame(0, $this->intents($club, $invoice->id));
    }

    public function test_a_link_whose_paymob_page_brought_nothing_ends_only_after_the_staff_checked_paymob(): void
    {
        Http::fake(['*/v1/intention/' => Http::sequence()->push(['client_secret' => 'cs_u1', 'intention_order_id' => 7201], 201)->push(['client_secret' => 'cs_u2', 'intention_order_id' => 7202], 201)]);
        [$club, $owner] = $this->ready();
        $cancelled = $this->invoice($club, $owner);
        $replaced = $this->invoice($club, $owner);
        foreach ([$cancelled, $replaced] as $invoice) {
            $this->openLink($owner, $invoice->id, ['valid_days' => 1])->assertSessionHasNoErrors();
            $this->payThrough($club, $this->links($club, $invoice->id)[0])->assertRedirect(); // the customer reached Paymob, and left
        }
        $reference = fn (Invoice $i) => $this->lastIntent($club, $i->id)->reference;

        // While the page may still take money: refused, checked or not.
        $link = $this->links($club, $cancelled->id)[0];
        $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id), ['paymob_checked' => 1])->assertSessionHasErrors('error');
        $this->assertStringContainsString('فتح العميل صفحة الدفع', session('errors')->first('error'));

        // Past the page's time and the late window, its time running out is not taken as proof: the staff are asked to
        // look the reference up at Paymob first, and the invoice page lists it.
        $this->travelTo($link->expires_at->copy()->addMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES + 5));
        $this->actingAs($owner)->get(route('club.invoices.show', $cancelled->id))->assertInertia(fn ($p) => $p->where('paymentLink.live.unanswered', [$reference($cancelled)]));
        $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id))->assertSessionHasErrors('error');
        $this->assertStringContainsString($reference($cancelled), session('errors')->first('error'));
        $this->assertNull($link->fresh()->cancelled_at);
        $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id), ['paymob_checked' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($link->fresh()->cancelled_at);
        $this->assertSame([$reference($cancelled)], $this->audit($club, 'payment_link.cancelled')[0]['paymob_checked']);

        // Replacing one is ending it too.
        $this->openLink($owner, $replaced->id)->assertSessionHasErrors('error');
        $this->assertStringContainsString($reference($replaced), session('errors')->first('error'));
        $this->assertCount(1, $this->links($club, $replaced->id));
        $this->openLink($owner, $replaced->id, ['paymob_checked' => 1])->assertSessionHasNoErrors();
        $this->assertCount(2, $this->links($club, $replaced->id));
        $this->assertSame([$reference($replaced)], $this->audit($club, 'payment_link.replaced')[0]['paymob_checked']);
        $this->assertSame([Invoice::ISSUED, Invoice::ISSUED], [$cancelled->fresh()->status, $replaced->fresh()->status], 'the invoices untouched');
    }

    public function test_money_the_bank_still_holds_stops_ending_or_replacing_a_link_whatever_time_passed(): void
    {
        Http::fake(['*/v1/intention/' => Http::sequence()->push(['client_secret' => 'cs_b1', 'intention_order_id' => 7301], 201)->push(['client_secret' => 'cs_b2', 'intention_order_id' => 7302], 201)]);
        [$club, $owner] = $this->ready();
        $atBank = $this->invoice($club, $owner);
        $authorized = $this->invoice($club, $owner);
        foreach ([$atBank, $authorized] as $invoice) {
            $this->openLink($owner, $invoice->id, ['valid_days' => 1])->assertSessionHasNoErrors();
            $this->payThrough($club, $this->links($club, $invoice->id)[0])->assertRedirect();
        }
        // A signed «pending»; a signed authorization not captured.
        $this->paymobSays($this->lastIntent($club, $atBank->id), 7301, ['pending' => true, 'success' => false]);
        $this->paymobSays($this->lastIntent($club, $authorized->id), 7302, ['is_auth' => true, 'is_capture' => false]);
        $this->assertSame(['pending', 'authorized_only'], $this->asTenant($club, fn () => DB::table('payment_webhook_events')->orderBy('id')->pluck('outcome')->all()));

        // Days later, checked at Paymob or not: neither link is cancelled or replaced, and each shows «قيد التنفيذ».
        $this->travel(3)->days();
        foreach ([$atBank, $authorized] as $invoice) {
            $link = $this->links($club, $invoice->id)[0];
            $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id), ['paymob_checked' => 1])->assertSessionHasErrors('error');
            $this->assertStringContainsString('قيد التنفيذ لدى البنك', session('errors')->first('error'));
            $this->openLink($owner, $invoice->id, ['paymob_checked' => 1])->assertSessionHasErrors('error');
            $this->assertCount(1, $this->links($club, $invoice->id));
            $this->assertNull($link->fresh()->cancelled_at);
            $this->asTenant($club, fn () => $this->assertSame(PaymentLink::PROCESSING, $link->fresh()->status()));
        }
    }

    public function test_any_issued_invoice_gets_a_link_and_is_paid_once_through_it_subscription_gift_store_and_part_paid(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_any', 'intention_order_id' => 7401], 201)]);
        [$club, $owner] = $this->ready();
        $plan = $this->plan($club, ['price' => 25]);
        $member = $this->member($club);
        $membership = $this->asTenant($club, fn () => app(MembershipService::class)->sell($member, $plan));
        $gift = $this->asTenant($club, fn () => app(GiftService::class)->issue($plan, [
            'giver_name' => 'Sara', 'giver_phone' => '96891234567', 'recipient_name' => 'Noor', 'recipient_phone' => '91234567', 'message' => 'Happy birthday',
        ], 'staff'));
        $towel = $this->asTenant($club, function () {
            $product = Product::create(['name' => 'منشفة النادي', 'price' => 3.5]);
            app(StockService::class)->move($product, 10, 'adjustment', null, 'opening', 2);

            return $product->fresh();
        });
        $store = $this->invoice($club, $owner, ['lines' => [['product_id' => $towel->id, 'description' => 'منشفة النادي', 'quantity' => 2, 'unit_price' => 3.5, 'discount' => 0]]]);
        $part = $this->invoice($club, $owner);
        $this->asTenant($club, fn () => app(PaymentService::class)->record($part->fresh(), '5.000', 'cash'));

        $cases = ['subscription' => Invoice::findOrFail($membership->invoice_id), 'gift' => $gift->invoice, 'store' => $store, 'part paid' => $part->fresh()];
        $order = 7400;
        foreach ($cases as $what => $invoice) {
            $owed = $invoice->fresh()->balance();
            $this->openLink($owner, $invoice->id)->assertSessionHasNoErrors();
            $link = $this->links($club, $invoice->id)[0];
            $this->payThrough($club, $link)->assertRedirect();
            $intent = $this->lastIntent($club, $invoice->id);
            $this->assertSame($owed, $intent->amount, "$what: the page asks what the invoice owes now");
            // Paymob's own order for the page (a callback naming another order is refused: order_mismatch).
            $transaction = ++$order * 100;
            $this->paymobSays($intent, (int) $intent->provider_order_id, ['id' => $transaction]);
            $this->paymobSays($intent, (int) $intent->provider_order_id, ['id' => $transaction]); // the same callback, late, again
            $this->assertSame(Invoice::PAID, $invoice->fresh()->status, $what);
            $this->assertSame(1, $this->asTenant($club, fn () => Payment::where('invoice_id', $invoice->id)->where('gateway', 'paymob')->count()), "$what: recorded once");
        }

        // What the invoice paid for is delivered by its payment, as from any payment (ActivatePaidItems on InvoicePaid).
        $this->asTenant($club, fn () => $this->assertSame(Membership::ACTIVE, $membership->fresh()->status));
        $this->asTenant($club, fn () => $this->assertSame(GiftMembership::PAID, $gift->fresh()->status));
        $this->assertSame(['5.000', '20.000'], [$this->asTenant($club, fn () => Payment::where('invoice_id', $part->id)->where('gateway', null)->value('amount')), $part->fresh()->paid_total]);
    }

    public function test_nothing_owed_a_single_baisa_or_payments_off_makes_no_link(): void
    {
        Http::fake();
        [$club, $owner] = $this->ready();
        $paid = $this->invoice($club, $owner, ['payment_method' => 'cash', 'payment_date' => today()->toDateString()]);
        $this->openLink($owner, $paid->id)->assertSessionHasErrors('error');
        $this->assertStringContainsString('لا يوجد مبلغ مستحق', session('errors')->first('error'));

        $baisa = $this->invoice($club, $owner);
        $this->asTenant($club, fn () => app(PaymentService::class)->record($baisa->fresh(), '4.995', 'cash'));
        $this->openLink($owner, $baisa->id)->assertSessionHasErrors('error');

        $this->asTenant($club, fn () => TenantIntegration::where('provider', 'paymob')->update(['is_enabled' => false]));
        $off = $this->invoice($club, $owner);
        $this->openLink($owner, $off->id)->assertSessionHasErrors('error');
        $this->assertStringContainsString('الدفع الإلكتروني غير مفعّل', session('errors')->first('error'));
        $this->assertSame(0, $this->asTenant($club, fn () => PaymentLink::count()));
    }

    public function test_the_editor_issues_with_its_link_together_or_neither_and_once_when_sent_twice(): void
    {
        Http::fake();
        [$club, $owner] = $this->ready();
        $key = (string) Str::uuid();
        $form = ['customer_mode' => 'new', 'customer_name' => 'شركة الواحة', 'customer_phone' => '96891234567', 'issue' => 1,
            'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(15)->toDateString(), 'payment_method' => 'credit',
            'lines' => [['description' => 'تأجير القاعة', 'quantity' => 1, 'unit_price' => 20, 'discount' => 0]],
            'with_link' => 1, 'link_days' => 3, 'client_key' => $key];

        // Pressed twice: one invoice, one link, and the invoice's page with it.
        $first = $this->actingAs($owner)->post(route('club.invoices.store'), $form);
        $second = $this->actingAs($owner)->post(route('club.invoices.store'), $form);
        $invoice = $this->asTenant($club, fn () => Invoice::sole());
        $first->assertRedirect(route('club.invoices.show', $invoice->id));
        $second->assertRedirect(route('club.invoices.show', $invoice->id));
        $this->assertCount(1, $this->links($club, $invoice->id));
        $this->assertEqualsWithDelta(now()->addDays(3)->getTimestamp(), $this->links($club, $invoice->id)[0]->expires_at->getTimestamp(), 5);

        // A link refused (paid in full now: nothing left to pay) issues nothing either.
        $this->actingAs($owner)->post(route('club.invoices.store'), ['payment_method' => 'cash', 'payment_date' => today()->toDateString(), 'client_key' => (string) Str::uuid()] + $form)
            ->assertSessionHasErrors('error');
        $this->assertSame(1, $this->asTenant($club, fn () => Invoice::count()), 'not issued without its link');

        // No validity chosen: the form says so, nothing issued.
        $this->actingAs($owner)->post(route('club.invoices.store'), ['link_days' => null, 'client_key' => (string) Str::uuid()] + $form)->assertSessionHasErrors('link_days');
        $this->assertSame(1, $this->asTenant($club, fn () => Invoice::count()));

        // Without the links permission: refused, nothing issued; the plain issue still works.
        $clerk = $this->holding($club, ['invoices.manage', 'invoices.view']);
        $this->actingAs($clerk)->post(route('club.invoices.store'), ['client_key' => (string) Str::uuid()] + $form)->assertForbidden();
        $this->assertSame(1, $this->asTenant($club, fn () => Invoice::count()));
        $this->actingAs($clerk)->post(route('club.invoices.store'), ['with_link' => 0, 'client_key' => (string) Str::uuid()] + $form)->assertSessionHasNoErrors();
        $this->assertSame([2, 1], $this->asTenant($club, fn () => [Invoice::count(), PaymentLink::count()]));
    }

    public function test_the_invoice_message_carries_the_live_link_and_none_is_sent_once_its_links_ended(): void
    {
        Http::fake();
        [$club, $owner] = $this->ready();
        $this->asTenant($club, function () {
            TenantIntegration::create(['provider' => 'whatsapp', 'is_enabled' => true, 'credentials' => ['phone_number_id' => 'PNID-9', 'access_token' => 'EAAG-secret'], 'public_identifier' => 'PNID-9']);
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => 'approved']);
        });
        $member = $this->member($club, ['phone' => '96891234567']);
        $invoice = $this->invoice($club, $owner, ['customer_mode' => 'member', 'member_id' => $member->id]);
        $pay = fn () => $this->asTenant($club, fn () => app(MemberNotifier::class)->paymentLink($invoice->fresh()));

        // Never a link: the invoice's own pay page, as before.
        $this->assertSame(route('site.pay.show', ['club' => $club->slug, 'token' => $invoice->public_token]), $pay());

        // A live link: its address, in the message sent from the invoice page too.
        $this->openLink($owner, $invoice->id)->assertSessionHasNoErrors();
        $link = $this->links($club, $invoice->id)[0];
        $this->assertSame($this->linkUrl($club, $link), $pay());
        $this->actingAs($owner)->post(route('club.invoices.send', $invoice->id))->assertSessionHas('success');
        $sent = $this->asTenant($club, fn () => Message::where('template_key', 'invoice_issued')->latest('id')->first());
        $this->assertNotNull($sent);
        $this->assertStringContainsString($link->token, json_encode($sent->variables ?? $sent->toArray(), JSON_UNESCAPED_SLASHES));

        // Its links ended: no link is made, nothing is sent, and the staff are told why; the invoice page has no pay button.
        $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id))->assertSessionHasNoErrors();
        $before = $this->asTenant($club, fn () => Message::count());
        $this->actingAs($owner)->post(route('club.invoices.send', $invoice->id))->assertSessionHas('error', fn ($m) => str_contains($m, 'لا يوجد رابط دفع نشط'));
        $this->assertSame([$before, 1], $this->asTenant($club, fn () => [Message::count(), PaymentLink::count()]));
        $this->assertSame(route('site.invoice', ['club' => $club->slug, 'token' => $invoice->public_token]), $pay());
        auth()->logout();
        $this->get(route('site.invoice', ['club' => $club->slug, 'token' => $invoice->public_token]))->assertInertia(fn ($p) => $p->where('pay_url', null));
    }

    public function test_links_take_their_permission_and_stay_in_their_branch_and_club(): void
    {
        Http::fake();
        [$club, $owner] = $this->ready();
        [$khoud, $salalah] = $this->asTenant($club, fn () => [Branch::create(['name' => 'فرع الخوض', 'is_active' => true]), Branch::create(['name' => 'فرع صلالة', 'is_active' => true])]);
        $ours = $this->invoice($club, $owner);
        $theirs = $this->invoice($club, $owner);
        $this->asTenant($club, fn () => [$ours->forceFill(['branch_id' => $khoud->id])->save(), $theirs->forceFill(['branch_id' => $salalah->id])->save()]);

        // Viewing invoices is not making links.
        $viewer = $this->holding($club, ['invoices.view']);
        $this->openLink($viewer, $ours->id)->assertForbidden();
        $this->actingAs($viewer)->get(route('club.invoices.show', $ours->id))->assertInertia(fn ($p) => $p->where('paymentLink.can_manage', false));

        // A desk tied to a branch: its branch's invoices only.
        $desk = $this->holding($club, ['payments.links', 'invoices.view']);
        $desk->forceFill(['branch_id' => $khoud->id])->save();
        $this->openLink($desk, $theirs->id)->assertNotFound();
        $this->openLink($desk, $ours->id)->assertSessionHasNoErrors();
        $this->assertSame([1, 0], [count($this->links($club, $ours->id)), count($this->links($club, $theirs->id))]);

        // Another club: neither its invoices nor its links' addresses.
        [$other, $otherOwner] = $this->ready();
        $this->openLink($otherOwner, $ours->id)->assertNotFound();
        $link = $this->links($club, $ours->id)[0];
        auth()->logout();
        $this->get(route('site.pay.link', ['club' => $other->slug, 'token' => $link->token]))->assertNotFound();
        $this->post(route('site.pay.link.start', ['club' => $other->slug, 'token' => $link->token]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_the_migrations_keep_every_link_and_going_back_is_refused_once_an_invoice_has_two(): void
    {
        [$club, $owner] = $this->ready();
        $invoice = $this->invoice($club, $owner);
        $this->openLink($owner, $invoice->id, ['valid_days' => 1])->assertSessionHasNoErrors();
        $lift = require base_path('app/Modules/Payments/Database/migrations/2026_11_20_000300_allow_many_links_per_invoice.php');
        $tokens = require base_path('app/Modules/Payments/Database/migrations/2026_11_20_000200_add_link_tokens_to_payment_links.php');

        // One link per invoice so far: the old key comes back and goes again, nothing lost.
        $lift->down();
        $this->assertTrue(\Schema::hasIndex('payment_links', 'payment_links_invoice_id_unique'));
        $lift->up();
        $this->assertFalse(\Schema::hasIndex('payment_links', 'payment_links_invoice_id_unique'));
        $this->assertTrue(\Schema::hasIndex('payment_links', 'payment_links_one_live_per_invoice'));

        // Two links of one invoice: going back is refused, both kept; so is dropping the addresses.
        $this->actingAs($owner)->post(route('club.payment-links.cancel', $this->links($club, $invoice->id)[0]->id))->assertSessionHasNoErrors();
        $this->openLink($owner, $invoice->id)->assertSessionHasNoErrors();
        $this->assertThrows(fn () => $lift->down(), \RuntimeException::class);
        $this->assertThrows(fn () => $tokens->down(), \RuntimeException::class);
        $this->assertCount(2, $this->links($club, $invoice->id));
        $this->assertFalse(\Schema::hasIndex('payment_links', 'payment_links_invoice_id_unique'));
    }
}
