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
}
