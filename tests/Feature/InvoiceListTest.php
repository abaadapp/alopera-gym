<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * فواتير العملاء: the list's tabs split invoices by how much is paid and whether they are late; the document status,
 * type, dates and page size filter on the server; older links keep working; and the manual invoice's live paper is the
 * invoice that will be issued, with nothing saved or numbered.
 */
class InvoiceListTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function invoice(Tenant $club, string $name, array $options = [], ?string $paid = null): Invoice
    {
        return $this->asTenant($club, function () use ($name, $options, $paid) {
            $invoice = app(InvoiceService::class)->issue(null, [['description' => 'خدمة', 'unit_price' => '30']], $options + ['type' => 'other', 'due_date' => today()->addDays(7)->toDateString()], $name);
            if ($paid !== null) {
                app(PaymentService::class)->record($invoice, $paid, 'cash');
            }

            return $invoice->fresh();
        });
    }

    /** @return list<string> the customers listed, newest first */
    private function listed(Tenant $club, array $query): array
    {
        return collect($this->actingAs($this->owner($club))->get(route('club.invoices.index', $query))->assertOk()->viewData('page')['props']['invoices']['data'])->pluck('customer_name')->all();
    }

    public function test_the_tabs_split_invoices_by_payment_and_lateness_and_older_links_still_filter(): void
    {
        $club = $this->club();
        $late = today()->subDays(2)->toDateString();
        $this->invoice($club, 'غير مدفوعة');
        $this->invoice($club, 'جزئية', [], '10');
        $this->invoice($club, 'مدفوعة', [], '30');
        $this->invoice($club, 'متأخرة', ['due_date' => $late]);
        $this->invoice($club, 'متأخرة جزئيًا', ['due_date' => $late], '10');
        $void = $this->invoice($club, 'ملغاة');
        $this->asTenant($club, fn () => app(InvoiceService::class)->void($void, 'خطأ'));
        // Credited in full after it was paid (an unpaid one is voided instead): its net is nothing, the money owed back.
        $credited = $this->invoice($club, 'بإشعار دائن', [], '30');
        $this->asTenant($club, fn () => app(CreditNoteService::class)->issue($credited, '30', 'إلغاء كامل'));

        $this->assertSame(['غير مدفوعة'], $this->listed($club, ['state' => 'unpaid']));
        $this->assertSame(['جزئية'], $this->listed($club, ['state' => 'partial']));
        $this->assertSame(['مدفوعة'], $this->listed($club, ['state' => 'paid']));
        $this->assertSame(['متأخرة جزئيًا', 'متأخرة'], $this->listed($club, ['state' => 'overdue']));
        $this->assertSame(['ملغاة'], $this->listed($club, ['status' => 'void']));
        $this->assertSame(['بإشعار دائن'], $this->listed($club, ['status' => 'credited']));
        $this->assertNotContains('ملغاة', $this->listed($club, ['status' => 'active']));
        $this->assertCount(5, $this->listed($club, ['status' => 'active']));
        // The links that pointed here before the tabs: still open, still late, still the raw status.
        $this->assertSame(['متأخرة جزئيًا', 'متأخرة', 'جزئية', 'غير مدفوعة'], $this->listed($club, ['status' => 'open']));
        $this->assertSame(['متأخرة جزئيًا', 'متأخرة'], $this->listed($club, ['status' => 'overdue']));
        $this->assertSame(['متأخرة جزئيًا', 'جزئية'], $this->listed($club, ['status' => 'partially_paid']));

        // One word a row: late before partly paid; voided and credited say so.
        $states = collect($this->actingAs($this->owner($club))->get(route('club.invoices.index'))->viewData('page')['props']['invoices']['data'])->pluck('state', 'customer_name');
        $this->assertSame(['بإشعار دائن' => 'credited', 'ملغاة' => 'void', 'متأخرة جزئيًا' => 'overdue', 'متأخرة' => 'overdue', 'مدفوعة' => 'paid', 'جزئية' => 'partial', 'غير مدفوعة' => 'unpaid'], $states->all());
    }

    public function test_type_dates_page_size_and_search_filter_on_the_server(): void
    {
        $club = $this->club();
        $this->invoice($club, 'ليلى أحمد', ['type' => 'pt', 'issue_date' => today()->subDays(10)->toDateString(), 'due_date' => today()->addDays(20)->toDateString()]);
        $this->invoice($club, 'سارة علي', ['type' => 'membership']);
        // A member's invoice carries a phone: searching a name must not match every invoice that has one.
        $member = $this->member($club, ['full_name' => 'منى سالم', 'phone' => '96891112222']);
        $this->asTenant($club, fn () => app(InvoiceService::class)->issue($member, [['description' => 'عضوية', 'unit_price' => '20']], ['type' => 'membership']));

        $this->assertSame(['ليلى أحمد'], $this->listed($club, ['type' => 'pt']));
        $this->assertSame(['ليلى أحمد'], $this->listed($club, ['to' => today()->subDays(5)->toDateString()]));
        $this->assertSame(['منى سالم', 'سارة علي'], $this->listed($club, ['from' => today()->subDay()->toDateString()]));
        $this->assertSame(['ليلى أحمد'], $this->listed($club, ['due_from' => today()->addDays(15)->toDateString()]));
        $this->assertSame(['ليلى أحمد'], $this->listed($club, ['q' => 'ليلى']));
        $this->assertSame(['منى سالم'], $this->listed($club, ['q' => '1112222']));

        $this->asTenant($club, function () {
            foreach (range(1, 22) as $i) {
                app(InvoiceService::class)->issue(null, [['description' => 'بند', 'unit_price' => '1']], [], 'عميل '.$i);
            }
        });
        $page = fn (array $q) => $this->actingAs($this->owner($club))->get(route('club.invoices.index', $q))->viewData('page')['props']['invoices'];
        $this->assertSame([20, 2], [count($page([])['data']), $page([])['last_page']]);
        $this->assertCount(25, $page(['per_page' => 50])['data']);
        $this->assertCount(20, $page(['per_page' => 7])['data'], 'only 20, 50 or 100 a page');
    }

    public function test_the_manual_invoice_paper_is_the_invoice_that_will_be_issued_and_nothing_is_saved(): void
    {
        $club = $this->club();
        $this->asTenant($club, fn () => $club->forceFill(['settings' => array_merge($club->settings ?? [], ['vat_rate' => 5])])->save());
        $owner = $this->owner($club);
        $lines = [['description' => 'تأجير القاعة — 2026-11-05', 'quantity' => 2, 'unit_price' => 40, 'discount' => 5], ['description' => '', 'quantity' => 1, 'unit_price' => 9]];

        $preview = $this->actingAs($owner)->postJson(route('club.invoices.preview'), ['customer_name' => 'شركة الواحة', 'lines' => $lines])->assertOk()->json();
        $this->assertStringContainsString('تأجير القاعة', $preview['html']);
        $this->assertStringContainsString('شركة الواحة', $preview['html']);
        $this->assertStringContainsString('<bdo dir="ltr">2026-11-05</bdo>', $preview['html']);
        $this->assertSame(['80.000', '5.000', '3.750', '78.750'], [$preview['totals']['subtotal'], $preview['totals']['discount_total'], $preview['totals']['tax_total'], $preview['totals']['total']], 'the unfinished second line is left out');
        $this->asTenant($club, fn () => $this->assertSame(0, Invoice::count()));

        // Issued from the same form: the same figures, and the first number (the preview took none).
        $this->actingAs($owner)->post(route('club.invoices.store'), ['customer_name' => 'شركة الواحة', 'lines' => [$lines[0]]])->assertSessionHasNoErrors();
        $issued = $this->asTenant($club, fn () => Invoice::sole());
        $this->assertSame([$preview['totals']['subtotal'], $preview['totals']['tax_total'], $preview['totals']['total']], [$issued->subtotal, $issued->tax_total, $issued->total]);
        $this->assertStringEndsWith('000001', $issued->number);

        // No line yet: no paper. A discount larger than its line is shown whole, not refused. Without the permission: 403.
        $this->assertNull($this->actingAs($owner)->postJson(route('club.invoices.preview'), ['lines' => [['description' => '', 'unit_price' => 5, 'quantity' => 1]]])->json('html'));
        $this->assertSame('0.000', $this->actingAs($owner)->postJson(route('club.invoices.preview'), ['lines' => [['description' => 'بند', 'unit_price' => 5, 'quantity' => 1, 'discount' => 9]]])->json('totals.total'));
        $this->actingAs($this->staff($club, 'trainer'))->postJson(route('club.invoices.preview'), ['lines' => $lines])->assertForbidden();
        $stranger = $this->member($this->club('Other'));
        $this->actingAs($owner)->postJson(route('club.invoices.preview'), ['member_id' => $stranger->id, 'lines' => $lines])->assertUnprocessable();
    }
}
