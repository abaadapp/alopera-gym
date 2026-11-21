<?php

namespace Tests\Feature;

use App\Core\Backup\TenantBackupService;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * R1, the release before payment links v2 (PR #218), made so that going back from v2 to it is safe:
 *  - cancelling a link ends the link only, whatever its invoice is for, from every path — never the invoice, its
 *    membership, its tax or its journal;
 *  - a link's own address (/pay/link/{token}) is served once v2's migration added its columns, 404 before: the invoice's
 *    current link opens its pay page, an earlier one never starts a payment, and money Paymob took on an earlier link's
 *    page is still matched to its invoice, once.
 * v2's schema is applied here as PR #218's migration (2026_11_21_000100_add_payment_link_history) makes it.
 */
class PaymentLinksBridgeTest extends TestCase
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

    /** What PR #218's migration adds (adds only; its history keeps its rows: no cascade from links or invoices). */
    private function v2Schema(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->unique();
            $table->timestamp('issued_at')->nullable();
            $table->decimal('amount', 12, 3)->nullable();
            $table->json('paymob_checked')->nullable();
        });
        Schema::create('payment_link_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->foreignId('payment_link_id')->constrained('payment_links');
            $table->string('token', 64)->nullable()->unique();
            $table->string('description', 250);
            $table->decimal('amount', 12, 3)->nullable();
            $table->decimal('balance_at_end', 12, 3)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replaced_at')->nullable();
            $table->foreignId('replaced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('paymob_checked')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'id']);
            $table->index('invoice_id');
        });
    }

    /** As v2 replaces a link: the row's link kept in the history, the row given a new address. */
    private function replaceAsV2(Tenant $club, PaymentLink $row, string $old, string $new): void
    {
        DB::table('payment_link_history')->insert([
            'tenant_id' => $club->id, 'invoice_id' => $row->invoice_id, 'payment_link_id' => $row->id, 'token' => $old,
            'description' => $row->description, 'amount' => $row->invoice->total, 'issued_at' => $row->created_at, 'expires_at' => $row->expires_at,
            'replaced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payment_links')->where('id', $row->id)->update(['token' => $new, 'issued_at' => now(), 'expires_at' => now()->addDays(7), 'cancelled_at' => null]);
    }

    private function linkUrl(Tenant $club, string $token): string
    {
        return route('site.pay.link', ['club' => $club->slug, 'token' => $token]);
    }

    private function paymobSays(PaymentIntent $intent, int $order, int $transaction): string
    {
        $obj = [
            'id' => $transaction, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true, 'is_auth' => false,
            'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true,
            'integration_id' => 111, 'has_parent_transaction' => false, 'order' => ['id' => $order, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-21T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ];

        return $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, self::HMAC), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk()->json('outcome');
    }

    public function test_cancelling_a_link_on_any_invoice_ends_the_link_only_even_when_asked_directly_of_an_ended_one(): void
    {
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $membership = $this->asTenant($club, fn () => app(MembershipService::class)->sell($this->member($club), $this->plan($club, ['price' => 25])));
        // A link on a subscription invoice, as v2 makes them; then one past its time (the log shows no cancel for it).
        [$live, $ended] = $this->asTenant($club, fn () => [
            PaymentLink::create(['invoice_id' => $membership->invoice_id, 'description' => 'اشتراك', 'created_by' => $owner->id, 'expires_at' => now()->addDays(7)]),
            PaymentLink::create(['invoice_id' => app(MembershipService::class)->sell($this->member($club), $this->plan($club, ['price' => 30]))->invoice_id,
                'description' => 'اشتراك آخر', 'created_by' => $owner->id, 'expires_at' => now()->subDay()]),
        ]);
        $books = fn () => [DB::table('gym_journal_entries')->count(), DB::table('gym_journal_lines')->count(), DB::table('invoices')->orderBy('id')->get(['id', 'status', 'total', 'tax_total', 'paid_total', 'credited_total'])->toJson()];
        $before = $books();

        foreach ([$live, $ended] as $link) {
            $this->actingAs($owner)->post(route('club.payment-links.cancel', $link->id))->assertSessionHasNoErrors();
            $this->assertNotNull($link->fresh()->cancelled_at);
        }
        $this->assertSame($before, $books(), 'no invoice voided, no entry written, no amount changed');
        $this->asTenant($club, fn () => $this->assertSame([Membership::PENDING, Membership::PENDING], Membership::orderBy('id')->pluck('status')->all()));
        $this->assertSame(0, DB::table('audit_logs')->where('tenant_id', $club->id)->where('event', 'invoice.voided')->count());
        $this->assertSame(['issued', 'issued'], DB::table('audit_logs')->where('tenant_id', $club->id)->where('event', 'payment_link.cancelled')->orderBy('id')->pluck('properties')->map(fn ($p) => json_decode($p, true)['invoice_kept'])->all());
        // Voiding stays the invoice screen's own act.
        $this->actingAs($owner)->post(route('club.invoices.void', $membership->invoice_id), ['reason' => 'خطأ'])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(Invoice::VOID, Invoice::find($membership->invoice_id)->status));
    }

    public function test_a_links_own_address_is_not_found_before_v2s_columns_exist(): void
    {
        $club = $this->club();
        $token = Str::random(48);
        $this->get($this->linkUrl($club, $token))->assertNotFound();
        $this->post(route('site.pay.link.start', ['club' => $club->slug, 'token' => $token]))->assertNotFound();
    }

    public function test_after_v2s_migration_the_current_link_opens_an_earlier_one_never_and_its_late_money_is_kept_once(): void
    {
        Http::fake(['*/v1/intention/' => Http::sequence()->push(['client_secret' => 'cs_b1', 'intention_order_id' => 9301], 201)->push(['client_secret' => 'cs_b2', 'intention_order_id' => 9302], 201)]);
        $this->v2Schema();
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.payment-links.store'), ['amount' => '12.340', 'description' => 'حصص خاصة', 'customer_name' => 'سارة',
            'customer_phone' => '91234567', 'valid_days' => 1, 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        auth()->logout();
        $row = $this->asTenant($club, fn () => PaymentLink::with('invoice')->latest('id')->firstOrFail());
        $invoice = $row->invoice;
        [$old, $new] = [Str::random(48), Str::random(48)];
        DB::table('payment_links')->where('id', $row->id)->update(['token' => $old]);

        // The customer reached Paymob from link A's own address; its time and late window pass; v2 replaces it by B.
        $this->post(route('site.pay.link.start', ['club' => $club->slug, 'token' => $old]))->assertRedirect();
        $pageA = $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $invoice->id)->sole());
        $this->travelTo($pageA->expires_at->copy()->addMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES + 5));
        $this->replaceAsV2($club, $row->fresh('invoice'), $old, $new);

        // B, the current link: its invoice's page. A: why it opens nothing, and it starts nothing.
        $this->get($this->linkUrl($club, $new))->assertOk()->assertInertia(fn ($p) => $p->component('Site/Pay')->where('closed', null)->where('token', $invoice->public_token));
        $this->get($this->linkUrl($club, $old))->assertOk()->assertInertia(fn ($p) => $p->where('closed', fn ($m) => str_contains($m, 'حلّ محله رابط أحدث')));
        $this->post(route('site.pay.link.start', ['club' => $club->slug, 'token' => $old]))->assertSessionHasErrors('error');
        $this->assertSame(1, $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $invoice->id)->count()), 'no page from the earlier link');
        // Another club's address, or none: not found.
        $other = $this->club('Other');
        $this->get($this->linkUrl($other, $new))->assertNotFound();
        $this->get($this->linkUrl($other, $old))->assertNotFound();
        $this->get($this->linkUrl($club, Str::random(48)))->assertNotFound();

        // Paying from B: one page, paid once.
        $this->post(route('site.pay.link.start', ['club' => $club->slug, 'token' => $new]))->assertRedirect();
        $pageB = $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $invoice->id)->latest('id')->firstOrFail());
        $this->assertNotSame($pageA->id, $pageB->id);
        $this->assertSame('processed', $this->paymobSays($pageB, 9302, 930201));
        $this->assertSame('duplicate', $this->paymobSays($pageB, 9302, 930201));
        $this->assertSame([Invoice::PAID, '12.340'], [$invoice->fresh()->status, $invoice->fresh()->paid_total]);

        // Then Paymob's word on A's page comes late, twice: matched to the invoice by the page's own reference (not by
        // the link's address, which changed), kept once as the customer's credit held for review — not paid twice.
        $this->assertSame('held_for_review', $this->paymobSays($pageA, 9301, 930101));
        $this->assertSame('duplicate', $this->paymobSays($pageA, 9301, 930101));
        $this->assertSame([Invoice::PAID, '12.340'], [$invoice->fresh()->status, $invoice->fresh()->paid_total]);
        $this->assertSame(['930201', '930101'], $this->asTenant($club, fn () => Payment::where('invoice_id', $invoice->id)->where('gateway', 'paymob')->orderBy('id')->pluck('gateway_transaction_id')->all()));
        $this->assertSame(['processed', 'duplicate', 'held_for_review', 'duplicate'], PaymentWebhookEvent::orderBy('id')->pluck('outcome')->all());
        Http::assertSentCount(2);
    }

    public function test_after_v2s_migration_a_cancelled_current_link_opens_nothing_and_the_log_sends_the_invoices_address(): void
    {
        Http::fake();
        $this->v2Schema();
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.payment-links.store'), ['amount' => '5.000', 'description' => 'حصة', 'valid_days' => 7, 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $row = $this->asTenant($club, fn () => PaymentLink::with('invoice')->latest('id')->firstOrFail());
        $token = Str::random(48);
        DB::table('payment_links')->where('id', $row->id)->update(['token' => $token]);

        // The log copies and shares the invoice's own address — never a link's — which opens only the current link.
        $this->actingAs($owner)->get(route('club.payment-links.index'))->assertInertia(fn ($p) => $p->where('paymentLinks.rows.0.url', route('site.pay.show', ['club' => $club->slug, 'token' => $row->invoice->public_token])));

        $this->actingAs($owner)->post(route('club.payment-links.cancel', $row->id))->assertSessionHasNoErrors();
        auth()->logout();
        $this->get($this->linkUrl($club, $token))->assertInertia(fn ($p) => $p->where('closed', fn ($m) => str_contains($m, 'أُلغي')));
        $this->post(route('site.pay.link.start', ['club' => $club->slug, 'token' => $token]))->assertSessionHasErrors('error');
        $this->post(route('site.pay.start', ['club' => $club->slug, 'token' => $row->invoice->public_token]))->assertSessionHasErrors('error');
        Http::assertNothingSent();
        $this->assertSame(Invoice::ISSUED, $row->invoice->fresh()->status);
    }

    public function test_after_v2s_migration_a_restore_here_is_refused_whole_rather_than_losing_the_earlier_links(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->v2Schema();
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.payment-links.store'), ['amount' => '5.000', 'description' => 'حصة', 'valid_days' => 7, 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $row = $this->asTenant($club, fn () => PaymentLink::with('invoice')->latest('id')->firstOrFail());
        $this->replaceAsV2($club, $row, Str::random(48), Str::random(48));
        $state = fn () => [DB::table('payment_links')->orderBy('id')->get()->toJson(), DB::table('payment_link_history')->orderBy('id')->get()->toJson(), DB::table('invoices')->orderBy('id')->get()->toJson()];
        $before = $state();

        // This release's backup does not know v2's history table; its restore deletes the club's links first, which the
        // history refuses: the whole restore is rolled back, nothing lost (roll forward to v2 to restore).
        $service = app(TenantBackupService::class);
        $file = $service->create($club);
        try {
            $service->restore($club, Storage::disk('local')->path($service->path($club, $file)));
            $this->fail('the restore went through and took the earlier links');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('لم يتغير شيء', $e->getMessage());
        }
        $this->assertSame($before, $state());
    }
}
