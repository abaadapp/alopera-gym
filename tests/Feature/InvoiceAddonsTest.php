<?php

namespace Tests\Feature;

use App\Core\Papers\Papers;
use App\Models\Tenant;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductAddon;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Add-ons on a manual invoice, as at the point of sale: chosen with a catalogue product, each billed on a line of its own
 * under the product's, priced and checked on the server (offered at the point of sale, going with that product, within
 * its limit), kept with a draft, taking its stock once on issue and putting it back on a void.
 */
class InvoiceAddonsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function product(Tenant $club, array $attributes = [], int $stock = 10): Product
    {
        return $this->asTenant($club, function () use ($attributes, $stock) {
            $product = Product::create($attributes + ['name' => 'Protein shake', 'price' => 2]);
            if ($stock) {
                app(StockService::class)->move($product, $stock, 'adjustment', null, 'opening', 0.8);
            }

            return $product->fresh();
        });
    }

    private function addon(Tenant $club, array $attributes, array $products = []): ProductAddon
    {
        return $this->asTenant($club, function () use ($attributes, $products) {
            $addon = ProductAddon::create($attributes + ['price' => 0.5, 'applies_to' => $products ? 'products' : 'all']);
            $addon->products()->sync(collect($products)->pluck('id'));

            return $addon->fresh();
        });
    }

    /** A shake with an extra scoop (up to 2 per shake) and a banana that takes one banana off the shelf, VAT at 5%. */
    private function shop(Tenant $club, int $bananas = 10): array
    {
        $this->asTenant($club, fn () => $club->forceFill(['settings' => array_merge($club->settings ?? [], ['vat_rate' => 5])])->save());
        $shake = $this->product($club);
        $banana = $this->product($club, ['name' => 'Banana', 'price' => 0.2], $bananas);
        $scoop = $this->addon($club, ['name' => 'Extra scoop', 'price' => 0.5, 'max_quantity' => 2], [$shake]);
        $fruit = $this->addon($club, ['name' => 'Add banana', 'price' => 0.3, 'stock_product_id' => $banana->id, 'stock_usage' => 1], [$shake]);

        return [$shake, $banana, $scoop, $fruit];
    }

    /** @return array<string, mixed> the editor's form: two shakes (0.500 off) with the add-ons given */
    private function form(Product $shake, array $addons, array $extra = []): array
    {
        return array_replace([
            'customer_mode' => 'walk_in',
            'customer_name' => 'شركة الواحة',
            'issue_date' => today()->toDateString(),
            'due_date' => today()->toDateString(),
            'lines' => [['product_id' => $shake->id, 'description' => 'Protein shake', 'quantity' => 2, 'unit_price' => 2, 'discount' => 0.5, 'addons' => $addons]],
        ], $extra);
    }

    private function stock(Tenant $club, Product $product): string
    {
        return $this->asTenant($club, fn () => $product->fresh()->stock_quantity);
    }

    public function test_a_product_and_two_addons_are_billed_line_by_line_priced_by_the_server_and_take_their_stock(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$shake, $banana, $scoop, $fruit] = $this->shop($club);
        $online = $this->addon($club, ['name' => 'Vanilla', 'show_in_pos' => false]);

        // The editor offers what goes with each product at the point of sale, and what the shelf allows.
        $this->actingAs($owner)->get(route('club.invoices.create'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('products', fn ($products) => collect($products)->firstWhere('id', $shake->id)['addons'] === [$fruit->id, $scoop->id])
            ->where('addons', fn ($addons) => collect($addons)->pluck('id')->doesntContain($online->id)
                && collect($addons)->firstWhere('id', $fruit->id)['left'] === 10 && collect($addons)->firstWhere('id', $scoop->id)['left'] === null
                && collect($addons)->firstWhere('id', $scoop->id)['scope'] === 'مع Protein shake'));

        // Two shakes at 2.000 less 0.500, two scoops and a banana each: 4.000 + 2.000 + 0.600 − 0.500, VAT 5% after.
        // A price sent for an add-on is not read.
        $form = $this->form($shake, [['addon_id' => $scoop->id, 'quantity' => 2, 'unit_price' => 0], ['addon_id' => $fruit->id, 'quantity' => 1]]);
        $preview = $this->actingAs($owner)->postJson(route('club.invoices.preview'), $form)->assertOk()->json();
        $this->assertSame(['6.600', '0.500', '0.305', '6.405'], [$preview['totals']['subtotal'], $preview['totals']['discount_total'], $preview['totals']['tax_total'], $preview['totals']['total']]);
        $this->assertStringContainsString('+ Extra scoop — Protein shake', $preview['html']);
        $this->assertStringContainsString('+ Add banana — Protein shake', $preview['html']);

        // One scoop a shake instead: the total follows.
        $fewer = $this->actingAs($owner)->postJson(route('club.invoices.preview'), $this->form($shake, [['addon_id' => $scoop->id, 'quantity' => 1], ['addon_id' => $fruit->id, 'quantity' => 1]]))->json('totals');
        $this->assertSame(['5.600', '5.355'], [$fewer['subtotal'], $fewer['total']]);
        $this->asTenant($club, fn () => $this->assertSame(0, Invoice::withoutGlobalScope('issued')->count(), 'a preview saves nothing'));

        $this->actingAs($owner)->post(route('club.invoices.store'), $form + ['issue' => 1, 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $invoice = $this->asTenant($club, fn () => Invoice::sole());
        $this->assertSame('6.405', $invoice->total);

        $this->asTenant($club, function () use ($invoice, $shake, $scoop, $fruit, $banana) {
            [$line, $scoops, $bananas] = InvoiceItem::where('invoice_id', $invoice->id)->orderBy('id')->get()->all();
            $this->assertSame([null, $shake->id, 'product'], [$line->parent_id, $line->itemable_id, $line->itemable_type]);
            $this->assertSame([$line->id, 'product_addon', $scoop->id, '4.000', '0.500', '2.000'], [$scoops->parent_id, $scoops->itemable_type, $scoops->itemable_id, $scoops->quantity, $scoops->unit_price, $scoops->line_total]);
            $this->assertSame([$line->id, $fruit->id, '2.000', '0.300', '0.600'], [$bananas->parent_id, $bananas->itemable_id, $bananas->quantity, $bananas->unit_price, $bananas->line_total]);
            $this->assertSame('+ Add banana — Protein shake', $bananas->description);

            // The shakes and the bananas their add-on uses left the shelf, once, on this invoice.
            $this->assertSame(['8.000', '8.000'], [$shake->fresh()->stock_quantity, $banana->fresh()->stock_quantity]);
            $moved = StockMovement::where('reference_type', 'invoice')->where('reference_id', $invoice->id)->orderBy('id')->get();
            $this->assertSame([[$shake->id, '-2.000'], [$banana->id, '-2.000']], $moved->map(fn ($m) => [$m->product_id, $m->quantity])->all());
            $this->assertSame('إضافة «Add banana» مع «Protein shake»', $moved[1]->note);

            // The books carry the whole invoice: owed in full, revenue net of the tax, the tax payable.
            $this->assertSame('6.405', app(FinanceReports::class)->balanceOf('receivable'));
            $this->assertSame('0.305', app(FinanceReports::class)->balanceOf('vat_payable'));
        });

        // Issued as previewed: the paper, the print page and the PDF carry the add-ons under the shake.
        $issued = $this->asTenant($club, fn () => Papers::for('invoice', $invoice->fresh())->html());
        $this->assertSame(str_replace('مسودة', $invoice->number, $preview['html']), $issued);
        $this->assertSame($issued, $this->actingAs($owner)->get(route('club.invoices.print', ['invoice' => $invoice, 'print' => 0]))->getContent());
        $this->assertStringStartsWith('%PDF', $this->actingAs($owner)->get(route('club.papers.pdf', ['type' => 'invoice', 'id' => $invoice->id]))->assertOk()->getContent());

        // Counted as sold on the add-ons page.
        $this->actingAs($owner)->get(route('club.product-addons.index'))->assertInertia(fn ($page) => $page
            ->where('addons', fn ($addons) => collect($addons)->firstWhere('id', $scoop->id)['sold'] == 4 && collect($addons)->firstWhere('id', $fruit->id)['sold'] == 2));

        // The invoice's page marks each add-on line under its product.
        $this->actingAs($owner)->get(route('club.invoices.show', $invoice))->assertInertia(fn ($page) => $page
            ->where('invoice.items.0.addon', false)->where('invoice.items.1.addon', true)->where('invoice.items.2.addon', true)
            ->where('invoice.items.1.parent_id', $page->toArray()['props']['invoice']['items'][0]['id']));
    }

    public function test_an_addon_against_the_rules_is_refused_on_its_line_and_nothing_is_saved(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$shake, $banana, $scoop, $fruit] = $this->shop($club, bananas: 3);
        $bar = $this->product($club, ['name' => 'Protein bar', 'price' => 1]);
        $barOnly = $this->addon($club, ['name' => 'Chocolate dip'], [$bar]);
        $online = $this->addon($club, ['name' => 'Vanilla', 'show_in_pos' => false]);
        $retired = $this->addon($club, ['name' => 'Honey', 'is_active' => false]);
        $stranger = $this->addon($this->club('Other'), ['name' => 'Elsewhere']);
        $issue = fn (array $form) => $this->actingAs($owner)->from(route('club.invoices.create'))->post(route('club.invoices.store'), $form + ['issue' => 1, 'client_key' => (string) Str::uuid()]);

        $issue($this->form($shake, [['addon_id' => $barOnly->id, 'quantity' => 1]]))->assertSessionHasErrors(['lines.0.addons' => '«Chocolate dip» لا تُباع مع «Protein shake».']);
        $issue($this->form($shake, [['addon_id' => $online->id, 'quantity' => 1]]))->assertSessionHasErrors(['lines.0.addons' => 'إحدى الإضافات المختارة لم تعد متاحة — احذفها من البند.']);
        $issue($this->form($shake, [['addon_id' => $retired->id, 'quantity' => 1]]))->assertSessionHasErrors('lines.0.addons');
        $issue($this->form($shake, [['addon_id' => $scoop->id, 'quantity' => 3]]))->assertSessionHasErrors(['lines.0.addons' => 'الحد المسموح من «Extra scoop» هو 2 لكل قطعة.']);
        $issue($this->form($shake, [['addon_id' => $scoop->id, 'quantity' => 1], ['addon_id' => $scoop->id, 'quantity' => 1]]))->assertSessionHasErrors(['lines.0.addons' => '«Extra scoop» مختارة مرتين على البند نفسه.']);
        $issue($this->form($shake, [['addon_id' => $stranger->id, 'quantity' => 1]]))->assertSessionHasErrors('lines.0.addons.0.addon_id');
        $typed = $this->form($shake, [], ['lines' => [['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 5, 'addons' => [['addon_id' => $scoop->id, 'quantity' => 1]]]]]);
        $issue($typed)->assertSessionHasErrors(['lines.0.addons' => 'الإضافات تُختار مع صنف من الكتالوج.']);

        // Two shakes need two bananas each of three: the whole invoice is refused, nothing leaves the shelf.
        $issue($this->form($shake, [['addon_id' => $fruit->id, 'quantity' => 1]], ['lines' => [['product_id' => $shake->id, 'description' => 'Protein shake', 'quantity' => 4, 'unit_price' => 2, 'addons' => [['addon_id' => $fruit->id, 'quantity' => 1]]]]]))
            ->assertSessionHasErrors(['items' => 'الكمية المتوفرة من «Banana» غير كافية (3).']);

        $this->asTenant($club, function () use ($shake, $banana) {
            $this->assertSame(0, Invoice::withoutGlobalScope('issued')->count());
            $this->assertSame(0, StockMovement::where('reference_type', 'invoice')->count());
            $this->assertSame(['10.000', '3.000'], [$shake->fresh()->stock_quantity, $banana->fresh()->stock_quantity]);
        });

        // The live paper leaves out what saving would refuse, as it leaves out an unfinished line.
        $preview = $this->actingAs($owner)->postJson(route('club.invoices.preview'), $this->form($shake, [['addon_id' => $barOnly->id, 'quantity' => 1], ['addon_id' => $scoop->id, 'quantity' => 1]]))->assertOk()->json();
        $this->assertSame('5.000', $preview['totals']['subtotal']);
        $this->assertStringNotContainsString('Chocolate dip', $preview['html']);
    }

    public function test_a_draft_keeps_its_addons_reopens_with_them_and_is_issued_once(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$shake, $banana, $scoop, $fruit] = $this->shop($club);

        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form($shake, [['addon_id' => $scoop->id, 'quantity' => 2], ['addon_id' => $fruit->id, 'quantity' => 1]]) + ['issue' => 0, 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $draft = $this->asTenant($club, fn () => Invoice::drafts()->sole());
        $this->asTenant($club, function () use ($draft, $shake, $banana) {
            $this->assertSame(2, InvoiceItem::where('invoice_id', $draft->id)->whereNotNull('parent_id')->count());
            $this->assertSame(['10.000', '10.000'], [$shake->fresh()->stock_quantity, $banana->fresh()->stock_quantity], 'a draft takes no stock');
            $this->assertSame(0, JournalEntry::where('source_type', 'invoice')->count());
        });

        // Reopened: one line, its add-ons with how many per piece, and the paper with them.
        $this->actingAs($owner)->get(route('club.invoices.drafts.edit', $draft->id))->assertOk()->assertInertia(fn ($page) => $page
            ->has('draft.lines', 1)
            ->where('draft.lines.0.addons', [['id' => $scoop->id, 'qty' => 2, 'name' => 'Extra scoop', 'price' => '0.500'], ['id' => $fruit->id, 'qty' => 1, 'name' => 'Add banana', 'price' => '0.300']])
            ->where('paper.html', fn ($html) => str_contains($html, '+ Extra scoop — Protein shake'))
            ->where('paper.totals.total', '6.405'));

        // Changed (three shakes, one scoop each, no banana): the draft's lines are replaced, none left behind.
        $changed = $this->form($shake, [['addon_id' => $scoop->id, 'quantity' => 1]], ['lines' => [['product_id' => $shake->id, 'description' => 'Protein shake', 'quantity' => 3, 'unit_price' => 2, 'addons' => [['addon_id' => $scoop->id, 'quantity' => 1]]]]]);
        $this->actingAs($owner)->post(route('club.invoices.drafts.update', $draft->id), $changed + ['issue' => 0, 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(['7.500', 2], [$draft->fresh()->subtotal, InvoiceItem::where('invoice_id', $draft->id)->count()]));

        // Issued twice over (a double click): one invoice, the stock taken once, the draft gone with its lines.
        $key = (string) Str::uuid();
        $this->actingAs($owner)->post(route('club.invoices.drafts.update', $draft->id), $changed + ['issue' => 1, 'client_key' => $key])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.invoices.drafts.update', $draft->id), $changed + ['issue' => 1, 'client_key' => $key])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($draft, $shake, $banana) {
            $invoice = Invoice::sole();
            $this->assertSame(['7.875', 2], [$invoice->total, $invoice->items()->count()]);
            $this->assertSame(['7.000', '10.000'], [$shake->fresh()->stock_quantity, $banana->fresh()->stock_quantity]);
            $this->assertSame(0, InvoiceItem::where('invoice_id', $draft->id)->count());
            $this->assertSame(1, JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)->count());
        });
    }

    public function test_a_void_puts_the_addon_stock_back_once_and_a_credit_note_refunds_without_touching_the_shelf(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$shake, $banana, $scoop, $fruit] = $this->shop($club);
        $form = $this->form($shake, [['addon_id' => $scoop->id, 'quantity' => 1], ['addon_id' => $fruit->id, 'quantity' => 1]]);

        $this->actingAs($owner)->post(route('club.invoices.store'), $form + ['issue' => 1, 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $voided = $this->asTenant($club, fn () => Invoice::sole());
        $this->actingAs($owner)->post(route('club.invoices.void', $voided), ['reason' => 'خطأ في البنود'])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => app(InvoiceService::class)->void($voided->fresh(), 'مرة ثانية'));
        $this->asTenant($club, function () use ($voided, $shake, $banana) {
            $this->assertSame(Invoice::VOID, $voided->fresh()->status);
            $this->assertSame(['10.000', '10.000'], [$shake->fresh()->stock_quantity, $banana->fresh()->stock_quantity], 'everything back, once');
            $this->assertSame(2, StockMovement::where('type', 'invoice_void')->count());
            $this->assertSame('0.000', app(FinanceReports::class)->balanceOf('receivable'));
        });
        $this->actingAs($owner)->get(route('club.product-addons.index'))->assertInertia(fn ($page) => $page->where('addons', fn ($addons) => collect($addons)->sum('sold') == 0));

        // Paid in cash, then credited in full with the money handed back: the debt and the cash return, the shelf does
        // not (a returned item is put back by a stock adjustment), and nothing is credited twice.
        $this->actingAs($owner)->post(route('club.invoices.store'), $form + ['issue' => 1, 'payment_method' => 'cash', 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $paid = $this->asTenant($club, fn () => Invoice::where('status', Invoice::PAID)->sole());
        $this->actingAs($owner)->post(route('club.credit-notes.store', $paid), ['amount' => $paid->total, 'reason' => 'إرجاع', 'refund_now' => true, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->from(route('club.invoices.show', $paid))->post(route('club.credit-notes.store', $paid), ['amount' => '0.100', 'reason' => 'مرة ثانية'])->assertSessionHasErrors('amount');
        $this->asTenant($club, function () use ($paid, $shake, $banana) {
            $this->assertSame([Invoice::CREDITED, $paid->total, $paid->total], [$paid->fresh()->status, $paid->fresh()->credited_total, $paid->creditNotes()->sole()->refunded_total]);
            $this->assertSame(['8.000', '8.000'], [$shake->fresh()->stock_quantity, $banana->fresh()->stock_quantity]);
            $this->assertSame('0.000', app(FinanceReports::class)->balanceOf('receivable'));
        });
        $this->assertThrows(fn () => $this->asTenant($club, fn () => app(CreditNoteService::class)->issue($paid->fresh(), '0.100', 'مرة ثالثة')));
    }
}
