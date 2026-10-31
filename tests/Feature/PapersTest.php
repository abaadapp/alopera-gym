<?php

namespace Tests\Feature;

use App\Core\Papers\Papers;
use App\Core\Papers\PaperTypes;
use App\Models\Tenant;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Gym\Services\PtService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductAddon;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The papers: one engine draws every document for the screen, the print page, the PDF and the customer's link; each
 * club sets its own templates; a live preview is the paper that prints; an issued invoice keeps the identity it was
 * issued with; and the papers are reached only with their documents' permissions.
 */
class PapersTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function sellMembership(Tenant $club): Invoice
    {
        $member = $this->member($club, ['full_name' => 'ليلى أحمد']);
        $plan = $this->plan($club, ['name' => 'باقة ثلاثة أشهر', 'duration_value' => 3, 'price' => 75]);

        return $this->asTenant($club, fn () => app(MembershipService::class)->sell($member, $plan)->invoice()->firstOrFail());
    }

    private function sale(Tenant $club): Sale
    {
        $owner = $this->owner($club);
        [$shake, $scoop] = $this->asTenant($club, function () {
            $shake = Product::create(['name' => 'مشروب بروتين', 'price' => 2]);
            app(StockService::class)->move($shake, 10, 'adjustment', null, 'opening', 0.8);
            $scoop = ProductAddon::create(['name' => 'جرعة إضافية', 'price' => 0.5, 'applies_to' => 'products', 'max_quantity' => 2]);
            $scoop->products()->sync([$shake->id]);

            return [$shake, $scoop];
        });
        $this->actingAs($owner)->post(route('club.pos.checkout'), ['items' => [['product_id' => $shake->id, 'quantity' => 2, 'addons' => [['addon_id' => $scoop->id, 'quantity' => 1]]]], 'payment_method' => 'cash'])->assertSessionHasNoErrors();

        return $this->asTenant($club, fn () => Sale::latest('id')->firstOrFail());
    }

    public function test_membership_renewal_training_and_product_invoices_print_what_was_sold(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $invoice = $this->sellMembership($club);
        $this->actingAs($owner)->get(route('club.invoices.print', $invoice))->assertOk()
            ->assertSee('باقة ثلاثة أشهر')->assertSee('ليلى أحمد')->assertSee('75.000')->assertSee('window.print', false)
            // Dates in an item stay as written after an Arabic word.
            ->assertSee('<bdo dir="ltr">'.now()->toDateString().'</bdo>', false);

        $renewal = $this->asTenant($club, function () use ($invoice) {
            app(PaymentService::class)->record($invoice, $invoice->total, 'cash');

            return app(MembershipService::class)->renew($invoice->billable)->invoice()->firstOrFail();
        });
        $this->actingAs($owner)->get(route('club.papers.show', ['type' => 'invoice', 'id' => $renewal->id]))->assertOk()->assertSee('تجديد — باقة ثلاثة أشهر');

        $pt = $this->asTenant($club, function () use ($invoice) {
            $package = PtPackage::create(['name' => 'باقة ثماني جلسات', 'sessions_count' => 8, 'price' => 96, 'validity_days' => 60]);

            return app(PtService::class)->sell($invoice->billable, $package)->invoice()->firstOrFail();
        });
        $this->actingAs($owner)->get(route('club.papers.show', ['type' => 'invoice', 'id' => $pt->id]))->assertOk()->assertSee('تدريب شخصي — باقة ثماني جلسات (8 جلسات)')->assertSee('تدريب شخصي');

        // A product sold with an add-on: its own invoice, issued once, lists the add-on; no second invoice is made.
        $sale = $this->sale($club);
        $this->asTenant($club, fn () => $this->assertSame(1, Invoice::where('type', 'pos')->count()));
        $this->actingAs($owner)->get(route('club.papers.show', ['type' => 'invoice', 'id' => $sale->invoice_id]))->assertOk()->assertSee('+ جرعة إضافية — مشروب بروتين')->assertSee('5.000');
        $this->actingAs($owner)->get(route('club.sales.receipt', $sale))->assertOk()
            ->assertSeeInOrder(['مشروب بروتين', '4.000', '+ جرعة إضافية', '1.000', 'الإجمالي', '5.000'], false)->assertSee('size: 80mm auto', false);
    }

    public function test_partial_payments_and_credit_notes_show_on_the_invoice_and_their_own_papers(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $invoice = $this->sellMembership($club);
        [$payment, $note] = $this->asTenant($club, function () use ($invoice) {
            $payment = app(PaymentService::class)->record($invoice, '30', 'cash');
            $note = app(CreditNoteService::class)->issue($invoice->fresh(), '15', 'خصم بعد شكوى');

            return [$payment, $note];
        });

        $this->actingAs($owner)->get(route('club.papers.show', ['type' => 'invoice', 'id' => $invoice->id]))->assertOk()
            ->assertSee('مدفوعة جزئيًا')->assertSee('الإشعارات الدائنة')->assertSee('− 15.000')->assertSee('30.000')->assertSee('30.000');
        $this->actingAs($owner)->get(route('club.payments.receipt', $payment))->assertOk()->assertSee('سند قبض')->assertSee($invoice->number)->assertSee('30.000');
        $this->actingAs($owner)->get(route('club.payments.receipt', ['payment' => $payment->id, 'face' => 'strip']))->assertOk()->assertSee('size: 80mm auto', false);
        $this->actingAs($owner)->get(route('club.credit-notes.print', $note))->assertOk()->assertSee('إشعار دائن')->assertSee('خصم بعد شكوى')->assertSee('15.000');
    }

    public function test_every_document_prints_and_downloads_with_its_own_permission(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $invoice = $this->sellMembership($club);
        $sale = $this->sale($club);
        $this->asTenant($club, fn () => Employee::create(['name' => 'سارة', 'basic_salary' => '400', 'status' => 'active']));
        $this->actingAs($owner)->post(route('club.payroll.store'), ['month' => now()->format('Y-m')]);
        $run = $this->asTenant($club, fn () => PayrollRun::firstOrFail());

        foreach ([['invoice', $invoice->id, 'فاتورة'], ['sale', $sale->id, 'فاتورة بيع'], ['payroll_sheet', $run->id, 'كشف مسيرة الرواتب']] as [$type, $id, $title]) {
            $this->actingAs($owner)->get(route('club.papers.show', ['type' => $type, 'id' => $id]))->assertOk()->assertSee($title)->assertDontSee('window.print', false);
            $pdf = $this->actingAs($owner)->get(route('club.papers.pdf', ['type' => $type, 'id' => $id, 'download' => 1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $pdf->getContent());
            $this->assertStringContainsString('attachment;', $pdf->headers->get('Content-Disposition'));
        }

        // Reception collects payments and sells, but payroll papers are not theirs; a trainer reaches none.
        $reception = $this->staff($club, 'reception');
        $this->actingAs($reception)->get(route('club.papers.show', ['type' => 'invoice', 'id' => $invoice->id]))->assertOk();
        $this->actingAs($reception)->get(route('club.papers.show', ['type' => 'payroll_sheet', 'id' => $run->id]))->assertForbidden();
        $this->actingAs($this->staff($club, 'trainer'))->get(route('club.papers.pdf', ['type' => 'invoice', 'id' => $invoice->id]))->assertForbidden();
        // Unknown types and records answer 404; another club's document does not exist here.
        $this->actingAs($owner)->get(route('club.papers.show', ['type' => 'nothing', 'id' => 1]))->assertNotFound();
        $this->actingAs($owner)->get(route('club.papers.show', ['type' => 'invoice', 'id' => 999999]))->assertNotFound();
        $other = $this->club('Other');
        $this->actingAs($this->owner($other))->get(route('club.papers.show', ['type' => 'invoice', 'id' => $invoice->id]))->assertNotFound();
        // Signed out: the panel's papers are not public.
        auth()->logout();
        $this->get(route('club.papers.pdf', ['type' => 'invoice', 'id' => $invoice->id]))->assertRedirect();
    }

    public function test_the_live_preview_is_the_paper_that_prints(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $invoice = $this->sellMembership($club);
        $values = ['header' => 'لياقة لكل الأعمار', 'footer' => "شكرًا لكم\nنراكم قريبًا", 'font' => 'large', 'lang' => 'ar', 'paper' => 'A5', 'show_phone' => false, 'show_payments' => true, 'show_notes' => true, 'show_logo' => true, 'show_branch' => true, 'show_employee' => true, 'show_signature' => true];

        $preview = $this->actingAs($owner)->postJson(route('club.settings.papers.preview', 'invoice'), $values + ['face' => 'sheet'])->assertOk()->json();
        $this->assertSame('A5', $preview['size']);
        $this->assertStringContainsString('لياقة لكل الأعمار', $preview['html']);
        $this->assertStringNotContainsString('Invoice', $preview['html'], 'Arabic only');
        // Not saved by previewing.
        $this->assertStringNotContainsString('لياقة لكل الأعمار', $this->actingAs($owner)->get(route('club.papers.show', ['type' => 'invoice', 'id' => $invoice->id]))->getContent());

        $this->actingAs($owner)->put(route('club.settings.papers.update', 'invoice'), $values)->assertSessionHasNoErrors();
        $printed = $this->actingAs($owner)->get(route('club.papers.show', ['type' => 'invoice', 'id' => $invoice->id]))->assertOk()->getContent();
        $this->assertSame($preview['html'], $printed, 'the preview and the printed paper are the same HTML');
        $this->assertStringContainsString('size: 148mm 210mm', $printed);

        // Restoring the defaults drops the club's settings for this paper only.
        $this->actingAs($owner)->delete(route('club.settings.papers.reset', 'invoice'))->assertSessionHasNoErrors();
        $this->assertSame('A4', PaperTypes::settings($club->fresh(), 'invoice')['paper']);
    }

    public function test_required_parts_have_no_switch_and_bad_values_are_refused(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $invoice = $this->sellMembership($club);

        $this->actingAs($owner)->put(route('club.settings.papers.update', 'invoice'), ['font' => 'huge'])->assertSessionHasErrors('font');
        $this->actingAs($owner)->put(route('club.settings.papers.update', 'invoice'), ['paper' => '80mm'])->assertSessionHasErrors('paper');
        // Switches the invoice does not have are ignored: the customer and the amounts always print.
        $this->actingAs($owner)->put(route('club.settings.papers.update', 'invoice'), ['show_customer' => false, 'show_prices' => false, 'show_phone' => false])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('show_customer', PaperTypes::settings($club->fresh(), 'invoice'));
        $this->actingAs($owner)->get(route('club.invoices.print', $invoice))->assertSee('ليلى أحمد')->assertSee('75.000');
        $this->actingAs($owner)->get(route('club.settings.papers.edit', 'nothing'))->assertNotFound();
    }

    public function test_a4_sheet_and_thermal_strip_follow_the_template(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $sale = $this->sale($club);

        $this->actingAs($owner)->get(route('club.sales.receipt', $sale))->assertSee('size: 80mm auto', false);
        $this->actingAs($owner)->put(route('club.settings.papers.update', 'sale'), ['strip' => '58mm', 'footer' => 'نراكم غدًا'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->get(route('club.sales.receipt', $sale))->assertSee('size: 58mm auto', false)->assertSee('نراكم غدًا');
        $this->actingAs($owner)->get(route('club.sales.receipt', ['sale' => $sale->id, 'face' => 'sheet']))->assertSee('size: 210mm 297mm', false)->assertSee('نراكم غدًا');
        $preview = $this->actingAs($owner)->postJson(route('club.settings.papers.preview', 'sale'), ['face' => 'strip'])->json();
        $this->assertSame('58mm', $preview['size']);
    }

    public function test_each_club_sets_its_own_papers_and_only_with_its_permission(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $this->actingAs($this->owner($a))->put(route('club.settings.papers.update', 'invoice'), ['footer' => 'تذييل النادي الأول'])->assertSessionHasNoErrors();
        $invoiceB = $this->sellMembership($b);

        $this->assertSame('', PaperTypes::settings($b->fresh(), 'invoice')['footer']);
        $this->actingAs($this->owner($b))->get(route('club.papers.show', ['type' => 'invoice', 'id' => $invoiceB->id]))->assertDontSee('تذييل النادي الأول');
        $this->actingAs($this->owner($b))->get(route('club.settings.papers.edit', 'invoice'))->assertInertia(fn ($p) => $p->where('paper.values.footer', ''));

        foreach (['reception', 'finance', 'trainer'] as $key) {
            $user = $this->staff($a, $key);
            $this->actingAs($user)->get(route('club.settings.papers'))->assertForbidden();
            $this->actingAs($user)->postJson(route('club.settings.papers.preview', 'invoice'), [])->assertForbidden();
            $this->actingAs($user)->put(route('club.settings.papers.update', 'invoice'), ['footer' => 'x'])->assertForbidden();
        }
        $this->actingAs($this->staff($a, 'manager'))->get(route('club.settings.papers'))->assertOk();
    }

    public function test_an_issued_invoice_keeps_the_club_identity_it_was_issued_with(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $club->forceFill(['name' => 'نادي الأمس', 'settings' => array_replace($club->settings ?? [], ['vat_rate' => 5, 'vat_number' => 'OM1111111111'])])->save();
        $old = $this->sellMembership($club);
        $this->actingAs($owner)->get(route('club.invoices.print', $old))->assertSee('فاتورة ضريبية')->assertSee('OM1111111111');

        $club->forceFill(['name' => 'نادي اليوم', 'settings' => array_replace($club->fresh()->settings ?? [], ['vat_number' => 'OM2222222222'])])->save();
        $new = $this->asTenant($club->fresh(), fn () => app(InvoiceService::class)->issue(null, [['description' => 'خدمة', 'unit_price' => 10]], [], 'زائر'));

        $this->actingAs($owner)->get(route('club.invoices.print', $old))->assertSee('نادي الأمس')->assertSee('OM1111111111')->assertDontSee('OM2222222222');
        $this->actingAs($owner)->get(route('club.invoices.print', $new))->assertSee('نادي اليوم')->assertSee('OM2222222222');

        // No tax number: a plain invoice, whatever the rate.
        $club->forceFill(['settings' => array_replace($club->fresh()->settings ?? [], ['vat_number' => null])])->save();
        $plain = $this->asTenant($club->fresh(), fn () => app(InvoiceService::class)->issue(null, [['description' => 'خدمة', 'unit_price' => 10]], [], 'زائر'));
        $this->actingAs($owner)->get(route('club.invoices.print', $plain))->assertDontSee('فاتورة ضريبية')->assertSee('ضريبة القيمة المضافة');
    }

    public function test_the_invoice_footer_from_the_club_settings_carries_over_until_the_template_has_one(): void
    {
        $club = $this->club();
        $club->forceFill(['settings' => array_replace($club->settings ?? [], ['invoice_footer' => 'المبالغ غير مستردة بعد التفعيل'])])->save();
        $invoice = $this->sellMembership($club);
        $this->actingAs($this->owner($club))->get(route('club.invoices.print', $invoice))->assertSee('المبالغ غير مستردة بعد التفعيل');
        $this->actingAs($this->owner($club))->put(route('club.settings.papers.update', 'invoice'), ['footer' => 'تذييل القالب'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner($club))->get(route('club.invoices.print', $invoice))->assertSee('تذييل القالب')->assertDontSee('المبالغ غير مستردة بعد التفعيل');
    }

    public function test_the_customers_link_shows_the_same_paper_and_nothing_else(): void
    {
        $club = $this->club();
        $invoice = $this->sellMembership($club);
        $panel = $this->asTenant($club, fn () => Papers::for('invoice', $invoice->fresh())->html());

        $this->get(route('site.invoice', ['club' => $club->slug, 'token' => $invoice->public_token]))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Site/Invoice')->where('paper.html', $panel)->where('invoice.number', $invoice->number));
        $this->get(route('site.invoice.print', ['club' => $club->slug, 'token' => $invoice->public_token]))->assertOk()->assertSee($invoice->number)->assertSee('window.print', false);
        $pdf = $this->get(route('site.invoice.pdf', ['club' => $club->slug, 'token' => $invoice->public_token]))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->get(route('site.invoice.pdf', ['club' => $club->slug, 'token' => str_repeat('a', 48)]))->assertNotFound();
        // Another club's link does not open this one.
        $other = $this->club('Other');
        $this->get(route('site.invoice', ['club' => $other->slug, 'token' => $invoice->public_token]))->assertNotFound();
    }
}
