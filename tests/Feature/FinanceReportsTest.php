<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\SupplierBillService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The finance reports added to the reports page: the VAT report matches the books to the fils and lays the documents
 * beside them, without assuming the club is registered; a member's statement runs its balance from the one carried in;
 * the cash closing and adjustments reports read what happened; every report exports as PDF too, only for those allowed.
 */
class FinanceReportsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function vat(Tenant $club, float $rate, string $number = ''): void
    {
        $this->asTenant($club, fn () => $club->forceFill(['settings' => array_merge($club->settings ?? [], ['vat_rate' => $rate, 'vat_number' => $number])])->save());
    }

    /** @return array<string, string|int> figure label => value */
    private function figures($response): array
    {
        $figures = [];
        $response->assertInertia(function (Assert $page) use (&$figures) {
            foreach ($page->toArray()['props']['data']['figures'] as $f) {
                $figures[$f['label']] = $f['value'];
            }
        });

        return $figures;
    }

    public function test_the_vat_report_matches_the_books_and_shows_its_documents(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->vat($club, 5, 'OM1100223344');
        $this->asTenant($club, function () {
            $paid = app(InvoiceService::class)->issue(null, [['description' => 'اشتراك', 'unit_price' => 100]], ['type' => 'other'], 'شركة');
            app(PaymentService::class)->record($paid, $paid->total, 'cash');
            app(CreditNoteService::class)->issue($paid->fresh(), '21.000', 'خصم');
            $mistake = app(InvoiceService::class)->issue(null, [['description' => 'خطأ', 'unit_price' => 40]], ['type' => 'other'], 'عميل');
            app(InvoiceService::class)->void($mistake, 'أُصدرت بالخطأ');
            $bills = app(SupplierBillService::class);
            $bill = $bills->create(Supplier::create(['name' => 'مورد الأجهزة', 'is_active' => true]), ['reference' => 'S-1', 'issue_date' => today()->toDateString(), 'subtotal' => '60', 'tax' => '3']);
            $bills->approve($bill);
        });

        $page = $this->actingAs($owner)->get(route('club.reports.show', ['report' => 'tax']));
        $page->assertOk();
        $f = $this->figures($page);
        // Output: 5 + 2 issued − 2 voided − 1 credit note = 4; input 3; net 1; documents and books agree.
        $this->assertSame(['4.000', '3.000', '1.000', '0.000'], [$f['ضريبة المخرجات (بعد الإلغاء والإشعارات)'], $f['ضريبة المدخلات (المشتريات)'], $f['صافي الضريبة المستحقة للفترة'], $f['فرق المستندات عن الدفاتر']]);
        $page->assertInertia(fn (Assert $p) => $p->where('data.options.notes.1.text', fn ($t) => str_contains($t, 'OM1100223344')));

        // As a PDF, the same report: a real PDF file.
        $pdf = $this->actingAs($owner)->get(route('club.reports.export', ['report' => 'tax', 'format' => 'pdf']));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->streamedContent());
        $csv = $this->actingAs($owner)->get(route('club.reports.export', ['report' => 'tax', 'format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('تقرير مساعد وليس إقرارًا رسميًا', $csv);
    }

    public function test_the_vat_report_does_not_assume_the_club_is_registered(): void
    {
        $club = $this->club();
        $this->vat($club, 0);
        $this->actingAs($this->owner($club))->get(route('club.reports.show', ['report' => 'tax']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('data.options.notes.1.tone', 'amber')->where('data.options.notes.1.text', fn ($t) => str_contains($t, '0%')));
        // Opening it takes the books' permission: reception has none.
        $this->actingAs($this->staff($club, 'reception'))->get(route('club.reports.show', ['report' => 'tax']))->assertForbidden();
    }

    public function test_a_members_statement_runs_its_balance_from_the_one_carried_in(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);
        $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00'));
        $this->asTenant($club, fn () => app(InvoiceService::class)->issue($member, [['description' => 'سبتمبر', 'unit_price' => 30]], ['type' => 'other']));
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00'));
        $this->asTenant($club, function () use ($member) {
            $october = app(InvoiceService::class)->issue($member, [['description' => 'أكتوبر', 'unit_price' => 50]], ['type' => 'other']);
            app(PaymentService::class)->record($october, '40', 'cash');
            app(CreditNoteService::class)->issue($october->fresh(), '5', 'خصم');
        });

        $page = $this->actingAs($owner)->get(route('club.reports.show', ['report' => 'member-statement', 'member_id' => $member->id, 'from' => '2026-10-01', 'to' => '2026-10-31']));
        $f = $this->figures($page);
        // Carried in 30 (September); October: +50 −40 −5 → 35.
        $this->assertSame(['30.000', '50.000', '45.000'], [$f['رصيد أول المدة'], $f['المطالبات (مدين)'], $f['المدفوعات والتخفيضات (دائن)']]);
        $this->assertSame('35.000', $f['الرصيد آخر المدة (عليه)']);
        $page->assertInertia(fn (Assert $p) => $p->where('data.tables.0.rows.3.5', '35.000'));

        // Without a member it asks for one; reception without invoices.view cannot open it.
        $this->actingAs($owner)->get(route('club.reports.show', ['report' => 'member-statement']))->assertOk()->assertInertia(fn (Assert $p) => $p->where('data.empty', true));
        $this->actingAs($this->staff($club, 'trainer'))->get(route('club.reports.show', ['report' => 'member-statement', 'member_id' => $member->id]))->assertForbidden();
    }

    public function test_the_adjustments_report_reads_the_corrections_and_every_report_exports_as_pdf(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->asTenant($club, function () {
            $invoice = app(InvoiceService::class)->issue(null, [['description' => 'خدمة', 'unit_price' => 20]], ['type' => 'other'], 'عميل');
            $payment = app(PaymentService::class)->record($invoice, '20', 'cash');
            app(PaymentService::class)->cancel($payment, 'سُجّلت على الفاتورة الخطأ');
            app(InvoiceService::class)->void($invoice->fresh(), 'تكرار');
        });
        $f = $this->figures($this->actingAs($owner)->get(route('club.reports.show', ['report' => 'adjustments'])));
        $this->assertSame([1, 1], [$f['دفعات أُلغيت'], $f['فواتير أُلغيت']]);

        foreach (['finance', 'dues', 'statements', 'members', 'cash-shifts', 'adjustments', 'tax'] as $report) {
            $res = $this->actingAs($owner)->get(route('club.reports.export', ['report' => $report, 'format' => 'pdf']));
            $res->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $res->streamedContent(), $report);
        }
        // Exporting takes reports.export: a trainer has neither the report nor the export.
        $this->actingAs($this->staff($club, 'trainer'))->get(route('club.reports.export', ['report' => 'adjustments', 'format' => 'pdf']))->assertForbidden();
    }
}
