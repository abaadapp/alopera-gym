<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Payments\Http\Controllers\PaymentLinkController;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Paymob\PaymobClient;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A payment link that cannot be made is never left out without a word: the invoice page and the editor say why (the
 * invoice, the staff member's permission, Paymob not ready), and the Paymob page keeps its keys and state but no longer
 * makes links — that is «المالية ← روابط الدفع». Paymob is faked; no key or payment is real.
 */
class PaymentLinkAvailabilityTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const KEYS = ['secret_key' => 'sk_test_secret', 'public_key' => 'pk_test', 'hmac_secret' => 'availability-hmac', 'integration_ids' => '111'];

    private function paymob(Tenant $club, array $over = []): TenantIntegration
    {
        return $this->asTenant($club, fn () => TenantIntegration::create(array_replace(['provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test', 'credentials' => self::KEYS], $over)));
    }

    private function holding(Tenant $club, array $permissions): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.Str::lower(Str::random(8)), 'name' => 'مخصص', 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception');
        $user->forceFill(['role_id' => $role->id])->save();

        return $user->fresh();
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

    public function test_paymobs_reason_is_given_exactly_when_it_takes_no_payments_and_names_what_is_missing_never_a_key(): void
    {
        $row = fn (array $over = []) => (new TenantIntegration)->forceFill(array_replace(['provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test'], $over))
            ->setAttribute('credentials', $over['credentials'] ?? self::KEYS);
        $cases = [
            'not linked' => [null, 'لم يُربط Paymob'],
            'stopped' => [$row(['is_enabled' => false]), 'Paymob موقوف'],
            'a key missing' => [$row(['credentials' => array_replace(self::KEYS, ['hmac_secret' => null])]), 'ينقص: HMAC secret'],
            'no integration id' => [$row(['credentials' => array_replace(self::KEYS, ['integration_ids' => ''])]), 'لا رقم ربط'],
            'ready' => [$row(), null],
        ];
        foreach ($cases as $case => [$integration, $words]) {
            $reason = PaymobClient::unavailableReason($integration);
            $this->assertSame($reason === null, PaymobClient::takesPayments($integration), $case);
            $words === null ? $this->assertNull($reason, $case) : $this->assertStringContainsString($words, (string) $reason, $case);
            $this->assertStringNotContainsString('availability-hmac', (string) $reason, $case);
            $this->assertStringNotContainsString('sk_test_secret', (string) $reason, $case);
        }

        // On the production server test keys take nothing, and it says so.
        $this->app['env'] = 'production';
        try {
            $this->assertFalse(PaymobClient::takesPayments($row()));
            $this->assertStringContainsString('وضع الاختبار', (string) PaymobClient::unavailableReason($row()));
            $live = $row(['mode' => 'live', 'credentials' => array_replace(self::KEYS, ['secret_key' => 'sk_live_x', 'public_key' => 'pk_live_x'])]);
            $this->assertNull(PaymobClient::unavailableReason($live));
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_an_invoice_page_says_why_no_link_can_be_made_instead_of_hiding_the_panel(): void
    {
        $club = $this->club();
        $integration = $this->paymob($club);
        $owner = $this->owner($club);
        $invoice = $this->invoice($club, $owner);

        // Ready: the button, no reason.
        $this->actingAs($owner)->get(route('club.invoices.show', $invoice->id))->assertInertia(fn ($p) => $p
            ->where('paymentLink.can_open', true)->where('paymentLink.reason', null));

        // Without the permission: the reason, not an empty page.
        $viewer = $this->holding($club, ['invoices.view']);
        $this->actingAs($viewer)->get(route('club.invoices.show', $invoice->id))->assertInertia(fn ($p) => $p
            ->where('paymentLink.can_manage', false)->where('paymentLink.reason', fn ($r) => str_contains($r, 'صلاحية «روابط الدفع»')));

        // Paymob stopped: why, in Paymob's terms.
        $this->asTenant($club, fn () => $integration->forceFill(['is_enabled' => false])->save());
        $this->actingAs($owner)->get(route('club.invoices.show', $invoice->id))->assertInertia(fn ($p) => $p
            ->where('paymentLink.on', false)->where('paymentLink.reason', fn ($r) => str_contains($r, 'الدفع الإلكتروني غير متاح') && str_contains($r, 'Paymob موقوف')));
        // …and the server refuses the link all the same.
        $this->actingAs($owner)->post(route('club.payment-links.open', $invoice->id), ['valid_days' => 7, 'client_key' => (string) Str::uuid()])->assertSessionHasErrors();
        $this->assertSame(0, $this->asTenant($club, fn () => PaymentLink::where('invoice_id', $invoice->id)->count()));

        // A running link is shown as itself, with no reason over it.
        $this->asTenant($club, fn () => $integration->forceFill(['is_enabled' => true])->save());
        $this->actingAs($owner)->post(route('club.payment-links.open', $invoice->id), ['valid_days' => 7, 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->actingAs($owner)->get(route('club.invoices.show', $invoice->id))->assertInertia(fn ($p) => $p
            ->where('paymentLink.live.status', 'pending')->where('paymentLink.can_open', false)->where('paymentLink.reason', null));
    }

    public function test_the_invoice_comes_first_in_the_reason_draft_void_and_paid_in_full(): void
    {
        $club = $this->club();
        $this->paymob($club);
        $online = app(OnlinePaymentService::class);
        $reason = fn (array $state) => $this->asTenant($club, fn () => PaymentLinkController::whyNoLink(
            (new Invoice)->forceFill($state + ['total' => '20.000', 'paid_total' => '0.000', 'credited_total' => '0.000']), true, false, $online));

        $this->assertStringContainsString('مسودة', $reason(['status' => Invoice::DRAFT]));
        $this->assertStringContainsString('ملغاة', $reason(['status' => Invoice::VOID]));
        $this->assertStringContainsString('مدفوعة بالكامل', $reason(['status' => Invoice::PAID, 'paid_total' => '20.000']));
        $this->assertStringContainsString('مدفوعة بالكامل', $reason(['status' => Invoice::PARTIALLY_PAID, 'paid_total' => '20.000']));
        // The invoice is said before the permission: nothing to ask for, whoever asks.
        $this->assertStringContainsString('مدفوعة بالكامل', $this->asTenant($club, fn () => PaymentLinkController::whyNoLink(
            (new Invoice)->forceFill(['status' => Invoice::PAID, 'total' => '20.000', 'paid_total' => '20.000', 'credited_total' => '0.000']), false, false, $online)));
    }

    public function test_the_editor_says_why_issue_with_a_payment_link_is_not_offered(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        // Paymob not linked: the button is not offered, and the editor says why.
        $this->actingAs($owner)->get(route('club.invoices.create'))->assertInertia(fn ($p) => $p
            ->where('may.link', false)->where('link_reason', fn ($r) => str_contains($r, 'لم يُربط Paymob')));

        $this->paymob($club);
        $this->actingAs($owner)->get(route('club.invoices.create'))->assertInertia(fn ($p) => $p->where('may.link', true)->where('link_reason', null));

        // Issuing without the links permission: why, and the server refuses a link asked for all the same.
        $clerk = $this->holding($club, ['invoices.view', 'invoices.manage']);
        $this->actingAs($clerk)->get(route('club.invoices.create'))->assertInertia(fn ($p) => $p
            ->where('may.link', false)->where('link_reason', fn ($r) => str_contains($r, 'صلاحية «روابط الدفع»')));
        $this->actingAs($clerk)->post(route('club.invoices.store'), [
            'customer_mode' => 'walk_in', 'issue' => 1, 'with_link' => 1, 'link_days' => 7, 'payment_method' => 'credit',
            'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(7)->toDateString(),
            'lines' => [['description' => 'رسوم', 'quantity' => 1, 'unit_price' => 5, 'discount' => 0]], 'client_key' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->assertSame(0, $this->asTenant($club, fn () => PaymentLink::count()));
    }

    public function test_the_paymob_page_keeps_its_keys_methods_check_and_webhook_but_makes_no_links(): void
    {
        $club = $this->club();
        $this->paymob($club);

        $this->actingAs($this->owner($club))->get(route('club.integrations.paymob'))->assertOk()->assertInertia(fn ($p) => $p
            ->component('Integrations/Paymob')
            ->has('integration')->has('methods', 3)->has('checks', 3)->has('endpoints.webhook')->has('intents')->has('events')
            ->missing('paymentLinks')
            ->where('links.on', true)->where('links.reason', null)->where('links.manage_route', 'club.payment-links.index'));

        // Keys only: the page, without the way to the links.
        $this->actingAs($this->holding($club, ['integrations.manage']))->get(route('club.integrations.paymob'))->assertOk()
            ->assertInertia(fn ($p) => $p->missing('paymentLinks')->where('links.manage_route', null));
    }
}
