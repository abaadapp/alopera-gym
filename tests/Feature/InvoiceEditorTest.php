<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceAttachment;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The invoice editor: a draft is a real draft (no number, nothing owed, nothing in the books, no stock, no money) that
 * is reopened, changed and issued; issuing numbers it in sequence, takes catalogue products off the shelf and records
 * the money taken now through the payments service; files stay with the invoice for the team.
 */
class InvoiceEditorTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array<string, mixed> */
    private function form(array $extra = []): array
    {
        return array_replace([
            'customer_mode' => 'new',
            'customer_name' => 'شركة الواحة',
            'customer_phone' => '96891234567',
            'customer_email' => 'oasis@example.om',
            'issue_date' => today()->toDateString(),
            'due_date' => today()->addDays(15)->toDateString(),
            'payment_terms_days' => 15,
            'notes' => 'شكرًا لتعاملكم',
            'internal_notes' => 'السعر المتفق عليه هاتفيًا',
            'lines' => [['description' => 'تأجير القاعة', 'quantity' => 2, 'unit_price' => 40, 'discount' => 5]],
        ], $extra);
    }

    private function product(Tenant $club, int $stock = 10, array $attributes = []): Product
    {
        return $this->asTenant($club, function () use ($stock, $attributes) {
            $product = Product::create($attributes + ['name' => 'منشفة النادي', 'price' => 3.5]);
            if ($stock) {
                app(StockService::class)->move($product, $stock, 'adjustment', null, 'opening', 2);
            }

            return $product->fresh();
        });
    }

    public function test_a_draft_has_no_number_debt_or_entry_and_is_reopened_changed_and_issued_with_its_files(): void
    {
        Storage::fake('local');
        $club = $this->club();
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 0, 'attachments' => [UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf')]]))->assertSessionHasNoErrors();
        $draft = $this->asTenant($club, fn () => Invoice::drafts()->sole());
        $this->asTenant($club, function () use ($draft) {
            $this->assertSame(0, Invoice::count(), 'a draft is not an invoice');
            $this->assertNull($draft->number);
            $this->assertNull($draft->public_token);
            $this->assertSame(['75.000', '96891234567', 15], [$draft->total, $draft->customer_phone, $draft->payment_terms_days]);
            $this->assertSame(0, JournalEntry::where('source_type', 'invoice')->count(), 'nothing in the books');
            $this->assertSame(1, InvoiceAttachment::where('invoice_id', $draft->id)->count());
        });

        // Out of the list, the dues and the totals; in its own tab, and back in the editor as it was left.
        $this->actingAs($owner)->get(route('club.invoices.index'))->assertInertia(fn ($page) => $page->has('invoices.data', 0)->where('totals.receivable', '0.000')->where('draftCount', 1));
        $this->actingAs($owner)->get(route('club.invoices.index', ['state' => 'draft']))->assertInertia(fn ($page) => $page->has('invoices.data', 1)->where('invoices.data.0.state', 'draft'));
        $this->actingAs($owner)->get(route('club.invoices.drafts.edit', $draft->id))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Finance/Invoices/Create')->where('draft.customer_mode', 'new')->where('draft.lines.0.description', 'تأجير القاعة')->has('draft.attachments', 1)->where('draft.internal_notes', 'السعر المتفق عليه هاتفيًا'));
        $this->actingAs($owner)->get(route('club.invoices.show', $draft->id))->assertNotFound();

        // Changed and saved again: still the one draft.
        $this->actingAs($owner)->post(route('club.invoices.drafts.update', $draft->id), $this->form(['issue' => 0, 'lines' => [['description' => 'تأجير القاعة', 'quantity' => 3, 'unit_price' => 40, 'discount' => 5]]]))->assertRedirect(route('club.invoices.drafts.edit', $draft->id));
        $this->asTenant($club, fn () => $this->assertSame(['115.000', 1], [Invoice::drafts()->sole()->total, Invoice::drafts()->count()]));

        // Issued: the first number, owed, posted; the draft is gone and its file is the invoice's.
        $this->actingAs($owner)->post(route('club.invoices.drafts.update', $draft->id), $this->form(['issue' => 1, 'lines' => [['description' => 'تأجير القاعة', 'quantity' => 3, 'unit_price' => 40, 'discount' => 5]]]))->assertSessionHasNoErrors();
        $invoice = $this->asTenant($club, fn () => Invoice::sole());
        $this->assertStringEndsWith('000001', $invoice->number);
        $this->assertSame([Invoice::ISSUED, '115.000', 'oasis@example.om', 'السعر المتفق عليه هاتفيًا'], [$invoice->status, $invoice->total, $invoice->customer_email, $invoice->internal_notes]);
        $this->asTenant($club, function () use ($invoice) {
            $this->assertSame(0, Invoice::drafts()->count());
            $this->assertSame($invoice->id, InvoiceAttachment::sole()->invoice_id);
            $this->assertSame(1, JournalEntry::where(['source_type' => 'invoice', 'source_id' => $invoice->id, 'source_event' => 'issue'])->count());
        });
        $this->actingAs($owner)->get(route('club.invoices.show', $invoice))->assertInertia(fn ($page) => $page->has('invoice.attachments', 1)->where('invoice.internal_notes', 'السعر المتفق عليه هاتفيًا'));

        // The file reads for whoever reads invoices in this club; the printed paper never carries the team's note.
        $file = $this->asTenant($club, fn () => InvoiceAttachment::sole());
        $this->actingAs($owner)->get(route('club.invoices.attachments.show', $file))->assertOk();
        $this->actingAs($this->staff($club, 'trainer'))->get(route('club.invoices.attachments.show', $file))->assertForbidden();
        $this->actingAs($this->owner($this->club('Other')))->get(route('club.invoices.attachments.show', $file))->assertNotFound();
        $this->assertStringNotContainsString('السعر المتفق عليه هاتفيًا', $this->actingAs($owner)->get(route('club.invoices.print', $invoice))->getContent());
    }

    public function test_a_draft_is_thrown_away_and_another_club_or_role_cannot_reach_it(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 0]))->assertSessionHasNoErrors();
        $draft = $this->asTenant($club, fn () => Invoice::drafts()->sole());

        $this->actingAs($this->staff($club, 'trainer'))->get(route('club.invoices.drafts.edit', $draft->id))->assertForbidden();
        $this->actingAs($this->owner($this->club('Other')))->get(route('club.invoices.drafts.edit', $draft->id))->assertNotFound();
        $this->actingAs($owner)->delete(route('club.invoices.drafts.discard', $draft->id))->assertRedirect(route('club.invoices.index', ['state' => 'draft']));
        $this->asTenant($club, fn () => $this->assertSame(0, Invoice::drafts()->count()));

        // A number is taken only by issuing: the next invoice is still the first.
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1]))->assertSessionHasNoErrors();
        $this->assertStringEndsWith('000001', $this->asTenant($club, fn () => Invoice::sole()->number));
    }

    public function test_money_taken_on_issue_is_recorded_through_payments(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'payment_method' => 'cash']))->assertSessionHasNoErrors();
        $paid = $this->asTenant($club, fn () => Invoice::latest('id')->first()->load('payments'));
        $this->assertSame(Invoice::PAID, $paid->status);
        $this->assertSame([['cash', Payment::COMPLETED, '75.000']], $paid->payments->map(fn ($p) => [$p->method, $p->status, $p->amount])->all());

        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'payment_method' => 'card', 'paid_amount' => 30, 'payment_reference' => 'TX-77']))->assertSessionHasNoErrors();
        $part = $this->asTenant($club, fn () => Invoice::latest('id')->first()->load('payments'));
        $this->assertSame([Invoice::PARTIALLY_PAID, '45.000', 'TX-77'], [$part->status, $part->balance(), $part->payments->first()->reference]);

        // A transfer waits for finance to confirm it; the invoice stays owed until then.
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'payment_method' => 'bank_transfer', 'payment_reference' => 'TR-1']))->assertSessionHasNoErrors();
        $transfer = $this->asTenant($club, fn () => Invoice::latest('id')->first()->load('payments'));
        $this->assertSame([Invoice::ISSUED, Payment::PENDING], [$transfer->status, $transfer->payments->first()->status]);

        // More than the invoice: refused, and nothing is issued.
        $before = $this->asTenant($club, fn () => Invoice::count());
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'payment_method' => 'cash', 'paid_amount' => 500]))->assertSessionHasErrors('paid_amount');
        $this->asTenant($club, fn () => $this->assertSame($before, Invoice::count()));
        // A draft never carries money, whatever the form says.
        $payments = $this->asTenant($club, fn () => Payment::count());
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 0, 'payment_method' => 'cash']))->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame([$payments, 0], [Payment::count(), Invoice::drafts()->sole()->payments()->count()]));
    }

    public function test_catalogue_products_leave_the_shelf_on_issue_and_come_back_when_it_is_voided(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $towel = $this->product($club);
        $line = fn (int $qty) => [['product_id' => $towel->id, 'description' => $towel->name, 'quantity' => $qty, 'unit_price' => 3.5, 'discount' => 0]];

        // A draft takes nothing.
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 0, 'lines' => $line(3)]))->assertSessionHasNoErrors();
        $this->assertSame('10.000', $towel->fresh()->stock_quantity);

        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'lines' => $line(3)]))->assertSessionHasNoErrors();
        $invoice = $this->asTenant($club, fn () => Invoice::sole());
        $this->assertSame('7.000', $towel->fresh()->stock_quantity);
        $this->asTenant($club, fn () => $this->assertSame(['invoice', -3.0], [StockMovement::where('reference_id', $invoice->id)->sole()->type, (float) StockMovement::where('reference_id', $invoice->id)->sole()->quantity]));

        // More than the shelf holds: refused, nothing issued, nothing taken.
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'lines' => $line(50)]))->assertSessionHasErrors('items');
        $this->assertSame('7.000', $towel->fresh()->stock_quantity);
        $this->asTenant($club, fn () => $this->assertSame(1, Invoice::count()));

        // Voided unpaid: the towels go back.
        $this->actingAs($owner)->post(route('club.invoices.void', $invoice), ['reason' => 'خطأ في الطلب'])->assertSessionHasNoErrors();
        $this->assertSame('10.000', $towel->fresh()->stock_quantity);
        $this->asTenant($club, fn () => $this->assertSame(1, StockMovement::where(['reference_id' => $invoice->id, 'type' => 'invoice_void'])->count()));
    }

    public function test_a_member_invoice_and_the_editor_screens(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);

        $this->actingAs($owner)->get(route('club.invoices.create'))->assertOk()->assertInertia(fn ($page) => $page->component('Finance/Invoices/Create')->has('products')->where('methods', ['credit', 'cash', 'card', 'bank_transfer']));
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'customer_mode' => 'member', 'member_id' => null]))->assertSessionHasErrors('member_id');
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'customer_mode' => 'member', 'member_id' => $member->id]))->assertSessionHasNoErrors();
        $invoice = $this->asTenant($club, fn () => Invoice::sole());
        $this->assertSame(['member', $member->id, $member->full_name], [$invoice->billable_type, $invoice->billable_id, $invoice->customer_name]);
        // Issued today or before, never later; due on or after it.
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'issue_date' => today()->addDays(3)->toDateString(), 'due_date' => today()->addDays(10)->toDateString()]))->assertSessionHasErrors('issue_date');
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'due_date' => today()->subDay()->toDateString()]))->assertSessionHasErrors('due_date');

        // The live paper follows the editor's own fields.
        $preview = $this->actingAs($owner)->postJson(route('club.invoices.preview'), $this->form())->assertOk()->json();
        $this->assertStringContainsString('شركة الواحة', $preview['html']);
        $this->assertSame('75.000', $preview['totals']['total']);
    }

    public function test_the_paper_and_the_issued_invoice_add_up_the_same_with_tax_discounts_and_baisa(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->asTenant($club, fn () => $club->forceFill(['settings' => array_merge($club->settings ?? [], ['vat_rate' => 5])])->save());
        // 1.5 × 0.333 = 0.4995 → 0.500 (the line rounded to the baisa, as the editor shows it); 3 × 12.250 less 1.125.
        $lines = [
            ['description' => 'مياه', 'quantity' => 1.5, 'unit_price' => 0.333, 'discount' => 0],
            ['description' => 'حصة تدريب', 'quantity' => 3, 'unit_price' => 12.25, 'discount' => 1.125],
        ];

        $preview = $this->actingAs($owner)->postJson(route('club.invoices.preview'), $this->form(['lines' => $lines]))->assertOk()->json('totals');
        // Gross 0.500 + 36.750 = 37.250; less 1.125 = 36.125; 5% = 1.80625 → 1.806; total 37.931.
        $this->assertSame(['37.250', '1.125', '1.806', '37.931'], [$preview['subtotal'], $preview['discount_total'], $preview['tax_total'], $preview['total']]);

        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'lines' => $lines, 'payment_method' => 'cash', 'paid_amount' => 20]))->assertSessionHasNoErrors();
        $invoice = $this->asTenant($club, fn () => Invoice::with('items')->sole());
        $this->assertSame(['0.500', '35.625'], $invoice->items->pluck('line_total')->all());
        $this->assertSame([$preview['subtotal'], $preview['tax_total'], $preview['total'], '20.000', '17.931'], [$invoice->subtotal, $invoice->tax_total, $invoice->total, $invoice->paid_total, $invoice->balance()]);
    }

    public function test_figures_beyond_the_baisa_and_a_discount_above_its_line_are_named_on_the_line(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1.2345, 'discount' => 0]]]))
            ->assertSessionHasErrors(['lines.0.unit_price' => 'السعر بثلاث منازل عشرية على الأكثر.']);
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 0, 'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 2, 'discount' => 0], ['description' => 'y', 'quantity' => 2, 'unit_price' => 1, 'discount' => 2.5]]]))
            ->assertSessionHasErrors(['lines.1.discount' => 'الخصم أكبر من قيمة البند.']);
        $this->asTenant($club, fn () => $this->assertSame([0, 0], [Invoice::count(), Invoice::drafts()->count()]));
    }

    public function test_the_same_form_sent_twice_issues_one_invoice_with_one_payment_and_saves_one_draft(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $form = $this->form(['issue' => 1, 'payment_method' => 'cash', 'paid_amount' => 10, 'client_key' => 'editor-key-1']);

        $first = $this->actingAs($owner)->post(route('club.invoices.store'), $form)->assertSessionHasNoErrors();
        $again = $this->actingAs($owner)->post(route('club.invoices.store'), $form)->assertSessionHasNoErrors();
        $invoice = $this->asTenant($club, fn () => Invoice::with('payments')->sole());
        $first->assertRedirect(route('club.invoices.show', $invoice));
        $again->assertRedirect(route('club.invoices.show', $invoice));
        $this->assertSame(['10.000', 1], [$invoice->paid_total, $invoice->payments->count()]);

        // A draft saved twice by the same form is one draft; another form (another key) is another document.
        $draft = $this->form(['issue' => 0, 'client_key' => 'editor-key-2']);
        $this->actingAs($owner)->post(route('club.invoices.store'), $draft);
        $this->actingAs($owner)->post(route('club.invoices.store'), $draft);
        $this->asTenant($club, fn () => $this->assertSame(1, Invoice::drafts()->count()));
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'client_key' => 'editor-key-3']))->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(2, Invoice::count()));

        // A refused submission keeps nothing: corrected, the same key goes through.
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'client_key' => 'editor-key-4', 'payment_method' => 'cash', 'paid_amount' => 999]))->assertSessionHasErrors('paid_amount');
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1, 'client_key' => 'editor-key-4', 'payment_method' => 'cash', 'paid_amount' => 5]))->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(3, Invoice::count()));
    }

    public function test_a_payment_form_sent_twice_records_one_payment(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form(['issue' => 1]))->assertSessionHasNoErrors();
        $invoice = $this->asTenant($club, fn () => Invoice::sole());

        $pay = ['amount' => 20, 'method' => 'cash', 'client_key' => 'pay-key-1'];
        $this->actingAs($owner)->post(route('club.payments.store', $invoice), $pay)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.payments.store', $invoice), $pay)->assertSessionHasNoErrors();
        $this->assertSame(['20.000', 1], [$invoice->fresh()->paid_total, $this->asTenant($club, fn () => Payment::where('invoice_id', $invoice->id)->count())]);

        // The next payment (a new form) is recorded.
        $this->actingAs($owner)->post(route('club.payments.store', $invoice), ['amount' => 20, 'method' => 'cash', 'client_key' => 'pay-key-2'])->assertSessionHasNoErrors();
        $this->assertSame('40.000', $invoice->fresh()->paid_total);
    }
}
