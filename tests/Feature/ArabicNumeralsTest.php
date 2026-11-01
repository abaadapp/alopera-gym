<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** A figure typed on an Arabic keyboard is the figure; words that hold digits stay as typed. */
class ArabicNumeralsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_figures_typed_in_arabic_digits_are_read_as_figures_and_words_are_left_alone(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $form = [
            'customer_mode' => 'walk_in',
            'customer_name' => 'فرع ٢ — الخوض',
            'issue_date' => today()->toDateString(),
            'lines' => [['description' => 'حصة ٣ أيام', 'quantity' => '٢', 'unit_price' => '١٢٫٥٠٠', 'discount' => '۰٫۵']],
        ];

        $preview = $this->actingAs($owner)->postJson(route('club.invoices.preview'), $form)->assertOk()->json('totals');
        $this->assertSame(['25.000', '0.500', '24.500'], [$preview['subtotal'], $preview['discount_total'], $preview['total']]);

        $this->actingAs($owner)->post(route('club.invoices.store'), $form + ['issue' => 1])->assertSessionHasNoErrors();
        $invoice = $this->asTenant($club, fn () => Invoice::with('items')->sole());
        $this->assertSame(['24.500', 'فرع ٢ — الخوض', 'حصة ٣ أيام', '2.000'], [$invoice->total, $invoice->customer_name, $invoice->items[0]->description, $invoice->items[0]->quantity]);
    }
}
