<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * An invoice prints its lines in the order they were written, each add-on under its product: the database keeps no
 * order of its own, and a line changed after it was saved (a product line given its add-ons) came back last, so an
 * add-on printed above the product it belongs to.
 */
class DocumentLineOrderTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_an_invoice_reads_its_lines_in_the_order_they_were_written_after_one_is_changed(): void
    {
        $club = $this->club();
        $member = $this->member($club);

        $this->asTenant($club, function () use ($member) {
            $invoice = app(InvoiceService::class)->issue($member, [
                ['description' => 'الأول', 'quantity' => 1, 'unit_price' => 1],
                ['description' => 'الثاني', 'quantity' => 1, 'unit_price' => 1],
                ['description' => 'الثالث', 'quantity' => 1, 'unit_price' => 1],
            ]);
            // the first line touched after the others were saved, as a product line is when its add-ons are attached
            InvoiceItem::whereKey($invoice->items()->reorder()->min('id'))->update(['description' => 'الأول']);

            $this->assertSame(['الأول', 'الثاني', 'الثالث'], Invoice::findOrFail($invoice->id)->items->pluck('description')->all());
        });
    }
}
