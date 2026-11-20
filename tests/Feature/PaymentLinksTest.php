<?php

namespace Tests\Feature;

use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentReview;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Payments\Services\PaymentLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * «إنشاء رابط دفع» on the Paymob page (and «روابط الدفع» in finance): an amount and a description issue an ordinary club
 * invoice on credit whose secure link opens one Paymob checkout; only a signed, matching callback records the money,
 * once. The link opens a page only until its time and never once cancelled. Paymob is faked; nothing leaves the test,
 * and no real key or payment is used.
 */
class PaymentLinksTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'test-hmac-secret';

    private function paymob(Tenant $club): void
    {
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test_secret', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));
    }

    /** @return array<string, mixed> */
    private function form(array $over = []): array
    {
        return array_replace(['amount' => '12.340', 'description' => 'حصص خاصة — نوفمبر', 'customer_name' => 'سارة', 'customer_phone' => '91234567', 'valid_days' => 7, 'client_key' => (string) Str::uuid()], $over);
    }

    private function holding(Tenant $club, array $permissions): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.Str::lower(Str::random(8)), 'name' => 'مخصص', 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception');
        $user->forceFill(['role_id' => $role->id])->save();

        return $user->fresh();
    }

    private function link(Tenant $club): PaymentLink
    {
        return $this->asTenant($club, fn () => PaymentLink::with('invoice')->latest('id')->firstOrFail());
    }

    private function signedCallback(PaymentIntent $intent, int $order, array $over = []): array
    {
        $obj = array_replace_recursive([
            'id' => 800001, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true, 'is_auth' => false,
            'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true,
            'integration_id' => 111, 'has_parent_transaction' => false, 'order' => ['id' => $order, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-19T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ], $over);

        return ['type' => 'TRANSACTION', 'obj' => $obj];
    }

    private function webhook(array $payload, ?string $hmac = null)
    {
        return $this->postJson(route('webhooks.paymob').'?hmac='.($hmac ?? PaymobSignature::compute($payload['obj'], self::HMAC)), $payload);
    }

    private function start(Tenant $club, PaymentLink $link)
    {
        return $this->post(route('site.pay.start', ['club' => $club->slug, 'token' => $link->invoice->public_token]));
    }

    public function test_a_link_is_one_invoice_for_exactly_the_amount_with_a_secure_link_and_no_money_recorded(): void
    {
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);

        // Pressed twice with the same form key: one link.
        $form = $this->form();
        $this->actingAs($owner)->post(route('club.payment-links.store'), $form)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.payment-links.store'), $form);
        $this->assertSame(1, $this->asTenant($club, fn () => PaymentLink::count()));

        $link = $this->link($club);
        $invoice = $link->invoice;
        $this->assertSame([Invoice::ISSUED, '12.340', '0.000', 'سارة', '96891234567'], [$invoice->status, $invoice->total, $invoice->paid_total, $invoice->customer_name, $invoice->customer_phone]);
        $this->assertSame(0, $this->asTenant($club, fn () => Payment::where('invoice_id', $invoice->id)->count()), 'making the link records no payment');
        $this->assertSame(PaymentLink::PENDING, $this->asTenant($club, fn () => $link->status()));
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $link->expires_at->getTimestamp(), 5);

        $url = route('site.pay.show', ['club' => $club->slug, 'token' => $invoice->public_token]);
        $page = $this->actingAs($owner)->get(route('club.payment-links.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Finance/PaymentLinks/Index')
                ->where('paymentLinks.on', true)
                ->where('paymentLinks.rows.0.status', 'pending')
                ->where('paymentLinks.rows.0.amount', '12.340')
                ->where('paymentLinks.rows.0.url', $url)
                ->where('paymentLinks.rows.0.whatsapp', fn ($wa) => str_starts_with($wa, 'https://wa.me/96891234567?text=') && str_contains(rawurldecode($wa), $url)));

        // The keys never reach the page.
        $this->assertStringNotContainsString('sk_test_secret', $page->getContent());
        $this->assertStringNotContainsString(self::HMAC, $page->getContent());

        // The customer's page shows the amount and the pay button's way.
        auth()->logout();
        $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->component('Site/Pay')->where('invoice.balance', '12.340')->where('onlinePayments', true)->where('closed', null));
    }

    public function test_with_tax_the_customer_is_asked_exactly_the_amount_typed(): void
    {
        $club = $this->club();
        $club->forceFill(['settings' => array_merge($club->settings ?? [], ['vat_rate' => 5])])->save();
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form(['amount' => '10.000']))->assertSessionHasNoErrors();
        $invoice = $this->link($club)->invoice;
        $this->assertSame(['10.000', '0.476'], [$invoice->total, $invoice->tax_total]);

        // Across many amounts the line price found gives the total typed (one baisa gaps are refused, never rounded).
        foreach ([100, 105, 999, 1234, 5000, 12345, 49999] as $minor) {
            $amount = Money::fromMinor($minor);
            $net = Money::toMinor(PaymentLinks::netFor($amount, 5));
            $this->assertContains($net + (int) round($net * 5 / 100) - $minor, [0, -1, 1], $amount);
        }
    }

    public function test_the_form_is_checked_and_nothing_is_issued_from_a_wrong_one(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        foreach ([['amount' => '0'], ['amount' => '-5'], ['amount' => '1.2345'], ['amount' => 'abc'], ['amount' => '5000.010'], ['amount' => ''], ['amount' => '12.345']] as $bad) {
            $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form($bad))->assertSessionHasErrors('amount');
        }
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form(['description' => '']))->assertSessionHasErrors('description');
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form(['customer_name' => '']))->assertSessionHasErrors('customer_name');
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form(['customer_phone' => 'abc']))->assertSessionHasErrors('customer_phone');
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form(['valid_days' => 2]))->assertSessionHasErrors('valid_days');

        $this->assertSame([0, 0], $this->asTenant($club, fn () => [PaymentLink::count(), Invoice::count()]));
    }

    public function test_only_staff_allowed_to_make_links_reach_them(): void
    {
        $club = $this->club();
        $this->paymob($club);
        $this->actingAs($this->owner($club))->post(route('club.payment-links.store'), $this->form())->assertSessionHasNoErrors();
        $link = $this->link($club);

        foreach ([$this->staff($club, 'trainer'), $this->holding($club, ['invoices.view', 'invoices.manage', 'integrations.manage'])] as $user) {
            $this->actingAs($user)->get(route('club.payment-links.index'))->assertForbidden();
            $this->actingAs($user)->post(route('club.payment-links.store'), $this->form())->assertForbidden();
            $this->actingAs($user)->post(route('club.payment-links.cancel', $link))->assertForbidden();
        }
        // Keys without the permission: the Paymob page, without the links.
        $this->actingAs($this->holding($club, ['integrations.manage']))->get(route('club.integrations.paymob'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('paymentLinks', null));

        $allowed = $this->holding($club, ['payments.links']);
        $this->actingAs($allowed)->get(route('club.payment-links.index'))->assertOk()->assertInertia(fn ($p) => $p->has('paymentLinks.rows', 1));
        $this->actingAs($allowed)->post(route('club.payment-links.store'), $this->form())->assertSessionHasNoErrors();
        $this->assertSame([2, $allowed->id], $this->asTenant($club, fn () => [PaymentLink::count(), PaymentLink::latest('id')->value('created_by')]));
        $this->actingAs($this->owner($club))->get(route('club.integrations.paymob'))->assertOk()->assertInertia(fn ($p) => $p->has('paymentLinks.rows', 2));
    }

    public function test_one_club_never_sees_or_cancels_another_clubs_links(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $this->actingAs($this->owner($a))->post(route('club.payment-links.store'), $this->form())->assertSessionHasNoErrors();
        $link = $this->link($a);

        $this->actingAs($this->owner($b))->get(route('club.payment-links.index'))->assertOk()->assertInertia(fn ($p) => $p->has('paymentLinks.rows', 0));
        $this->actingAs($this->owner($b))->post(route('club.payment-links.cancel', $link->id))->assertNotFound();
        $this->assertSame([Invoice::ISSUED, null], $this->asTenant($a, fn () => [$link->invoice->fresh()->status, $link->fresh()->cancelled_at]));
    }

    public function test_the_link_is_paid_once_and_only_by_a_signed_matching_callback(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_l1', 'intention_order_id' => 5501], 201)]);
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form())->assertSessionHasNoErrors();
        auth()->logout();
        $link = $this->link($club);

        $first = $this->start($club, $link)->assertRedirect()->headers->get('Location');
        $this->assertSame($first, $this->start($club, $link)->headers->get('Location'), 'pressed twice: the same checkout');
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['amount'] === Money::toMinor('12.340') && $r['currency'] === 'OMR');
        $intent = $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $link->invoice_id)->sole());
        $this->assertSame('12.340', $intent->amount);

        // A wrong signature, amount, currency, order or integration: nothing is paid.
        $this->webhook($this->signedCallback($intent, 5501, ['id' => 800002]), str_repeat('0', 128));
        $this->webhook($this->signedCallback($intent, 5501, ['id' => 800003, 'amount_cents' => Money::toMinor($intent->amount) - 1]));
        $this->webhook($this->signedCallback($intent, 5501, ['id' => 800004, 'currency' => 'EGP']));
        $this->webhook($this->signedCallback($intent, 9999, ['id' => 800005]));
        $this->webhook($this->signedCallback($intent, 5501, ['id' => 800006, 'integration_id' => 222]));
        $this->assertSame(Invoice::ISSUED, $link->invoice->fresh()->status);
        $this->assertSame(
            ['rejected_signature', 'amount_mismatch', 'amount_mismatch', 'order_mismatch', 'integration_mismatch'],
            PaymentWebhookEvent::orderBy('id')->pluck('outcome')->all(),
        );

        // The right one, twice: paid once.
        $this->webhook($this->signedCallback($intent, 5501))->assertOk();
        $this->webhook($this->signedCallback($intent, 5501))->assertOk();
        $this->assertSame([Invoice::PAID, '12.340'], [$link->invoice->fresh()->status, $link->invoice->fresh()->paid_total]);
        $this->assertSame(1, $this->asTenant($club, fn () => Payment::where('invoice_id', $link->invoice_id)->where('kind', 'payment')->count()));

        $this->actingAs($owner)->get(route('club.payment-links.index'))->assertInertia(fn ($p) => $p->where('paymentLinks.rows.0.status', 'paid')->where('paymentLinks.rows.0.url', null));
        // A paid link is not cancelled.
        $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id));
        $this->assertNull($link->fresh()->cancelled_at);
        $this->assertSame(Invoice::PAID, $link->invoice->fresh()->status);
    }

    public function test_an_expired_link_opens_no_page_and_a_page_opened_before_ends_with_the_link(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_l2', 'intention_order_id' => 5502], 201)]);
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form(['valid_days' => 1]))->assertSessionHasNoErrors();
        auth()->logout();
        $link = $this->link($club);

        // Half an hour before its end: the page lasts until the link's end, not the usual hour.
        $this->travelTo($link->expires_at->copy()->subMinutes(30));
        $this->start($club, $link)->assertRedirect();
        $intent = $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $link->invoice_id)->sole());
        $this->assertSame($link->expires_at->getTimestamp(), $intent->expires_at->getTimestamp());

        // After its end (and the page's): no new page, and it reads «expired».
        $this->travelTo($link->expires_at->copy()->addMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES + 5));
        $this->start($club, $link);
        Http::assertSentCount(1);
        $url = route('site.pay.show', ['club' => $club->slug, 'token' => $link->invoice->public_token]);
        $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->where('closed', fn ($m) => str_contains($m, 'انتهت صلاحية')));
        $this->actingAs($owner)->get(route('club.payment-links.index'))->assertInertia(fn ($p) => $p->where('paymentLinks.rows.0.status', 'expired'));
    }

    public function test_a_link_is_cancelled_with_its_invoice_but_not_while_its_page_may_still_take_money(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_l3', 'intention_order_id' => 5503], 201)]);
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form())->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form(['description' => 'ثانٍ']))->assertSessionHasNoErrors();
        [$opened, $quiet] = $this->asTenant($club, fn () => PaymentLink::with('invoice')->orderBy('id')->get()->all());

        // The customer opened the first one's page: it is not cancelled under it.
        auth()->logout();
        $this->start($club, $opened)->assertRedirect();
        $this->actingAs($owner)->post(route('club.payment-links.cancel', $opened->id))->assertSessionHasErrors('error');
        $this->assertStringContainsString('فتح العميل صفحة الدفع', session('errors')->first('error'));
        $this->assertSame([Invoice::ISSUED, null], [$opened->invoice->fresh()->status, $opened->fresh()->cancelled_at]);

        // The other: cancelled, its invoice voided, and its link opens nothing.
        $this->actingAs($owner)->post(route('club.payment-links.cancel', $quiet->id))->assertSessionHasNoErrors();
        $this->assertSame(Invoice::VOID, $quiet->invoice->fresh()->status);
        $this->assertNotNull($quiet->fresh()->cancelled_at);
        auth()->logout();
        $this->start($club, $quiet);
        Http::assertSentCount(1);
        $this->actingAs($owner)->get(route('club.payment-links.index'))->assertInertia(fn ($p) => $p->where('paymentLinks.rows.0.status', 'cancelled')->where('paymentLinks.rows.0.whatsapp', null));
    }

    public function test_omr_goes_to_paymob_in_baisa_and_a_single_baisa_is_refused_for_visa(): void
    {
        Http::fake(['*/v1/intention/' => Http::sequence()
            ->push(['client_secret' => 'cs_a', 'intention_order_id' => 6601], 201)
            ->push(['client_secret' => 'cs_b', 'intention_order_id' => 6602], 201)]);
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);

        // Paymob Oman takes OMR in baisa (×1000), as its own plugin sends it: 25.000 → 25000, 0.100 → 100.
        foreach (['25.000' => 25000, '0.100' => 100] as $amount => $baisa) {
            $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form(['amount' => $amount]))->assertSessionHasNoErrors();
            $link = $this->link($club);
            $this->assertSame($amount, $link->invoice->total, 'the invoice is what was typed');
            auth()->logout();
            $this->start($club, $link)->assertRedirect();
            Http::assertSent(fn ($r) => $r['amount'] === $baisa && $r['currency'] === 'OMR' && $r['items'][0]['amount'] === $baisa);
            $intent = $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $link->invoice_id)->sole());
            $this->assertSame($amount, $intent->amount, 'the payment page asks the invoice amount');
        }

        // 12.345: Visa would be charged 12.340 and the callback would not match; refused before anything is issued.
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form(['amount' => '12.345']))->assertSessionHasErrors('amount');
        $this->assertStringContainsString('مؤقتًا', session('errors')->first('amount'));
        $this->assertStringContainsString('لا يُقرَّب', session('errors')->first('amount'));
        $this->assertSame(2, $this->asTenant($club, fn () => PaymentLink::count()));
    }

    public function test_a_checkout_being_opened_right_now_also_stops_a_cancel(): void
    {
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form())->assertSessionHasNoErrors();
        $link = $this->link($club);
        // Another request claimed the checkout and is still waiting for Paymob (no page address yet).
        $this->asTenant($club, fn () => PaymentIntent::create([
            'invoice_id' => $link->invoice_id, 'provider' => 'paymob', 'reference' => 'PT-CLAIM'.Str::upper(Str::random(8)), 'amount' => $link->invoice->total,
            'currency' => 'OMR', 'status' => PaymentIntent::PENDING, 'channel' => 'whatsapp', 'expires_at' => now()->addHour(),
        ]));

        $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id))->assertSessionHasErrors('error');
        $this->assertSame([Invoice::ISSUED, null], [$link->invoice->fresh()->status, $link->fresh()->cancelled_at]);
    }

    public function test_cancelling_a_link_voids_an_invoice_so_it_takes_the_invoice_void_permission_too(): void
    {
        $club = $this->club();
        $this->paymob($club);
        $linksOnly = $this->holding($club, ['payments.links']);
        $this->actingAs($linksOnly)->post(route('club.payment-links.store'), $this->form())->assertSessionHasNoErrors();
        $link = $this->link($club);

        // Making links does not make its holder able to void invoices.
        $this->actingAs($linksOnly)->get(route('club.payment-links.index'))->assertInertia(fn ($p) => $p->where('paymentLinks.can_cancel', false));
        $this->actingAs($linksOnly)->post(route('club.payment-links.cancel', $link->id))->assertForbidden();
        $this->assertSame([Invoice::ISSUED, null], [$link->invoice->fresh()->status, $link->fresh()->cancelled_at]);

        $both = $this->holding($club, ['payments.links', 'invoices.void']);
        $this->actingAs($both)->get(route('club.payment-links.index'))->assertInertia(fn ($p) => $p->where('paymentLinks.can_cancel', true));
        $this->actingAs($both)->post(route('club.payment-links.cancel', $link->id))->assertSessionHasNoErrors();
        $this->assertSame(Invoice::VOID, $link->invoice->fresh()->status);
    }

    public function test_a_link_with_money_held_for_review_is_not_cancelled(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_h1', 'intention_order_id' => 7701], 201)]);
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form())->assertSessionHasNoErrors();
        auth()->logout();
        $link = $this->link($club);
        $this->start($club, $link)->assertRedirect();
        $intent = $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $link->invoice_id)->sole());

        // Paid at Paymob, but its callback came after the page's end and after the late window: recorded, held for review.
        $this->travelTo($intent->expires_at->copy()->addMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES + 5));
        $this->webhook($this->signedCallback($intent, 7701))->assertOk();
        $this->assertSame('held_for_review', PaymentWebhookEvent::latest('id')->value('outcome'));
        $this->assertSame(Invoice::ISSUED, $link->invoice->fresh()->status, 'held money pays nothing yet');

        $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id))->assertSessionHasErrors('error');
        $this->assertSame([Invoice::ISSUED, null], [$link->invoice->fresh()->status, $link->fresh()->cancelled_at]);
    }

    public function test_a_links_invoice_is_not_voided_anywhere_while_its_money_is_unsettled_and_is_once_settled(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_s1', 'intention_order_id' => 7801], 201)]);
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form())->assertSessionHasNoErrors();
        auth()->logout();
        $link = $this->link($club);
        $this->start($club, $link)->assertRedirect();
        $intent = $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $link->invoice_id)->sole());
        $voidOnInvoiceScreen = fn () => $this->actingAs($owner)->post(route('club.invoices.void', $link->invoice_id), ['reason' => 'خطأ']);
        $cancelLink = fn () => $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id));

        // 1. A page open from it may still take money: the invoice screen refuses as the link does.
        $voidOnInvoiceScreen()->assertSessionHasErrors('error');
        $this->assertStringContainsString('فتح العميل صفحة الدفع', session('errors')->first('error'));

        // 2. Its money came late and is held for review: refused from both, until settled.
        $this->travelTo($intent->expires_at->copy()->addMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES + 5));
        $this->webhook($this->signedCallback($intent, 7801))->assertOk();
        $this->assertSame('held_for_review', PaymentWebhookEvent::latest('id')->value('outcome'));
        $voidOnInvoiceScreen()->assertSessionHasErrors('error');
        $this->assertStringContainsString('قيد التنفيذ أو المراجعة', session('errors')->first('error'));
        $cancelLink()->assertSessionHasErrors('error');

        // 3. Its refund asked for, not yet confirmed: still in progress.
        $held = $this->asTenant($club, fn () => Payment::where('invoice_id', $link->invoice_id)->sole());
        $refund = $this->asTenant($club, fn () => app(PaymentService::class)->refundCredit($held, $held->amount, 'paymob', null, null, 'رابط ملغى'));
        $this->assertSame(Payment::PENDING, $refund->status);
        $cancelLink()->assertSessionHasErrors('error');
        $this->assertSame([Invoice::ISSUED, null], [$link->invoice->fresh()->status, $link->fresh()->cancelled_at]);

        // 4. The refund confirmed: settled, the link is cancelled with its invoice, both in the audit log.
        $this->asTenant($club, fn () => app(PaymentService::class)->confirm($refund->fresh(), 'TRX-7801'));
        $cancelLink()->assertSessionHasNoErrors();
        $this->assertSame(Invoice::VOID, $link->invoice->fresh()->status);
        $this->assertNotNull($link->fresh()->cancelled_at);
        $this->assertEqualsCanonicalizing(['invoice.voided', 'payment_link.cancelled'], \DB::table('audit_logs')->where('tenant_id', $club->id)->whereIn('event', ['invoice.voided', 'payment_link.cancelled'])->pluck('event')->all());
    }

    public function test_a_staff_member_tied_to_a_branch_sees_and_cancels_only_that_branchs_links(): void
    {
        $club = $this->club();
        [$khoud, $salalah] = $this->asTenant($club, fn () => [Branch::create(['name' => 'فرع الخوض', 'is_active' => true]), Branch::create(['name' => 'فرع صلالة', 'is_active' => true])]);
        $owner = $this->owner($club);
        foreach (['الخوض' => $khoud, 'صلالة' => $salalah] as $name => $branch) {
            $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form(['description' => 'رابط '.$name]))->assertSessionHasNoErrors();
            $this->asTenant($club, fn () => $this->link($club)->invoice->forceFill(['branch_id' => $branch->id])->save());
        }
        [$ours, $theirs] = $this->asTenant($club, fn () => PaymentLink::orderBy('id')->get()->all());

        $desk = $this->holding($club, ['payments.links', 'invoices.void']);
        $desk->forceFill(['branch_id' => $khoud->id])->save();
        $this->actingAs($desk)->get(route('club.payment-links.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->has('paymentLinks.rows', 1)->where('paymentLinks.rows.0.description', 'رابط الخوض'));
        $this->actingAs($desk)->post(route('club.payment-links.cancel', $theirs->id))->assertNotFound();
        $this->assertNull($theirs->fresh()->cancelled_at);
        $this->actingAs($desk)->post(route('club.payment-links.cancel', $ours->id))->assertSessionHasNoErrors();
        $this->assertNotNull($ours->fresh()->cancelled_at);
    }

    public function test_each_validity_ends_when_chosen_and_its_invoice_falls_due_that_day(): void
    {
        $club = $this->club();
        $club->forceFill(['settings' => array_merge($club->settings ?? [], ['vat_rate' => 5])])->save();
        $owner = $this->owner($club);
        $this->travelTo(now()->setTime(10, 0));
        foreach ([1, 3, 7, 30] as $days) {
            $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form(['valid_days' => $days, 'amount' => '10.500']))->assertSessionHasNoErrors();
            $link = $this->link($club);
            $this->assertSame(now()->addDays($days)->format('Y-m-d H:i'), $link->expires_at->format('Y-m-d H:i'), "$days days");
            // Owner's decision (2026-11-19): a link's invoice is issued today and falls due the day the link ends.
            $this->assertSame([today()->toDateString(), now()->addDays($days)->toDateString(), $days], [$link->invoice->issue_date->toDateString(), $link->invoice->due_date->toDateString(), $link->invoice->payment_terms_days], "$days days");
        }

        // What issuing a link books today (the invoice engine's own posting, unchanged): the customer owes the total,
        // revenue and output tax for it, and no payment.
        $invoice = $link->invoice;
        $this->assertSame([Invoice::ISSUED, '10.500', '0.500'], [$invoice->status, $invoice->total, $invoice->tax_total]);
        $lines = $this->asTenant($club, fn () => JournalEntry::where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->with('lines.account')->get()->flatMap->lines);
        $this->assertSame('10.500', Money::normalize($lines->sum(fn ($l) => (float) $l->debit)), 'the customer owes the total');
        $this->assertSame('10.500', Money::normalize($lines->sum(fn ($l) => (float) $l->credit)), 'revenue and output tax');
        $this->assertSame(0, $this->asTenant($club, fn () => Payment::where('invoice_id', $invoice->id)->count()));

        // After its end the invoice is still owed (nothing cancels it on its own).
        $this->travelTo($link->expires_at->copy()->addDay());
        $this->assertSame([Invoice::ISSUED, '10.500'], [$invoice->fresh()->status, $invoice->fresh()->balance()]);
        $this->assertSame(PaymentLink::EXPIRED, $this->asTenant($club, fn () => $link->fresh()->status()));
    }

    public function test_4_720_is_paid_end_to_end_in_baisa_and_4_725_is_refused(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_v', 'intention_order_id' => 8801], 201)]);
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form(['amount' => '4.725']))->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form(['amount' => '4.720']))->assertSessionHasNoErrors();
        $link = $this->link($club);
        auth()->logout();
        $this->start($club, $link)->assertRedirect();
        Http::assertSent(fn ($r) => $r['amount'] === 4720 && $r['currency'] === 'OMR');
        $intent = $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $link->invoice_id)->sole());
        $this->assertSame(['4.720', '4.720'], [$link->invoice->total, $intent->amount]);

        // The signed callback in baisa (4720): paid; one baisa less (as a rounding would make it): not recorded.
        $this->webhook($this->signedCallback($intent, 8801, ['id' => 880001, 'amount_cents' => 4710]));
        $this->assertSame('amount_mismatch', PaymentWebhookEvent::latest('id')->value('outcome'));
        $this->webhook($this->signedCallback($intent, 8801, ['amount_cents' => 4720]))->assertOk();
        $this->assertSame([Invoice::PAID, '4.720'], [$link->invoice->fresh()->status, $link->invoice->fresh()->paid_total]);
    }

    public function test_what_a_link_still_owes_in_a_single_baisa_opens_no_page_and_is_not_rounded(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_b', 'intention_order_id' => 8901], 201)]);
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form(['amount' => '4.730']))->assertSessionHasNoErrors();
        $link = $this->link($club);
        // A part paid at the desk leaves 4.725 owed.
        $this->asTenant($club, fn () => app(PaymentService::class)->record($link->invoice, '0.005', 'cash'));
        $this->assertSame('4.725', $link->invoice->fresh()->balance());
        auth()->logout();

        $url = route('site.pay.show', ['club' => $club->slug, 'token' => $link->invoice->public_token]);
        $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->component('Site/Pay')->where('closed', fn ($m) => str_contains((string) $m, '4.725') && str_contains((string) $m, 'مؤقتًا')));
        $this->start($club, $link)->assertSessionHasErrors('error');
        $this->assertStringContainsString('بيسة مفردة', session('errors')->first('error'));
        $this->assertSame(0, $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $link->invoice_id)->count()));
        Http::assertNothingSent();
        $this->assertSame([Invoice::PARTIALLY_PAID, '4.725'], [$link->invoice->fresh()->status, $link->invoice->fresh()->balance()], 'nothing rounded or written off');
    }

    public function test_an_open_paymob_review_prevents_link_cancellation_even_after_checkout_expiry(): void
    {
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.payment-links.store'), $this->form())->assertSessionHasNoErrors();
        $link = $this->link($club);

        $this->asTenant($club, function () use ($link) {
            $intent = PaymentIntent::create([
                'invoice_id' => $link->invoice_id,
                'provider' => 'paymob',
                'reference' => 'PT-REVIEW-'.Str::upper(Str::random(8)),
                'amount' => $link->invoice->total,
                'currency' => 'OMR',
                'status' => PaymentIntent::EXPIRED,
                'channel' => 'whatsapp',
                'expires_at' => now()->subDays(2),
            ]);
            PaymentReview::create([
                'payment_intent_id' => $intent->id,
                'invoice_id' => $link->invoice_id,
                'gateway' => 'paymob',
                'transaction_id' => 'review-'.Str::lower(Str::random(12)),
                'reason' => 'amount',
                'expected_minor' => Money::toMinor($link->invoice->total),
                'received_minor' => Money::toMinor($link->invoice->total) - 10,
                'expected_currency' => 'OMR',
                'received_currency' => 'OMR',
                'status' => PaymentReview::OPEN,
            ]);
        });

        $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id))->assertSessionHasErrors('error');
        $this->assertSame([Invoice::ISSUED, null], [$link->invoice->fresh()->status, $link->fresh()->cancelled_at]);
    }
}
