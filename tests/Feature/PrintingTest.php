<?php

namespace Tests\Feature;

use App\Core\Papers\PaperFormat;
use App\Core\Papers\Papers;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Modules\Inventory\Models\PosDevice;
use App\Modules\Inventory\Models\PosPeripheral;
use App\Modules\Inventory\Models\PosPrintLog;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Rules\PrinterAddress;
use App\Modules\Inventory\Services\PosDevices;
use App\Modules\Inventory\Services\PosService;
use App\Modules\Inventory\Services\PrinterHealth;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Printing: every print door answers the same paper as a PDF (?format=pdf) for whoever may print it, within their
 * branch; a printer is configured as the printing layer can really reach it; an automatic print is claimed once;
 * every print's outcome goes to the log and a direct print or test sets the printer's state.
 */
class PrintingTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /** @return array{PosDevice, string} a device of the branch paired in a browser, and that browser's cookie */
    private function device(Tenant $club, Branch $branch): array
    {
        $this->actingAs($this->owner($club))->post(route('club.settings.pos-devices.store'), ['name' => 'كاشير', 'branch_id' => $branch->id])->assertSessionHas('pairing_code');
        $code = session('pairing_code')['code'];
        $response = $this->actingAs($this->staff($club, 'reception', ['branch_id' => $branch->id]))->post(route('club.pos.device.pair'), ['code' => $code]);

        return [$this->asTenant($club, fn () => PosDevice::latest('id')->firstOrFail()), $response->getCookie(PosDevices::COOKIE)->getValue()];
    }

    private function printer(Tenant $club, PosDevice $device, array $attributes = []): PosPeripheral
    {
        return $this->asTenant($club, fn () => $device->peripherals()->create($attributes + [
            'type' => 'printer', 'name' => 'طابعة الكاشير', 'connection' => 'network', 'address' => '192.168.1.50', 'port' => 9100,
            'paper' => '80mm', 'paper_width' => 80, 'is_default' => true, 'auto_print' => true, 'is_active' => true,
        ]));
    }

    private function sale(Tenant $club, Branch $branch, ?PosDevice $device = null): Sale
    {
        return $this->asTenant($club, function () use ($branch, $device) {
            $product = Product::create(['name' => 'Water', 'price' => 0.5]);
            app(StockService::class)->move($product, 10, 'adjustment', null, 'opening', 0.2, branchId: $branch->id);

            return app(PosService::class)->checkout([['product_id' => $product->id, 'quantity' => 1]], 'cash', branchId: $branch->id, device: $device);
        });
    }

    private function main(Tenant $club): Branch
    {
        return $this->asTenant($club, fn () => Branch::where('is_main', true)->firstOrFail());
    }

    public function test_every_print_door_answers_its_paper_as_a_pdf_for_whoever_may_print_it(): void
    {
        $club = $this->club();
        $main = $this->main($club);
        [$device, $cookie] = $this->device($club, $main);
        $this->printer($club, $device, ['paper' => '58mm', 'paper_width' => 58]);
        $sale = $this->sale($club, $main, $device);
        $cashier = $this->staff($club, 'cashier', ['branch_id' => $main->id]);

        // The receipt on this till's paper: the strip page for the browser, its PDF for the app.
        $this->withCookie(PosDevices::COOKIE, $cookie)->actingAs($cashier)->get(route('club.sales.receipt', $sale))->assertOk()->assertSee('size: 58mm auto', false);
        $pdf = $this->withCookie(PosDevices::COOKIE, $cookie)->actingAs($cashier)->get(route('club.sales.receipt', [$sale, 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[0 0 164\.\d+#', $pdf, '58 mm wide');
        // The point-of-sale clerk prints the payment receipt of a sale taken here, as a PDF too.
        $payment = $this->asTenant($club, fn () => $sale->invoice->payments()->firstOrFail());
        $this->actingAs($cashier)->get(route('club.payments.receipt', [$payment, 'face' => 'strip', 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // Its reprint is logged under the voucher's own number.
        $this->withCookie(PosDevices::COOKIE, $cookie)->actingAs($cashier)->withCredentials()->postJson(route('club.prints.store'), ['document' => 'receipt', 'document_id' => $payment->id, 'trigger' => 'reprint', 'via' => 'native', 'ok' => true, 'printed' => true])->assertOk();
        $this->asTenant($club, fn () => $this->assertSame($payment->receipt_number, PosPrintLog::latest('id')->value('number')));

        // Sheets: the invoice, the paper engine's door; papers.print is needed for the PDF, the document's own permission too.
        $owner = $this->owner($club);
        $this->actingAs($owner)->get(route('club.invoices.print', [$sale->invoice_id, 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($owner)->get(route('club.papers.show', ['type' => 'invoice', 'id' => $sale->invoice_id, 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $reader = $this->asTenant($club, fn () => Role::create(['key' => 'reader', 'name' => 'قارئ', 'permissions' => ['invoices.view']]));
        $noPrint = $this->staff($club, 'reception');
        $noPrint->forceFill(['role_id' => $reader->id])->save();
        $this->actingAs($noPrint->fresh())->get(route('club.papers.show', ['type' => 'invoice', 'id' => $sale->invoice_id]))->assertOk();
        $this->actingAs($noPrint->fresh())->get(route('club.papers.show', ['type' => 'invoice', 'id' => $sale->invoice_id, 'format' => 'pdf']))->assertForbidden();
        $this->actingAs($this->staff($club, 'trainer'))->get(route('club.sales.receipt', [$sale, 'format' => 'pdf']))->assertForbidden();
    }

    public function test_a_branch_never_prints_another_branchs_receipt(): void
    {
        $club = $this->club();
        $main = $this->main($club);
        $khoud = $this->asTenant($club, fn () => Branch::create(['name' => 'فرع الخوض', 'is_active' => true]));
        $sale = $this->sale($club, $main);
        $khoudCashier = $this->staff($club, 'cashier', ['branch_id' => $khoud->id]);

        $this->assertContains($this->actingAs($khoudCashier)->get(route('club.sales.receipt', [$sale, 'format' => 'pdf']))->getStatusCode(), [403, 404]);
        $this->actingAs($khoudCashier)->withCredentials()->postJson(route('club.prints.claim'), ['document' => 'sale', 'document_id' => $sale->id])->assertNotFound();
        $this->actingAs($khoudCashier)->withCredentials()->postJson(route('club.prints.store'), ['document' => 'sale', 'document_id' => $sale->id, 'trigger' => 'reprint', 'via' => 'native', 'ok' => true, 'printed' => true])->assertNotFound();
    }

    public function test_an_automatic_print_is_claimed_once_whatever_screen_or_reload_asks_again(): void
    {
        $club = $this->club();
        $main = $this->main($club);
        [$device, $cookie] = $this->device($club, $main);
        $printer = $this->printer($club, $device);
        $sale = $this->sale($club, $main, $device);
        $cashier = $this->staff($club, 'cashier', ['branch_id' => $main->id]);
        $claim = fn ($user) => $this->withCookie(PosDevices::COOKIE, $cookie)->actingAs($user)->withCredentials()->postJson(route('club.prints.claim'), ['document' => 'sale', 'document_id' => $sale->id])->assertOk()->json();

        $first = $claim($cashier);
        $this->assertTrue($first['claimed']);
        $this->assertSame(['claimed' => false, 'log' => null], $claim($cashier), 'a reload asks again: no second automatic print');
        $this->assertFalse($claim($this->staff($club, 'cashier', ['branch_id' => $main->id]))['claimed'], 'nor from a second screen');

        $this->asTenant($club, function () use ($first, $device, $printer) {
            $log = PosPrintLog::findOrFail($first['log']);
            $this->assertSame(['auto', PosPrintLog::CLAIMED, $device->id, $printer->id, 'sale:'.$log->document_id], [$log->trigger, $log->status, $log->pos_device_id, $log->pos_peripheral_id, $log->auto_key]);
        });

        // The claim is completed by who took it, once; nobody else completes it.
        $this->actingAs($this->owner($club))->withCredentials()->postJson(route('club.prints.store'), ['log' => $first['log'], 'via' => 'native', 'ok' => true, 'printed' => true])->assertNotFound();
        $this->actingAs($cashier)->withCredentials()->postJson(route('club.prints.store'), ['log' => $first['log'], 'via' => 'native', 'ok' => true, 'printed' => true, 'effective_port' => 9100])->assertOk()->assertJson(['status' => 'printed']);
        $this->actingAs($cashier)->withCredentials()->postJson(route('club.prints.store'), ['log' => $first['log'], 'via' => 'native', 'ok' => true, 'printed' => true])->assertNotFound();
        $this->asTenant($club, fn () => $this->assertSame('ok', $printer->fresh()->last_status));
    }

    public function test_every_outcome_is_logged_and_only_a_direct_print_or_a_test_sets_the_printers_state(): void
    {
        $club = $this->club();
        $main = $this->main($club);
        [$device, $cookie] = $this->device($club, $main);
        $printer = $this->printer($club, $device);
        $sale = $this->sale($club, $main, $device);
        $cashier = $this->staff($club, 'cashier', ['branch_id' => $main->id]);
        $store = fn (array $body, $user = null) => $this->withCookie(PosDevices::COOKIE, $cookie)->actingAs($user ?? $cashier)->withCredentials()->postJson(route('club.prints.store'), $body + ['document' => 'sale', 'document_id' => $sale->id, 'peripheral_id' => $printer->id]);

        // A cut mid-receipt: failed, may have printed, the printer in error with a plain message.
        $store(['trigger' => 'manual', 'via' => 'native', 'ok' => false, 'code' => 'SEND_FAILED', 'may_have_printed' => true, 'connected' => true, 'effective_port' => 9100])->assertOk()->assertJson(['status' => 'failed']);
        $this->asTenant($club, function () use ($printer) {
            $log = PosPrintLog::latest('id')->firstOrFail();
            $this->assertTrue($log->may_have_printed);
            $this->assertSame('انقطع الاتصال أثناء الإرسال — تحقق من الورق قبل إعادة الطباعة', $log->message());
            $this->assertSame(['error', 'انقطع الاتصال أثناء الإرسال — تحقق من الورق قبل إعادة الطباعة'], [$printer->fresh()->last_status, $printer->fresh()->last_error]);
        });

        // A dialog opened (the browser): «opened», never «printed», and the printer's state untouched.
        $store(['trigger' => 'reprint', 'via' => 'page', 'ok' => true, 'printed' => true])->assertOk()->assertJson(['status' => 'opened']);
        $this->asTenant($club, fn () => $this->assertSame('error', $printer->fresh()->last_status));

        // The reprint is printed by the app: the printer is well again.
        $store(['trigger' => 'reprint', 'via' => 'native', 'ok' => true, 'printed' => true])->assertOk()->assertJson(['status' => 'printed']);
        $this->asTenant($club, fn () => $this->assertSame(['ok', null], [$printer->fresh()->last_status, $printer->fresh()->last_error]));

        // A test page is for who manages the devices; a code that is not a code is refused.
        $test = ['document' => 'test', 'document_id' => null, 'trigger' => 'test', 'via' => 'native', 'ok' => false, 'code' => 'PORT_REFUSED', 'connected' => false];
        $this->actingAs($cashier)->withCredentials()->postJson(route('club.prints.store'), $test + ['peripheral_id' => $printer->id])->assertForbidden();
        $this->actingAs($this->owner($club))->withCredentials()->postJson(route('club.prints.store'), $test + ['peripheral_id' => $printer->id])->assertOk()->assertJson(['status' => 'failed']);
        $this->actingAs($this->owner($club))->withCredentials()->postJson(route('club.prints.store'), ['code' => 'drop table'] + $test + ['peripheral_id' => $printer->id])->assertUnprocessable();
        $this->asTenant($club, fn () => $this->assertSame('المنفذ لا يستجيب', $printer->fresh()->last_error));

        // The settings show the device's log, newest first, with who printed what.
        $this->actingAs($this->owner($club))->get(route('club.settings.pos-devices'))->assertInertia(fn ($page) => $page
            ->where('devices.0.logs.0.status', 'failed')->where('devices.0.logs.0.document', 'test')
            ->where('devices.0.logs.1.status', 'printed')->where('devices.0.logs.1.trigger', 'إعادة طباعة')->where('devices.0.logs.1.by', $cashier->name));
    }

    public function test_a_printer_is_saved_only_as_the_printing_layer_can_reach_it(): void
    {
        $club = $this->club();
        [$device] = $this->device($club, $this->main($club));
        $owner = $this->owner($club);
        $url = route('club.settings.pos-devices.peripherals.store', $device);
        $base = ['type' => 'printer', 'name' => 'طابعة'];

        // USB and Bluetooth are not offered as direct connections; the print dialog is.
        $this->actingAs($owner)->post($url, $base + ['connection' => 'usb'])->assertSessionHasErrors('connection');
        $this->actingAs($owner)->post($url, $base + ['connection' => 'bluetooth'])->assertSessionHasErrors('connection');
        $this->actingAs($owner)->post($url, $base + ['connection' => 'browser', 'paper' => 'A4'])->assertSessionHasNoErrors();
        // An address with a port or a scheme in it, or a public one, is refused as the app would refuse it.
        foreach (['192.168.1.50:9100', 'http://192.168.1.50', '8.8.8.8', '127.0.0.1', 'printer.example.com'] as $address) {
            $this->actingAs($owner)->post($url, $base + ['connection' => 'network', 'address' => $address])->assertSessionHasErrors('address');
        }
        $this->actingAs($owner)->post($url, $base + ['connection' => 'network', 'address' => ' 192.168.1.60 ', 'paper' => '58mm'])->assertSessionHasNoErrors();
        // A drawer keeps a record-only connection.
        $this->actingAs($owner)->post($url, ['type' => 'drawer', 'name' => 'درج', 'connection' => 'usb'])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($device) {
            $sheet = $device->peripherals()->where('paper', 'A4')->sole();
            $thermal = $device->peripherals()->where('paper', '58mm')->sole();
            $this->assertSame([null, false], [$sheet->paper_width, $sheet->printsDirect()], 'a sheet printer goes through the dialog');
            $this->assertSame(['192.168.1.60', 58, true], [$thermal->address, $thermal->paper_width, $thermal->printsDirect()]);
            $this->assertEquals(['connection' => 'network', 'paper' => '58mm', 'paper_width' => 58, 'port' => null], array_intersect_key($thermal->forPrinting(), array_flip(['connection', 'paper', 'paper_width', 'port'])));
        });
    }

    public function test_the_address_rule_is_the_apps_rule_on_the_shared_vectors(): void
    {
        $vectors = json_decode((string) file_get_contents(base_path('mobile/shared/fixtures/printing-vectors.json')), true);
        $this->assertNotEmpty($vectors['address']['cases']);
        foreach ($vectors['address']['cases'] as [$input, $kind]) {
            $this->assertSame($kind !== 'invalid', PrinterAddress::allows($input), "«{$input}» is {$kind} for the apps");
        }
    }

    public function test_a_printer_saved_before_as_usb_reads_as_the_print_dialog(): void
    {
        $club = $this->club();
        [$device] = $this->device($club, $this->main($club));
        $legacy = $this->asTenant($club, fn () => $device->peripherals()->create(['type' => 'printer', 'name' => 'قديمة', 'connection' => 'usb', 'paper_width' => 58, 'is_active' => true]));

        $this->asTenant($club, function () use ($legacy) {
            $this->assertSame(['browser', '58mm', 58, false], [$legacy->forPrinting()['connection'], $legacy->paperSize(), $legacy->forPrinting()['paper_width'], $legacy->printsDirect()]);
        });
    }

    public function test_typed_text_prints_what_the_font_draws_in_the_order_it_was_written(): void
    {
        // Emoji and pictographs come out as empty boxes on the PDF and the receipt printer: the paper leaves them out.
        $this->assertSame('شكرًا لزيارتكم', PaperFormat::printable('شكرًا لزيارتكم 🌹 💪'));
        $this->assertSame("خلود\nتابعونا", PaperFormat::printable("خلود 👍🏽\nتابعونا"));
        $this->assertSame('تَمْرِينٌ ـ © ± ←', PaperFormat::printable('تَمْرِينٌ ـ © ± ←'), 'Arabic marks, tatweel and the symbols the font has stay');
        // A handle, a phone number with its groups, an e-mail and a date are each kept left to right, whole.
        $html = PaperFormat::bidi("تابعونا @alopera.gym أو اتصل +968 9123 4567\ninfo@alopera.om · 2026-11-03")->toHtml();
        $this->assertStringContainsString('<bdo dir="ltr">@alopera.gym</bdo>', $html);
        $this->assertStringContainsString('<bdo dir="ltr">+968 9123 4567</bdo>', $html);
        $this->assertStringContainsString('<br>', $html, 'a line break is a <br>: the PDF engine ignores pre-line');
        $this->assertStringContainsString('<bdo dir="ltr">info@alopera.om · 2026-11-03</bdo>', $html);
        // A lone word or number needs nothing; what was typed is escaped once.
        $this->assertSame('باقة 8 جلسات Gold', PaperFormat::bidi('باقة 8 جلسات Gold')->toHtml());
        $this->assertSame('<bdo dir="ltr">A&amp;B Co</bdo>', PaperFormat::bidi('A&B Co')->toHtml());
        $this->assertSame('عرض &lt;script&gt;', PaperFormat::bidi('عرض <script>')->toHtml());
        $this->assertSame('اشتراك، <bdo dir="ltr">Visa / Master</bdo>', PaperFormat::bidi('اشتراك، Visa / Master')->toHtml());
        // In a left-to-right element the runs read right already: nothing is wrapped there.
        $this->assertSame('Instagram: @alopera.gym', PaperFormat::bidi('Instagram: @alopera.gym 📸', 'ltr')->toHtml());

        // On the receipt: each footer line in its own direction, emoji gone, the same in the print page and the PDF.
        $club = $this->club();
        $sale = $this->sale($club, $this->main($club));
        $this->asTenant($club, function () use ($club, $sale) {
            $club->forceFill(['settings' => array_replace_recursive($club->settings ?? [], ['papers' => ['sale' => ['footer' => "شكرًا لزيارتكم 🌹\nIf you can read this, the printer is ready."]]])])->save();
            foreach ([false, true] as $pdf) {
                $html = Papers::for('sale', $sale->fresh(), null, 'strip')->html(pdf: $pdf);
                $this->assertStringContainsString('<div dir="rtl">شكرًا لزيارتكم</div><div dir="ltr">If you can read this, the printer is ready.</div>', $html);
                $this->assertStringNotContainsString('🌹', $html);
            }
        });
    }

    public function test_a_document_sent_to_print_is_in_the_audit_trail_and_reading_it_is_not(): void
    {
        $club = $this->club();
        $main = $this->main($club);
        $sale = $this->sale($club, $main);
        $owner = $this->owner($club);
        $printed = fn () => $this->asTenant($club, fn () => DB::table('audit_logs')->where('event', 'paper.printed')->orderBy('id')->get());

        // Reading the paper on screen, and a till's receipt strip (it has its device's print log), are not.
        $this->actingAs($owner)->get(route('club.papers.show', ['type' => 'invoice', 'id' => $sale->invoice_id]))->assertOk();
        $this->actingAs($owner)->get(route('club.sales.receipt', [$sale, 'format' => 'pdf']))->assertOk();
        $this->assertCount(0, $printed());

        // The print page with its dialog, the PDF the app prints, and the PDF taken away are, with who and what.
        $this->actingAs($owner)->get(route('club.invoices.print', $sale->invoice_id))->assertOk();
        $this->actingAs($owner)->get(route('club.papers.show', ['type' => 'invoice', 'id' => $sale->invoice_id, 'format' => 'pdf']))->assertOk();
        $this->actingAs($owner)->get(route('club.papers.pdf', ['type' => 'invoice', 'id' => $sale->invoice_id, 'download' => 1]))->assertOk();
        $rows = $printed();
        $this->assertCount(3, $rows);
        $number = $this->asTenant($club, fn () => $sale->invoice->number);
        $this->assertSame(['page', 'pdf', 'pdf'], $rows->map(fn ($r) => json_decode($r->properties, true)['format'])->all());
        $this->assertSame([$number, 'invoice', true], [json_decode($rows[2]->properties, true)['number'], json_decode($rows[2]->properties, true)['paper'], json_decode($rows[2]->properties, true)['download']]);
        $this->assertSame([$owner->id, $sale->invoice_id, $club->id], [(int) $rows[0]->actor_id, (int) $rows[0]->subject_id, (int) $rows[0]->tenant_id]);
    }

    public function test_the_devices_screen_says_what_keeps_a_receipt_from_coming_out_and_mends_nothing(): void
    {
        $club = $this->club();
        $main = $this->main($club);
        [$device] = $this->device($club, $main);
        $owner = $this->owner($club);
        $codes = fn () => collect($this->actingAs($owner)->get(route('club.settings.pos-devices'))->assertOk()->viewData('page')['props']['devices'])
            ->firstWhere('id', $device->id)['warnings'];

        // Two printers and none default: receipts go to the older one, always, and the screen asks to choose.
        $first = $this->printer($club, $device, ['is_default' => false, 'auto_print' => false]);
        $second = $this->printer($club, $device, ['name' => 'طابعة المكتب', 'address' => '192.168.1.60', 'is_default' => false, 'auto_print' => true]);
        $this->asTenant($club, fn () => $this->assertSame($first->id, $device->fresh()->load('peripherals')->printer()->id));
        $warnings = $codes();
        $this->assertSame(['no_default', 'auto_elsewhere'], array_column($warnings, 'code'));
        $this->assertSame([PrinterHealth::CHECK, PrinterHealth::CERTAIN], array_column($warnings, 'level'));

        // Choosing a default from the screen leaves one default; then a stopped default, an address the app refuses
        // (a row saved before the rule), the same printer twice and two of one name are each said.
        $form = fn (PosPeripheral $p, array $over) => $over + ['type' => 'printer', 'name' => $p->name, 'connection' => 'network', 'address' => $p->address, 'port' => $p->port, 'paper' => '80mm', 'is_default' => $p->is_default, 'auto_print' => $p->auto_print, 'is_active' => true];
        $this->actingAs($owner)->put(route('club.settings.pos-devices.peripherals.update', [$device, $second]), $form($second, ['is_default' => true]))->assertSessionHasNoErrors();
        $this->actingAs($owner)->put(route('club.settings.pos-devices.peripherals.update', [$device, $first]), $form($first, ['is_default' => true]))->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame([$first->id], $device->peripherals()->where('is_default', true)->pluck('id')->all()));
        $this->asTenant($club, function () use ($first, $second, $device) {
            $first->forceFill(['is_active' => false])->save();
            $second->forceFill(['auto_print' => false, 'address' => '8.8.8.8'])->save();
            $device->peripherals()->create(['type' => 'printer', 'name' => 'طابعة المكتب', 'connection' => 'network', 'address' => '8.8.8.8', 'port' => null, 'paper' => '80mm', 'is_active' => true]);
        });
        $this->assertSame(['default_stopped', 'no_default', 'bad_address', 'bad_address', 'duplicate'], array_column($codes(), 'code'));

        // A device without a printer while a stopped device of its branch holds one; nothing is moved.
        [$replacement] = $this->device($club, $main);
        $this->asTenant($club, fn () => $device->forceFill(['status' => PosDevice::REVOKED])->save());
        $page = collect($this->actingAs($owner)->get(route('club.settings.pos-devices'))->viewData('page')['props']['devices']);
        $this->assertSame(['printer_on_revoked'], array_column($page->firstWhere('id', $replacement->id)['warnings'], 'code'));
        $this->assertSame([], $page->firstWhere('id', $device->id)['warnings'], 'a stopped device is not assessed');
        $this->asTenant($club, fn () => $this->assertSame(3, $device->peripherals()->count()));
    }

    public function test_the_tills_receipts_show_amounts_only_to_who_may_see_the_clubs_payments(): void
    {
        $club = $this->club();
        $main = $this->main($club);
        [$device, $cookie] = $this->device($club, $main);
        $sale = $this->sale($club, $main, $device);
        $payment = $this->asTenant($club, fn () => $sale->invoice->payments()->firstOrFail());
        $props = fn ($user) => $this->withCookie(PosDevices::COOKIE, $cookie)->actingAs($user)->get(route('club.pos.receipts'))->assertOk()->viewData('page')['props'];

        // A cashier (no invoices.view): the receipts, without their amounts or the day's total — left out by the server.
        $cashier = $this->staff($club, 'cashier', ['branch_id' => $main->id]);
        $seen = $props($cashier);
        $this->assertSame([$payment->receipt_number, null, null, false], [$seen['receipts'][0]['receipt'], $seen['receipts'][0]['amount'], $seen['total'], $seen['can']['amounts']]);
        $this->assertStringNotContainsString('"0.500"', json_encode($seen['receipts']).json_encode($seen['total']));
        // Their work stays: the receipt prints whole (the customer holds it anyway) and the reprint is logged.
        $this->withCookie(PosDevices::COOKIE, $cookie)->actingAs($cashier)->get(route('club.payments.receipt', [$payment, 'face' => 'strip', 'format' => 'pdf']))->assertOk();
        $this->withCookie(PosDevices::COOKIE, $cookie)->actingAs($cashier)->withCredentials()->postJson(route('club.prints.store'), ['document' => 'receipt', 'document_id' => $payment->id, 'trigger' => 'reprint', 'via' => 'native', 'ok' => true, 'printed' => true])->assertOk();
        $this->assertTrue($seen['can']['print']);

        // Reception and the owner see the club's payments, so they see the amounts and the total.
        foreach ([$this->staff($club, 'reception', ['branch_id' => $main->id]), $this->owner($club)] as $user) {
            $seen = $props($user);
            $this->assertSame(['0.500', '0.500', true], [$seen['receipts'][0]['amount'], $seen['total'], $seen['can']['amounts']]);
        }
    }

    public function test_a_device_has_one_default_printer_whatever_saves_at_once(): void
    {
        $club = $this->club();
        $main = $this->main($club);
        [$device] = $this->device($club, $main);
        [$other] = $this->device($club, $main);
        $owner = $this->owner($club);
        $old = $this->printer($club, $device);
        $defaults = fn (PosDevice $d) => $this->asTenant($club, fn () => $d->peripherals()->where('is_default', true)->pluck('id')->all());
        $form = ['type' => 'printer', 'connection' => 'network', 'port' => 9100, 'paper' => '80mm', 'is_active' => true];

        // A new printer saved as default takes the place of the old one in one step (the index never sees two).
        $this->actingAs($owner)->post(route('club.settings.pos-devices.peripherals.store', $device), $form + ['name' => 'طابعة جديدة', 'address' => '192.168.1.61', 'is_default' => true])->assertSessionHasNoErrors();
        $new = $this->asTenant($club, fn () => $device->peripherals()->where('name', 'طابعة جديدة')->firstOrFail());
        $this->assertSame([$new->id], $defaults($device));
        // One saved without it touches no other printer's choice.
        $this->actingAs($owner)->post(route('club.settings.pos-devices.peripherals.store', $device), $form + ['name' => 'احتياطية', 'address' => '192.168.1.62'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->put(route('club.settings.pos-devices.peripherals.update', [$device, $old]), $form + ['name' => $old->name, 'address' => $old->address])->assertSessionHasNoErrors();
        $this->assertSame([$new->id], $defaults($device));
        // Another device of the same branch keeps its own default: the scope is the device.
        $this->actingAs($owner)->post(route('club.settings.pos-devices.peripherals.store', $other), $form + ['name' => 'طابعة الجهاز الثاني', 'address' => '192.168.1.70', 'is_default' => true])->assertSessionHasNoErrors();
        $this->assertCount(1, $defaults($other));
        $this->assertSame([$new->id], $defaults($device));

        // Whatever writes it (two saves at once, a script), the database refuses a second default on one device.
        $this->asTenant($club, function () use ($device) {
            try {
                DB::transaction(fn () => $device->peripherals()->create(['type' => 'printer', 'name' => 'ثانية', 'connection' => 'browser', 'paper' => '80mm', 'is_default' => true, 'is_active' => true]));
                $this->fail('a second default printer was saved');
            } catch (QueryException $e) {
                $this->assertStringContainsString('pos_peripherals_one_default_printer', $e->getMessage());
            }
        });
        $this->assertSame([$new->id], $defaults($device));
    }

    public function test_the_migration_keeps_the_printer_receipts_go_to_and_touches_no_other(): void
    {
        $club = $this->club();
        $main = $this->main($club);
        [$doubled] = $this->device($club, $main);
        [$single] = $this->device($club, $main);
        [$stopped] = $this->device($club, $main);
        $migration = require base_path('app/Modules/Inventory/Database/migrations/2026_11_03_000100_one_default_printer_per_device.php');
        $migration->down();

        // As a database might hold them before the index: two or three defaults on a device.
        [$printing, $rows] = $this->asTenant($club, function () use ($doubled, $single, $stopped) {
            $add = fn (PosDevice $d, string $name, bool $active) => $d->peripherals()->create(['type' => 'printer', 'name' => $name, 'connection' => 'browser', 'paper' => '80mm', 'is_default' => true, 'is_active' => $active])->id;
            $ids = ['off' => $add($doubled, 'موقوفة', false), 'used' => $add($doubled, 'المستعملة', true), 'newer' => $add($doubled, 'الأحدث', true),
                'single' => $add($single, 'وحيدة', true), 'a' => $add($stopped, 'أ', false), 'b' => $add($stopped, 'ب', false)];

            return [$doubled->fresh()->load('peripherals')->printer()->id, $ids];
        });
        $before = $this->asTenant($club, fn () => PosPeripheral::orderBy('id')->get(['id', 'name', 'is_active', 'auto_print', 'paper'])->toArray());
        $migration->up();

        $this->asTenant($club, function () use ($rows, $printing, $doubled, $before) {
            $default = PosPeripheral::where('is_default', true)->orderBy('id')->pluck('id')->all();
            // The printer receipts went to stays the default; a device with one default is not touched at all.
            $this->assertSame([$rows['used'], $rows['single'], $rows['a']], $default);
            $this->assertSame($printing, $doubled->fresh()->load('peripherals')->printer()->id);
            $this->assertSame($before, PosPeripheral::orderBy('id')->get(['id', 'name', 'is_active', 'auto_print', 'paper'])->toArray(), 'nothing else changes');
        });
    }
}
