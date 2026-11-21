<?php

namespace Tests\Feature;

use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Accounting\Services\LedgerProjector;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductAddon;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\MemberNotifier;
use App\Modules\Messaging\Services\TemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A mixed customer invoice in the books and to the member (owner's decisions, 2026-11-21): its revenue goes line by line
 * to memberships, personal training, store products and other services — VAT on its own line, each discount inside its
 * line — balanced; a void reverses it as posted, a credit note goes to returns with its VAT; invoices of one kind and
 * entries posted before are as they were. The member is sent the invoice message once, with consent, saying the
 * membership starts only once it is paid in full (when Meta approved that wording; the plain one until then).
 */
class InvoiceCatalogBooksAndMessageTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function vatClub(): Tenant
    {
        $club = $this->club();
        $club->forceFill(['settings' => array_merge($club->settings ?? [], ['vat_registered' => true, 'vat_rate' => 5])])->save();

        return $club->fresh();
    }

    private function balance(Tenant $club, string $key): string
    {
        return $this->asTenant($club, fn () => app(FinanceReports::class)->balanceOf($key));
    }

    /** @param  array<string, string>  $expected */
    private function assertBooks(Tenant $club, array $expected): void
    {
        foreach ($expected as $key => $amount) {
            $this->assertSame($amount, $this->balance($club, $key), $key);
        }
        $this->asTenant($club, fn () => $this->assertSame((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit'), 'debits equal credits'));
    }

    /** @return array{shake: Product, scoop: ProductAddon, plan: MembershipPlan, pt: PtPackage, program: Program} */
    private function catalogue(Tenant $club): array
    {
        $program = $this->program($club, ['price' => 3]);

        return $this->asTenant($club, function () use ($program) {
            $shake = Product::create(['name' => 'Protein shake', 'price' => 2]);
            app(StockService::class)->move($shake, 10, 'adjustment', null, 'opening', 0.8);

            return [
                'shake' => $shake->fresh(),
                'scoop' => ProductAddon::create(['name' => 'Extra scoop', 'price' => 0.5, 'max_quantity' => 2, 'applies_to' => 'all'])->fresh(),
                'plan' => MembershipPlan::create(['name' => 'Monthly', 'type' => 'combo', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 25, 'signup_fee' => 5]),
                'pt' => PtPackage::create(['name' => 'PT 4', 'sessions_count' => 4, 'session_minutes' => 60, 'price' => 40, 'validity_days' => 60]),
                'program' => $program,
            ];
        });
    }

    /**
     * The shake ×2 less 0.500 with a scoop each (4.500, store), the plan less 2 with its first signup fee (28, memberships),
     * the package (40, personal training), the program ×2 less 1 (5, other): 77.500 net, VAT 5% 3.875, 81.375.
     */
    private function mixed(Tenant $club, User $by, Member $member, array $c, array $over = []): Invoice
    {
        $this->actingAs($by)->post(route('club.invoices.store'), array_replace([
            'customer_mode' => 'member', 'member_id' => $member->id, 'issue' => 1, 'payment_method' => 'credit',
            'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(7)->toDateString(), 'client_key' => (string) Str::uuid(),
            'lines' => [
                ['product_id' => $c['shake']->id, 'description' => 'Protein shake', 'quantity' => 2, 'unit_price' => 2, 'discount' => 0.5, 'addons' => [['addon_id' => $c['scoop']->id, 'quantity' => 1]]],
                ['item_type' => 'plan', 'item_id' => $c['plan']->id, 'description' => 'Monthly', 'quantity' => 1, 'unit_price' => 25, 'discount' => 2],
                ['item_type' => 'pt', 'item_id' => $c['pt']->id, 'description' => 'PT 4', 'quantity' => 1, 'unit_price' => 40, 'discount' => 0],
                ['item_type' => 'program', 'item_id' => $c['program']->id, 'description' => 'Zumba', 'quantity' => 2, 'unit_price' => 3, 'discount' => 1],
            ],
        ], $over))->assertSessionHasNoErrors();

        return $this->asTenant($club, fn () => Invoice::whereNotNull('number')->latest('id')->firstOrFail());
    }

    private function whatsapp(Tenant $club, bool $membershipWording): void
    {
        Http::fake();
        $this->asTenant($club, function () use ($membershipWording) {
            TenantIntegration::create(['provider' => 'whatsapp', 'is_enabled' => true, 'credentials' => ['phone_number_id' => 'PNID-7', 'access_token' => 'EAAG-secret'], 'public_identifier' => 'PNID-7']);
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->when(! $membershipWording, fn ($q) => $q->where('key', '!=', 'invoice_issued_membership'))->update(['status' => 'approved']);
        });
    }

    /** @return list<string> the template of each invoice message the member was sent (not skipped) */
    private function sent(Tenant $club, Invoice $invoice): array
    {
        return $this->asTenant($club, fn () => Message::where('related_type', 'invoice')->where('related_id', $invoice->id)->where('status', '!=', Message::SKIPPED)
            ->whereIn('template_key', ['invoice_issued', 'invoice_issued_membership'])->pluck('template_key')->all());
    }

    public function test_a_mixed_invoice_books_each_lines_revenue_to_its_account_with_its_vat_and_a_void_reverses_it(): void
    {
        $club = $this->vatClub();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);
        $invoice = $this->mixed($club, $owner, $this->member($club), $c);

        $this->assertSame(['other', '3.875', '81.375'], [$invoice->type, $invoice->tax_total, $invoice->total]);
        $this->assertBooks($club, [
            'receivable' => '81.375', 'revenue_pos' => '4.500', 'revenue_membership' => '28.000', 'revenue_pt' => '40.000', 'revenue_other' => '5.000', 'vat_payable' => '3.875',
        ]);
        // One entry for the invoice, its credits by line.
        $this->assertSame(1, $this->asTenant($club, fn () => JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)->count()));

        $this->asTenant($club, fn () => app(InvoiceService::class)->void($invoice->fresh(), 'خطأ في الإصدار'));
        $this->assertBooks($club, [
            'receivable' => '0.000', 'revenue_pos' => '0.000', 'revenue_membership' => '0.000', 'revenue_pt' => '0.000', 'revenue_other' => '0.000', 'vat_payable' => '0.000',
        ]);
    }

    public function test_a_credit_note_on_a_mixed_invoice_goes_to_returns_with_its_vat_and_leaves_the_revenue_as_booked(): void
    {
        $club = $this->vatClub();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);
        $invoice = $this->mixed($club, $owner, $this->member($club), $c);

        $note = $this->asTenant($club, fn () => app(CreditNoteService::class)->issue($invoice->fresh(), '21.000', 'خصم متفق عليه'));
        $tax = $note->tax_amount ?? '0.000';
        $this->assertBooks($club, [
            'receivable' => '60.375', 'revenue_pos' => '4.500', 'revenue_membership' => '28.000', 'revenue_pt' => '40.000', 'revenue_other' => '5.000',
        ]);
        $this->assertSame('21.000', $this->asTenant($club, fn () => Money::add(app(FinanceReports::class)->balanceOf('sales_returns'), $tax)));
        $this->assertSame(Money::sub('3.875', $tax), $this->balance($club, 'vat_payable'));
    }

    public function test_invoices_of_one_kind_and_editor_invoices_from_before_are_booked_as_they_were_and_posted_entries_stay(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);

        // An editor invoice made before the decision, with a product: other revenue, as then — also when posted later.
        $this->travelTo(now()->setDate(2026, 11, 20)->setTime(10, 0));
        $this->actingAs($owner)->post(route('club.invoices.store'), [
            'customer_mode' => 'walk_in', 'issue' => 1, 'payment_method' => 'credit', 'issue_date' => today()->toDateString(), 'due_date' => today()->toDateString(),
            'lines' => [['product_id' => $c['shake']->id, 'description' => 'Protein shake', 'quantity' => 1, 'unit_price' => 2, 'discount' => 0]], 'client_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $this->travelBack();
        $this->assertBooks($club, ['revenue_other' => '2.000', 'revenue_pos' => '0.000']);

        // A membership sold on its own screen, and a product-only editor invoice now: their kinds' accounts.
        $this->asTenant($club, fn () => app(MembershipService::class)->sell($this->member($club), $c['plan']));
        $this->actingAs($owner)->post(route('club.invoices.store'), [
            'customer_mode' => 'walk_in', 'issue' => 1, 'payment_method' => 'credit', 'issue_date' => today()->toDateString(), 'due_date' => today()->toDateString(),
            'lines' => [['product_id' => $c['shake']->id, 'description' => 'Protein shake', 'quantity' => 1, 'unit_price' => 2, 'discount' => 0]], 'client_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $this->assertBooks($club, ['revenue_membership' => '30.000', 'revenue_pos' => '2.000', 'revenue_other' => '2.000']);

        // The books built again change nothing posted.
        $before = $this->asTenant($club, fn () => JournalLine::orderBy('id')->get(['account_id', 'debit', 'credit'])->toArray());
        $added = $this->asTenant($club, fn () => app(LedgerProjector::class)->syncClub()['entries_added']);
        $this->assertSame(0, $added);
        $this->assertSame($before, $this->asTenant($club, fn () => JournalLine::orderBy('id')->get(['account_id', 'debit', 'credit'])->toArray()));
    }

    public function test_the_member_is_sent_the_invoice_once_saying_the_membership_starts_once_paid_when_that_wording_is_approved(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);
        $this->whatsapp($club, membershipWording: true);
        $member = $this->member($club, ['phone' => '96891234567']);

        $invoice = $this->mixed($club, $owner, $member, $c);
        $this->assertSame(['invoice_issued_membership'], $this->sent($club, $invoice));
        $body = $this->asTenant($club, fn () => Message::where('template_key', 'invoice_issued_membership')->sole()->body);
        $this->assertStringContainsString('يتفعّل بعد سداد الفاتورة كاملة', (string) $body);

        // Once: announced again (a retried job, the same event), nothing more.
        $this->asTenant($club, fn () => app(MemberNotifier::class)->invoiceIssued($member, $invoice->fresh()));
        $this->assertSame(['invoice_issued_membership'], $this->sent($club, $invoice));

        // The customer's page says it too, until paid in full.
        $this->get(route('site.invoice', ['club' => $club->slug, 'token' => $invoice->public_token]))->assertOk()->assertInertia(fn ($p) => $p->where('starts_when_paid', true));
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice->fresh(), $invoice->total, 'cash'));
        $this->get(route('site.invoice', ['club' => $club->slug, 'token' => $invoice->public_token]))->assertOk()->assertInertia(fn ($p) => $p->where('starts_when_paid', false));
    }

    public function test_until_that_wording_is_approved_the_plain_message_once_and_none_without_consent_or_when_paid_on_issue(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);
        $this->whatsapp($club, membershipWording: false);

        $member = $this->member($club, ['phone' => '96891234567']);
        $invoice = $this->mixed($club, $owner, $member, $c);
        $this->assertSame(['invoice_issued'], $this->sent($club, $invoice));

        // No consent to service messages: nothing sent.
        $quiet = $this->member($club, ['phone' => '96891234568']);
        $this->asTenant($club, fn () => $quiet->forceFill(['whatsapp_opt_in' => false])->save());
        $this->assertSame([], $this->sent($club, $this->mixed($club, $owner, $quiet, $c)));

        // Paid in full when issued: nothing owed, no invoice message (its activation says the rest).
        $paid = $this->member($club, ['phone' => '96891234569']);
        $this->assertSame([], $this->sent($club, $this->mixed($club, $owner, $paid, $c, ['payment_method' => 'cash'])));
    }
}
