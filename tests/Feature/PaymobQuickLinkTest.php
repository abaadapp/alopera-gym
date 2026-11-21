<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentReview;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\PaymentLinks;
use App\Modules\Payments\Services\QuickLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Payment links made inside Paymob (QuickLink) with the club's API key (owner, 2026-11-21): the address sent is
 * Paymob's own, its money is recorded only by a signed callback of its own order, once; the club's page of the invoice
 * leads to it, the desk takes nothing while it may be paid, and cancelling closes it at Paymob first — refused, nothing
 * changed, when Paymob does not confirm. Without the key, nothing changes. Test keys and Paymob's answers faked only.
 */
class PaymobQuickLinkTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'quicklink-hmac-secret';

    private const API_KEY = 'ZXlKaGJHY2lPaUpJVXpVeE1pSXNJblI1Y0NJNklrcFhWQ0o5-test-api-key';

    private const ORDER = 777001;

    /**
     * Paymob faked once per test (a later fake would not replace an earlier one).
     *
     * @param  array<string, mixed>  $links  Paymob's answer to making a link
     * @param  list<array<string, mixed>>  $cancels  its answers to cancelling, in turn
     */
    private function paymob(array $links = [], array $cancels = [['success' => true]], int $status = 200, bool $timeout = false): void
    {
        $cancel = Http::sequence();
        foreach ($cancels as $answer) {
            $cancel->push($answer, 200);
        }
        Http::fake([
            '*/api/auth/tokens' => Http::response(['token' => 'bearer-token-1'], 201),
            '*/api/ecommerce/payment-links/cancel' => $cancel,
            '*/api/ecommerce/payment-links' => $timeout
                ? fn () => throw new ConnectionException('cURL error 28: Operation timed out')
                : Http::response($links + ['id' => 9001, 'order' => self::ORDER, 'shorten_url' => 'https://paymob.link/abc12', 'client_url' => 'https://oman.paymob.com/api/ecommerce/payment-links/unrestricted?token=x', 'state' => 'created'], $status),
            '*/v1/intention/' => Http::response(['client_secret' => 'cs_page', 'intention_order_id' => 555], 201),
        ]);
    }

    /** @return array{0: Tenant, 1: User} */
    private function ready(bool $apiKey = true): array
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'omn_sk_test_secret', 'public_key' => 'omn_pk_test_public', 'hmac_secret' => self::HMAC, 'integration_ids' => '111']
                + ($apiKey ? ['api_key' => self::API_KEY] : []),
        ]));

        return [$club, $this->owner($club)];
    }

    private function invoice(Tenant $club, User $by): Invoice
    {
        $this->actingAs($by)->post(route('club.invoices.store'), [
            'customer_mode' => 'new', 'customer_name' => 'شركة الواحة', 'customer_phone' => '96891234567', 'issue' => 1,
            'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(15)->toDateString(), 'payment_method' => 'credit',
            'lines' => [['description' => 'تأجير القاعة', 'quantity' => 1, 'unit_price' => 20, 'discount' => 0]],
            'client_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        return $this->asTenant($club, fn () => Invoice::latest('id')->firstOrFail());
    }

    private function openLink(User $by, Invoice $invoice)
    {
        return $this->actingAs($by)->post(route('club.payment-links.open', $invoice->id), ['valid_days' => 7, 'client_key' => (string) Str::uuid()]);
    }

    private function link(Tenant $club, Invoice $invoice): PaymentLink
    {
        return $this->asTenant($club, fn () => PaymentLink::with('invoice')->where('invoice_id', $invoice->id)->sole());
    }

    private function attempt(Tenant $club, Invoice $invoice): PaymentIntent
    {
        return $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $invoice->id)->where('channel', QuickLinks::CHANNEL)->latest('id')->firstOrFail());
    }

    /** A Paymob callback, signed with the club's HMAC secret unless $sign is false. */
    private function paymobSays(PaymentIntent $intent, int $order, array $over = [], bool $sign = true): void
    {
        $obj = array_replace_recursive([
            'id' => random_int(100000, 999999), 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true, 'is_auth' => false,
            'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true,
            'integration_id' => 111, 'has_parent_transaction' => false,
            // A QuickLink's callback may carry Paymob's own merchant order id, not ours: its signed order finds it.
            'order' => ['id' => $order, 'merchant_order_id' => 'PAYMOB-'.$order],
            'created_at' => '2026-11-21T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ], $over);
        $hmac = $sign ? PaymobSignature::compute($obj, self::HMAC) : str_repeat('0', 128);
        $answer = $this->postJson(route('webhooks.paymob').'?hmac='.$hmac, ['type' => 'TRANSACTION', 'obj' => $obj]);
        $sign ? $answer->assertOk() : $answer->assertUnauthorized();
    }

    /** @return array<string, string|list<string>> a multipart request's parts by name (payment_methods: every one) */
    private static function parts(HttpRequest $r): array
    {
        $parts = [];
        foreach ($r->data() as $part) {
            $parts[$part['name']] = $part['name'] === 'payment_methods' ? [...($parts['payment_methods'] ?? []), $part['contents']] : $part['contents'];
        }

        return $parts;
    }

    private function paid(Tenant $club, Invoice $invoice): array
    {
        return $this->asTenant($club, fn () => [Invoice::find($invoice->id)->status, Payment::where('invoice_id', $invoice->id)->where('method', 'paymob')->count()]);
    }

    public function test_with_the_api_key_the_link_is_made_inside_paymob_its_address_is_sent_and_its_signed_order_pays_the_invoice_once(): void
    {
        $this->paymob();
        [$club, $owner] = $this->ready();
        $invoice = $this->invoice($club, $owner);
        $this->openLink($owner, $invoice)->assertSessionHasNoErrors();

        $attempt = $this->attempt($club, $invoice);
        $this->assertSame([PaymentIntent::PENDING, (string) self::ORDER, '9001', 'https://paymob.link/abc12'],
            [$attempt->status, $attempt->provider_order_id, $attempt->provider_link_id, $attempt->checkout_url]);
        $this->assertTrue($attempt->expires_at->equalTo($this->link($club, $invoice)->expires_at));

        // Paymob was asked with the API key for a token, then for the link: the invoice's amount, test, the club's
        // integration, our reference, our callback address.
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/auth/tokens') && $r['api_key'] === self::API_KEY);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/ecommerce/payment-links') && $r->hasHeader('Authorization', 'Bearer bearer-token-1')
            && array_intersect_key(self::parts($r), array_flip(['amount_cents', 'is_live', 'payment_methods', 'reference_id', 'notification_url', 'phone_number'])) === [
                'amount_cents' => (string) Money::toMinor($attempt->amount), 'reference_id' => $attempt->reference, 'notification_url' => route('webhooks.paymob'),
                'is_live' => 'false', 'phone_number' => '+96891234567', 'payment_methods' => ['111'],
            ]);

        // The address copied and sent by WhatsApp is Paymob's own.
        [$url, $whatsapp] = $this->asTenant($club, function () use ($club, $invoice) {
            $link = $this->link($club, $invoice);

            return [app(PaymentLinks::class)->url($link), app(PaymentLinks::class)->whatsapp($link)];
        });
        $this->assertSame('https://paymob.link/abc12', $url);
        $this->assertStringContainsString(rawurlencode('https://paymob.link/abc12'), $whatsapp);

        // The club's own page of the link leads to it: no second page at Paymob.
        auth()->logout();
        $this->post(route('site.pay.link.start', ['club' => $club->slug, 'token' => $this->link($club, $invoice)->token]))->assertRedirect('https://paymob.link/abc12');
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/v1/intention/'));

        // Paid: its signed order, under Paymob's own merchant order id — once, however often Paymob says it.
        $this->paymobSays($attempt, self::ORDER, ['id' => 424242]);
        $this->paymobSays($attempt, self::ORDER, ['id' => 424242]);
        $this->assertSame([Invoice::PAID, 1], $this->paid($club, $invoice));
        $this->assertSame(PaymentLink::PAID, $this->asTenant($club, fn () => $this->link($club, $invoice)->status()));
    }

    public function test_a_callback_unsigned_of_another_order_or_of_another_amount_records_nothing(): void
    {
        $this->paymob();
        [$club, $owner] = $this->ready();
        $invoice = $this->invoice($club, $owner);
        $this->openLink($owner, $invoice)->assertSessionHasNoErrors();
        $attempt = $this->attempt($club, $invoice);

        $this->paymobSays($attempt, self::ORDER, [], sign: false);
        // Our reference, but another order: not this link's money.
        $this->paymobSays($attempt, 999999, ['order' => ['merchant_order_id' => $attempt->reference]]);
        // Its order, another amount: not recorded as its payment.
        $this->paymobSays($attempt, self::ORDER, ['amount_cents' => Money::toMinor($attempt->amount) - 1000]);

        [$status, $payments] = $this->paid($club, $invoice);
        $this->assertNotSame(Invoice::PAID, $status);
        $this->assertSame('0.000', (string) $this->asTenant($club, fn () => Invoice::find($invoice->id)->paid_total));
    }

    public function test_the_desk_takes_nothing_while_it_may_be_paid_and_cancelling_closes_it_at_paymob_first(): void
    {
        $this->paymob(cancels: [['success' => false, 'message' => 'not allowed'], ['success' => true]]);
        [$club, $owner] = $this->ready();
        $invoice = $this->invoice($club, $owner);
        $this->openLink($owner, $invoice)->assertSessionHasNoErrors();
        $link = $this->link($club, $invoice);

        // The desk: refused, nothing recorded — whichever screen it comes from (one way in: PaymentService).
        try {
            $this->asTenant($club, fn () => app(PaymentService::class)->record(Invoice::find($invoice->id), '5.000', 'cash'));
            $this->fail('the desk took a payment while the Paymob link may be paid');
        } catch (BusinessRuleException $e) {
            $this->assertSame('payment.quicklink_open', $e->rule);
        }

        // Paymob does not confirm the cancel: refused, the link and its Paymob link as they were.
        $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id))->assertSessionHasErrors('error');
        $this->assertNull($this->link($club, $invoice)->cancelled_at);
        $this->assertSame(PaymentIntent::PENDING, $this->attempt($club, $invoice)->status);

        // Paymob confirms: closed there, then cancelled here — no «checked at Paymob» asked, the desk open again.
        $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id))->assertSessionHasNoErrors();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/ecommerce/payment-links/cancel') && $r['payment_link_id'] === '9001');
        $this->assertNotNull($this->link($club, $invoice)->cancelled_at);
        $this->assertSame([PaymentIntent::EXPIRED, QuickLinks::CANCELLED], [$this->attempt($club, $invoice)->status, $this->attempt($club, $invoice)->failure_reason]);
        $this->asTenant($club, fn () => app(PaymentService::class)->record(Invoice::find($invoice->id), '5.000', 'cash'));
        $this->assertSame('5.000', (string) $this->asTenant($club, fn () => Invoice::find($invoice->id)->paid_total));
    }

    public function test_without_the_api_key_nothing_changes(): void
    {
        // No API key: no call to Paymob when the link is made; the club's own address.
        $this->paymob();
        [$club, $owner] = $this->ready(apiKey: false);
        $invoice = $this->invoice($club, $owner);
        $this->openLink($owner, $invoice)->assertSessionHasNoErrors();
        Http::assertNothingSent();
        $link = $this->link($club, $invoice);
        $this->assertSame(route('site.pay.link', ['club' => $club->slug, 'token' => $link->token]), $this->asTenant($club, fn () => app(PaymentLinks::class)->url($link)));
    }

    public function test_paymob_refusing_the_link_tells_the_staff_and_leaves_the_clubs_own_page(): void
    {
        $this->paymob(['detail' => 'Invalid integration'], status: 400);
        [$other, $owner2] = $this->ready();
        $second = $this->invoice($other, $owner2);
        $this->openLink($owner2, $second)->assertSessionHas('error', fn ($m) => str_contains($m, 'رفضت Paymob إنشاء الرابط') && str_contains($m, 'صفحة الدفع من موقع النادي'));
        $failed = $this->attempt($other, $second);
        $this->assertSame([PaymentIntent::FAILED, 'paymob.quicklink_refused', null], [$failed->status, $failed->failure_reason, $failed->checkout_url]);
        $secondLink = $this->link($other, $second);
        $this->assertSame(route('site.pay.link', ['club' => $other->slug, 'token' => $secondLink->token]), $this->asTenant($other, fn () => app(PaymentLinks::class)->url($secondLink)));
        $this->asTenant($other, fn () => app(PaymentService::class)->record(Invoice::find($second->id), '1.000', 'cash'));
    }

    public function test_the_api_key_is_saved_write_only_and_leaves_the_checkout_as_proven_as_it_was(): void
    {
        $this->paymob();
        [$club, $owner] = $this->ready(apiKey: false);
        $this->asTenant($club, fn () => TenantIntegration::where('provider', 'paymob')->first()->forceFill(['verified_at' => now()])->save());

        $this->actingAs($owner)->put(route('club.integrations.update', 'paymob'), ['is_enabled' => true, 'mode' => 'test', 'credentials' => ['api_key' => self::API_KEY]])
            ->assertSessionHasNoErrors();
        $row = $this->asTenant($club, fn () => TenantIntegration::where('provider', 'paymob')->first());
        $this->assertSame(self::API_KEY, $row->credential('api_key'));
        $this->assertNotNull($row->verified_at, 'an API key makes links, it opens no page: the checkout stays checked');
        $this->assertStringNotContainsString(self::API_KEY, (string) $this->asTenant($club, fn () => \DB::table('tenant_integrations')->where('provider', 'paymob')->value('credentials')));

        $page = $this->actingAs($owner)->get(route('club.integrations.paymob'))->assertOk();
        $this->assertStringNotContainsString(self::API_KEY, $page->getContent());
        $page->assertInertia(fn ($p) => $p->where('integration.present.api_key', true));
        Http::assertNothingSent();
    }

    public function test_paymob_not_answering_the_making_keeps_the_attempt_for_review_and_opens_no_second_page_nor_takes_money_at_the_desk(): void
    {
        $this->paymob(timeout: true);
        [$club, $owner] = $this->ready();
        $invoice = $this->invoice($club, $owner);

        // The staff are told it is not known, with the reference to look up at Paymob.
        $this->openLink($owner, $invoice)->assertSessionHas('error', fn ($m) => str_contains($m, 'لم تؤكد Paymob') && str_contains($m, 'افحص لوحة Paymob'));
        $attempt = $this->attempt($club, $invoice);
        $this->assertSame([PaymentIntent::PENDING, QuickLinks::UNCONFIRMED, null, null], [$attempt->status, $attempt->failure_reason, $attempt->checkout_url, $attempt->provider_order_id]);
        $link = $this->link($club, $invoice);

        // No page opens — the link's or the invoice's own — and nothing is asked of Paymob for one, long after the
        // minute a page being opened holds others back.
        $this->travel(10)->minutes();
        auth()->logout();
        $this->post(route('site.pay.link.start', ['club' => $club->slug, 'token' => $link->token]));
        $this->post(route('site.pay.start', ['club' => $club->slug, 'token' => $invoice->public_token]));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/v1/intention/'));
        $this->assertSame(1, $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $invoice->id)->count()));

        // The desk takes nothing meanwhile.
        try {
            $this->asTenant($club, fn () => app(PaymentService::class)->record(Invoice::find($invoice->id), '5.000', 'cash'));
            $this->fail('the desk took money while the Paymob link was not known');
        } catch (BusinessRuleException $e) {
            $this->assertSame('payment.quicklink_unconfirmed', $e->rule);
        }

        // The cancel dialog lists it to check at Paymob; without that word the cancel is refused.
        $this->actingAs($owner)->get(route('club.payment-links.index'))->assertInertia(fn ($p) => $p->where('paymentLinks.rows.0.unanswered', [$attempt->reference]));
        $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id))->assertSessionHasErrors('error');
        $this->assertNull($this->link($club, $invoice)->cancelled_at);
        $this->assertSame(PaymentIntent::PENDING, $this->attempt($club, $invoice)->status);

        // Checked at Paymob: cancelled, the attempt closed as checked, never cancelled at Paymob by id (none known).
        $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id), ['paymob_checked' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($this->link($club, $invoice)->cancelled_at);
        $this->assertSame([PaymentIntent::FAILED, QuickLinks::UNCONFIRMED_CHECKED], [$this->attempt($club, $invoice)->status, $this->attempt($club, $invoice)->failure_reason]);
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payment-links/cancel'));
        $this->asTenant($club, fn () => app(PaymentService::class)->record(Invoice::find($invoice->id), '5.000', 'cash'));
    }

    public function test_money_paid_on_a_paymob_link_not_known_is_held_for_review_never_recorded_unasked(): void
    {
        $this->paymob(timeout: true);
        [$club, $owner] = $this->ready();
        $invoice = $this->invoice($club, $owner);
        $this->openLink($owner, $invoice);
        $attempt = $this->attempt($club, $invoice);

        // Paymob had made it after all, and the customer paid: our reference, an order we never knew.
        $this->paymobSays($attempt, 888001, ['order' => ['merchant_order_id' => $attempt->reference]]);
        $this->assertNotSame(Invoice::PAID, $this->asTenant($club, fn () => Invoice::find($invoice->id)->status));
        $this->assertSame('0.000', (string) $this->asTenant($club, fn () => Invoice::find($invoice->id)->paid_total));
        $this->assertSame(1, $this->asTenant($club, fn () => PaymentReview::where('invoice_id', $invoice->id)->open()->count()));
    }

    public function test_paymob_closing_the_link_is_kept_even_when_the_cancel_then_fails_and_cancelling_again_does_not_ask_paymob_twice(): void
    {
        $this->paymob();
        [$club, $owner] = $this->ready();
        $invoice = $this->invoice($club, $owner);
        $this->openLink($owner, $invoice)->assertSessionHasNoErrors();
        $link = $this->link($club, $invoice);

        // Paymob closes it; then the gym's own write fails (the database went away mid-cancel).
        $fail = true;
        PaymentLink::updating(function () use (&$fail) {
            if ($fail) {
                $fail = false;
                throw new \RuntimeException('database went away');
            }
        });
        $this->actingAs($owner);
        try {
            $this->asTenant($club, fn () => app(PaymentLinks::class)->cancel(PaymentLink::find($link->id)));
            $this->fail('the cancel was expected to fail after Paymob closed the link');
        } catch (\RuntimeException $e) {
            $this->assertSame('database went away', $e->getMessage());
        }

        // Nothing misleading: the attempt says Paymob closed it, so the link no longer hands out Paymob's dead address
        // (it opens the club's own page, the link still running here) and the desk is open; the link is not cancelled.
        $attempt = $this->attempt($club, $invoice);
        $this->assertSame([PaymentIntent::EXPIRED, QuickLinks::CANCELLED], [$attempt->status, $attempt->failure_reason]);
        $this->assertNull($this->link($club, $invoice)->cancelled_at);
        $fresh = $this->link($club, $invoice);
        $this->assertSame(route('site.pay.link', ['club' => $club->slug, 'token' => $fresh->token]), $this->asTenant($club, fn () => app(PaymentLinks::class)->url($fresh)));
        $this->asTenant($club, fn () => QuickLinks::refuseDeskWhileOpen(Invoice::find($invoice->id)));

        // Cancelling again goes on from there: cancelled here, Paymob asked once in all, nothing to check by hand.
        $this->post(route('club.payment-links.cancel', $link->id))->assertSessionHasNoErrors();
        $this->assertNotNull($this->link($club, $invoice)->cancelled_at);
        Http::assertSentCount(4); // the token and the link when made; the token and the cancel once
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/payment-links/cancel'))->count());
    }
}
