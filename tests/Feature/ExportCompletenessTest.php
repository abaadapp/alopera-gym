<?php

namespace Tests\Feature;

use App\Core\Backup\TenantBackupService;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Reports\Support\ReportPdf;
use App\Modules\Reports\Support\ReportSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * An exported file holds what the screen shows, all of it: the same filters, every page, the user's branch only, the
 * club's own data only. Read from the file itself (its rows and cells), never from the status of the answer alone.
 */
class ExportCompletenessTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function roleWith(Tenant $club, array $permissions, array $attributes = []): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.uniqid(), 'name' => 'Custom', 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception', $attributes);
        $user->forceFill(['role_id' => $role->id])->save();

        return $user->fresh();
    }

    /** @return list<list<string>> the data rows of a CSV file (BOM, report lines and headings left out) */
    private function csv(TestResponse $response, string $heading): array
    {
        $response->assertOk();
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent());
        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), preg_split('/\r?\n/', trim($text)));
        $at = array_search($heading, array_column($rows, 0), true);
        $this->assertNotFalse($at, 'the file has its headings');

        return array_values(array_filter(array_slice($rows, $at + 1), fn ($r) => $r !== [null] && $r !== ['']));
    }

    /** @return list<string> member numbers on one page of the members list */
    private function listed(User $user, array $filters): array
    {
        $page = $this->actingAs($user)->get(route('club.members.index', $filters))->assertOk()->viewData('page');

        return array_column($page['props']['members']['data'], 'member_no');
    }

    /** @return list<string> member numbers in the exported file, in its order */
    private function exported(User $user, array $filters): array
    {
        return array_column($this->csv($this->actingAs($user)->get(route('club.members.export', $filters + ['format' => 'csv'])), 'رقم العضوية'), 0);
    }

    public function test_the_member_file_holds_exactly_the_filtered_list_across_all_its_pages(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $plan = $this->plan($club);
        $active = [];
        for ($i = 10; $i < 37; $i++) {
            $m = $this->member($club, ['full_name' => 'عضوة نشطة '.$i]);
            $this->activeMembership($club, $m, $plan);
            $active[] = $m->member_no;
        }
        $this->member($club, ['full_name' => 'سلمى الفريدة']);
        $stopped = $this->member($club, ['full_name' => 'عضوة موقوفة']);
        $this->asTenant($club, fn () => $stopped->forceFill(['status' => 'inactive'])->save());
        $this->member($this->club('Other'), ['full_name' => 'سلمى الفريدة']);

        // Membership «active»: 27 members, two pages of 25 on screen, one file with all 27.
        $onScreen = [...$this->listed($owner, ['membership' => 'active']), ...$this->listed($owner, ['membership' => 'active', 'page' => 2])];
        $this->assertCount(27, $onScreen);
        $this->assertEqualsCanonicalizing($active, $onScreen);
        $this->assertSame($onScreen, $this->exported($owner, ['membership' => 'active']));

        // The list's order (by name) is the file's order.
        $this->assertSame($this->listed($owner, ['membership' => 'active', 'sort' => 'name', 'dir' => 'asc']), array_slice($this->exported($owner, ['membership' => 'active', 'sort' => 'name', 'dir' => 'asc']), 0, 25));

        // A search with one answer gives a file of one row: this club's member, never the other club's namesake.
        $one = $this->csv($this->actingAs($owner)->get(route('club.members.export', ['q' => 'سلمى', 'format' => 'csv'])), 'رقم العضوية');
        $this->assertCount(1, $one);
        $this->assertSame('سلمى الفريدة', $one[0][1]);

        // The member's own status.
        $this->assertSame([$stopped->member_no], $this->exported($owner, ['status' => 'inactive']));

        // No filter: every member of the club, 29.
        $this->assertCount(29, $this->exported($owner, []));
    }

    public function test_the_member_file_keeps_to_the_users_branch_and_needs_the_export_permission(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->asTenant($club, fn () => [Branch::orderByDesc('is_main')->orderBy('id')->firstOrFail(), Branch::create(['name' => 'فرع الخوض', 'is_active' => true])]);
        $here = $this->member($club, ['full_name' => 'عضوة الرئيسي', 'branch_id' => $main->id]);
        $this->member($club, ['full_name' => 'عضوة الخوض', 'branch_id' => $khoud->id]);

        $exporter = $this->roleWith($club, ['members.view', 'members.export'], ['branch_id' => $main->id]);
        $this->assertSame([$here->member_no], $this->exported($exporter, []));

        $viewer = $this->roleWith($club, ['members.view']);
        $this->actingAs($viewer)->get(route('club.members.index'))->assertOk();
        $this->actingAs($viewer)->get(route('club.members.export', ['format' => 'csv']))->assertForbidden();
    }

    public function test_the_member_excel_file_opens_and_holds_the_same_rows_in_arabic(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->member($club, ['full_name' => 'سلمى الفريدة']);
        $this->member($club, ['full_name' => '=HYPERLINK("http://x")']);

        $response = $this->actingAs($owner)->get(route('club.members.export', ['q' => 'سلمى', 'format' => 'xlsx']))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray();
        unlink($path);

        $this->assertSame('رقم العضوية', $sheet[0][0]);
        $this->assertCount(2, $sheet, 'headings and the one member the search finds');
        $this->assertSame('سلمى الفريدة', $sheet[1][1]);
    }

    private function product(Tenant $club): Product
    {
        // Not counted in stock: what is sold from which branch's shelf is another test's business.
        return $this->asTenant($club, fn () => Product::create(['name' => 'مياه', 'price' => 1.255, 'track_stock' => false]));
    }

    private function sell(User $user, Product $product, string $customer = 'زبونة', ?int $branch = null): void
    {
        $this->actingAs($user)->post(route('club.pos.checkout'), [
            'items' => [['product_id' => $product->id, 'quantity' => 2]], 'payment_method' => 'cash', 'customer_name' => $customer,
        ] + ($branch ? ['branch_id' => $branch] : []))->assertSessionHasNoErrors();
    }

    public function test_the_sales_file_holds_every_sale_and_refuses_an_excel_file_it_could_not_hold_whole(): void
    {
        config(['gym.exports.xlsx_max_rows' => 3]);
        $club = $this->club();
        $owner = $this->owner($club);
        $water = $this->product($club);
        foreach (['أ', 'ب', 'ج', 'د', '=SUM(A1)'] as $name) {
            $this->sell($owner, $water, $name);
        }

        // CSV: all five, newest first, amounts with three decimals, a formula kept as text.
        $rows = $this->csv($this->actingAs($owner)->get(route('club.sales.export', ['format' => 'csv'])), 'الرقم');
        $this->assertCount(5, $rows);
        $this->assertSame("'=SUM(A1)", $rows[0][2]);
        $this->assertSame('2.510', $rows[0][10]);

        // Excel: more rows than one file may hold → a clear refusal, never a file that only looks whole.
        $refused = $this->actingAs($owner)->getJson(route('club.sales.export', ['format' => 'xlsx']));
        $refused->assertStatus(422);
        $this->assertStringContainsString('CSV', $refused->json('message'));
        $this->assertStringContainsString('5', $refused->json('message'));

        // Narrowed by the search to one sale, Excel gives it.
        $this->actingAs($owner)->get(route('club.sales.export', ['format' => 'xlsx', 'q' => 'ب']))->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_the_sales_file_keeps_to_the_period_the_branch_and_the_club(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->asTenant($club, fn () => [Branch::orderByDesc('is_main')->orderBy('id')->firstOrFail(), Branch::create(['name' => 'فرع الخوض', 'is_active' => true])]);
        $owner = $this->owner($club);
        $water = $this->product($club);

        $this->travelTo(now()->subMonths(2));
        $this->sell($owner, $water, 'قديمة', $main->id);
        $this->travelBack();
        $this->sell($owner, $water, 'الرئيسي', $main->id);
        $this->sell($this->roleWith($club, ['pos.sell'], ['branch_id' => $khoud->id]), $water, 'الخوض');
        $other = $this->club('Other');
        $this->sell($this->owner($other), $this->product($other), 'نادٍ آخر');

        $buyers = fn (User $u, array $q) => array_column($this->csv($this->actingAs($u)->get(route('club.sales.export', $q + ['format' => 'csv'])), 'الرقم'), 2);

        $this->assertEqualsCanonicalizing(['الرئيسي', 'الخوض'], $buyers($owner, ['range' => 'month']));
        $this->assertEqualsCanonicalizing(['الرئيسي', 'الخوض', 'قديمة'], $buyers($owner, []));
        $this->assertSame(['الرئيسي'], $buyers($this->roleWith($club, ['sales.view', 'reports.export'], ['branch_id' => $main->id]), ['range' => 'month']));
        $this->assertSame(['الخوض'], $buyers($owner, ['range' => 'month', 'branch' => $khoud->id]));
        // The file says which branch it covers.
        $this->assertStringContainsString('الفرع,"فرع الخوض"', $this->actingAs($owner)->get(route('club.sales.export', ['range' => 'month', 'branch' => $khoud->id, 'format' => 'csv']))->streamedContent());

        // A period with nothing in it: a file of headings, said to be empty.
        $empty = $this->actingAs($owner)->get(route('club.sales.export', ['format' => 'csv', 'from' => '2020-01-01', 'to' => '2020-01-31']));
        $empty->assertHeader('x-report-empty', '1');
        $this->assertSame([], $this->csv($empty, 'الرقم'));

        // The sales page without the export permission: no file.
        $this->actingAs($this->roleWith($club, ['sales.view']))->get(route('club.sales.export', ['format' => 'csv']))->assertForbidden();
    }

    public function test_a_file_read_in_pieces_holds_each_row_once_even_when_many_share_a_date(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $branch = $this->asTenant($club, fn () => Branch::query()->value('id'));
        $rows = [];
        // 3,001 expenses on one date: seven pieces of 500, all equal in the order the file is written in.
        for ($i = 1; $i <= 3001; $i++) {
            $rows[] = ['tenant_id' => $club->id, 'branch_id' => $branch, 'number' => sprintf('EXP-%05d', $i), 'description' => 'مصروف '.$i, 'amount' => '1.250', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('expenses')->insert($chunk);
        }
        // Rows edited since (their place on disk is no longer their id order), and the plan a larger table gets: a sort
        // per piece rather than an index walk. Rows equal in the order may then come back in another order each time.
        DB::table('expenses')->whereRaw('id % 7 = 0')->update(['payee' => 'مورد']);
        DB::statement('SET enable_indexscan = off');
        DB::statement('SET enable_bitmapscan = off');

        $numbers = array_column($this->csv($this->actingAs($owner)->get(route('club.expenses.export', ['format' => 'csv'])), 'الرقم'), 0);
        $this->assertCount(3001, $numbers);
        $this->assertCount(3001, array_unique($numbers), 'no expense twice, none left out');

        $report = $this->csv($this->actingAs($owner)->get(route('club.reports.export', ['report' => 'finance', 'format' => 'csv', 'range' => 'today'])), 'الرقم');
        $this->assertCount(3001, array_unique(array_column($report, 0)));
        $this->assertCount(3001, $report);
    }

    public function test_a_list_excel_file_keeps_three_decimals_and_keeps_text_as_text(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club, ['full_name' => 'سلمى الفريدة']);
        $this->activeMembership($club, $member, $this->plan($club, ['price' => 25.5]));

        $response = $this->actingAs($owner)->get(route('club.memberships.export', ['format' => 'xlsx']))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $this->assertSame('الإجمالي', $sheet->getCell('I1')->getValue());
        $this->assertSame('25.500', $sheet->getCell('I2')->getFormattedValue(), 'an amount in rials shows its three decimals');
        $this->assertIsNumeric($sheet->getCell('I2')->getValue(), 'and stays a number Excel can add up');
        $this->assertSame('s', $sheet->getCell('C2')->getDataType(), 'a phone number stays text');
        $this->assertSame($member->phone, $sheet->getCell('C2')->getValue());
    }

    private function xlsx(TestResponse $response): Worksheet
    {
        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        return $sheet;
    }

    private function expenses(Tenant $club, array $rows): void
    {
        $branch = $this->asTenant($club, fn () => Branch::query()->value('id'));
        DB::table('expenses')->insert(array_map(fn ($r) => $r + ['tenant_id' => $club->id, 'branch_id' => $branch, 'amount' => '1.250', 'expense_date' => today()->toDateString(),
            'payment_method' => 'cash', 'created_at' => now(), 'updated_at' => now()], $rows));
    }

    private function payrollRun(Tenant $club, int $employees): PayrollRun
    {
        $this->asTenant($club, function () use ($employees) {
            for ($i = 1; $i <= $employees; $i++) {
                Employee::create(['name' => 'موظفة '.$i, 'basic_salary' => '400.500', 'status' => 'active', 'payment_method' => 'cash']);
            }
        });
        $this->actingAs($this->owner($club))->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();

        return $this->asTenant($club, fn () => PayrollRun::firstOrFail());
    }

    public function test_every_export_refuses_an_excel_file_too_long_for_the_server_and_its_csv_holds_everything(): void
    {
        // A dozen files in a row: more than the export rate limit lets one user take in a minute, which is not this test.
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['gym.exports.xlsx_max_rows' => 3]);
        $club = $this->club();
        $owner = $this->owner($club);
        $plan = $this->plan($club);
        for ($i = 1; $i <= 4; $i++) {
            $this->activeMembership($club, $this->member($club, ['full_name' => 'عضوة '.$i]), $plan);
        }
        $this->expenses($club, array_map(fn ($i) => ['number' => 'EXP-'.$i, 'description' => 'مصروف '.$i], range(1, 4)));
        $run = $this->payrollRun($club, 4);

        $cases = [
            'الأعضاء' => [route('club.members.export'), 'رقم العضوية'],
            'العضويات' => [route('club.memberships.export'), 'رقم العضوية'],
            'المصروفات' => [route('club.expenses.export'), 'الرقم'],
            'الرواتب' => [route('club.payroll.export', $run), 'الموظف'],
        ];
        foreach ($cases as $what => [$url, $heading]) {
            $refused = $this->actingAs($owner)->getJson($url.'?format=xlsx');
            $refused->assertStatus(422);
            $this->assertStringContainsString('(4)', $refused->json('message'), $what);
            $this->assertStringContainsString('CSV', $refused->json('message'), $what);
            $this->assertCount(4, $this->csv($this->actingAs($owner)->get($url.'?format=csv'), $heading), $what.': the CSV file holds every row');
        }

        // A report: its rows are counted as the file is built, and building stops at the limit.
        $report = $this->actingAs($owner)->getJson(route('club.reports.export', ['report' => 'members', 'format' => 'xlsx']));
        $report->assertStatus(422);
        $this->assertStringContainsString('أكثر من 3', $report->json('message'));
        $this->actingAs($owner)->get(route('club.reports.export', ['report' => 'members', 'format' => 'csv']))->assertOk();

        // Within the limit Excel is given as before.
        config(['gym.exports.xlsx_max_rows' => 5000]);
        $this->assertSame('رقم العضوية', $this->xlsx($this->actingAs($owner)->get(route('club.memberships.export', ['format' => 'xlsx'])))->getCell('A1')->getValue());
    }

    public function test_excel_cells_follow_their_columns_kind_and_a_formula_stays_text_with_no_mark_to_see(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->expenses($club, [['number' => 'EXP-1', 'description' => '123.456', 'amount' => '12.500', 'reference' => '=1+1', 'payee' => '+96891234567']]);

        $sheet = $this->xlsx($this->actingAs($owner)->get(route('club.expenses.export', ['format' => 'xlsx'])));
        $this->assertSame('12.500', $sheet->getCell('E2')->getFormattedValue(), 'the amount column: rials with three decimals');
        $this->assertSame('n', $sheet->getCell('E2')->getDataType());
        $this->assertSame(['s', '123.456'], [$sheet->getCell('D2')->getDataType(), $sheet->getCell('D2')->getValue()], 'a description that looks like an amount stays as typed');
        $this->assertSame(['s', '+96891234567'], [$sheet->getCell('G2')->getDataType(), $sheet->getCell('G2')->getValue()], 'a phone stays text, its + kept');
        $this->assertSame(['s', '=1+1'], [$sheet->getCell('H2')->getDataType(), $sheet->getCell('H2')->getValue()], 'a formula is text, as typed: no apostrophe to see');
        $this->assertTrue($sheet->getStyle('H2')->getQuotePrefix(), 'and marked so that Excel keeps it text');
        $this->assertFalse($sheet->getStyle('D2')->getQuotePrefix());

        // CSV has no kinds: there the apostrophe is the protection.
        $csv = $this->csv($this->actingAs($owner)->get(route('club.expenses.export', ['format' => 'csv'])), 'الرقم');
        $this->assertSame(["'=1+1", '12.500', '123.456'], [$csv[0][7], $csv[0][4], $csv[0][3]]);

        // A report in Excel: the same.
        $this->activeMembership($club, $this->member($club, ['full_name' => '=HYPERLINK("http://x")']));
        $book = $this->actingAs($owner)->get(route('club.reports.export', ['report' => 'members', 'format' => 'xlsx']))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $book->streamedContent());
        $found = null;
        foreach (IOFactory::load($path)->getAllSheets() as $ws) {
            foreach ($ws->getCellCollection()->getCoordinates() as $at) {
                $cell = $ws->getCell($at);
                $this->assertStringStartsNotWith("'=", (string) $cell->getValue());
                if ($cell->getValue() === '=HYPERLINK("http://x")') {
                    $found = [$cell->getDataType(), $ws->getStyle($at)->getQuotePrefix()];
                }
            }
        }
        unlink($path);
        $this->assertSame(['s', true], $found);
    }

    public function test_exports_and_backup_downloads_are_recorded_without_the_words_searched_for(): void
    {
        Storage::fake('local');
        $club = $this->club();
        $owner = $this->owner($club);
        $this->activeMembership($club, $this->member($club, ['full_name' => 'سلمى الفريدة']));
        $run = $this->payrollRun($club, 1);

        $this->actingAs($owner)->get(route('club.members.export', ['q' => 'سلمى', 'format' => 'csv']))->assertOk();
        $this->actingAs($owner)->get(route('club.memberships.export', ['q' => 'سلمى', 'filter' => 'active', 'format' => 'xlsx']))->assertOk();
        $this->actingAs($owner)->get(route('club.payroll.export', ['run' => $run->id, 'format' => 'csv']))->assertOk();
        $this->actingAs($owner)->post(route('club.backups.store'))->assertSessionHas('success');
        $file = app(TenantBackupService::class)->list($club)[0]['name'];
        $this->actingAs($owner)->get(route('club.backups.download', $file))->assertOk();

        $this->asTenant($club, function () use ($owner, $run, $file) {
            $logs = AuditLog::whereIn('event', ['members.exported', 'memberships.exported', 'payroll.exported', 'backup.downloaded'])->get()->keyBy('event');
            $this->assertSame(['backup.downloaded', 'members.exported', 'memberships.exported', 'payroll.exported'], $logs->keys()->sort()->values()->all());
            foreach ($logs as $log) {
                $this->assertSame([$owner->id, 'user'], [$log->actor_id, $log->actor_type]);
                $this->assertNotNull($log->created_at);
                $this->assertStringNotContainsString('سلمى', json_encode($log->properties, JSON_UNESCAPED_UNICODE), 'never the words searched for');
            }
            $this->assertSame(['format' => 'csv', 'rows' => 1, 'membership' => null, 'status' => null, 'searched' => true], $logs['members.exported']->properties);
            $this->assertSame(['xlsx', 1, 'active', true], [$logs['memberships.exported']->properties['format'], $logs['memberships.exported']->properties['rows'], $logs['memberships.exported']->properties['filter'], $logs['memberships.exported']->properties['searched']]);
            $this->assertSame(['csv', 1, now()->format('Y-m')], [$logs['payroll.exported']->properties['format'], $logs['payroll.exported']->properties['rows'], $logs['payroll.exported']->properties['period']]);
            $this->assertSame($run->id, $logs['payroll.exported']->subject_id);
            $this->assertSame($file, $logs['backup.downloaded']->properties['file']);
        });
    }

    public function test_the_memberships_file_needs_the_export_permission_and_its_old_address_still_gives_it(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->activeMembership($club, $this->member($club));

        $viewer = $this->roleWith($club, ['memberships.view']);
        $this->actingAs($viewer)->get(route('club.memberships.index'))->assertOk();
        $this->actingAs($viewer)->get(route('club.memberships.export', ['format' => 'csv']))->assertForbidden();
        $this->actingAs($viewer)->followingRedirects()->get(route('club.memberships.index', ['format' => 'csv']))->assertForbidden();

        // An older link with ?format= on the list: to the export, its filters kept.
        $this->actingAs($owner)->get(route('club.memberships.index', ['filter' => 'active', 'format' => 'csv']))
            ->assertRedirect(route('club.memberships.export', ['filter' => 'active', 'format' => 'csv']));
        $this->assertCount(1, $this->csv($this->actingAs($owner)->get(route('club.memberships.export', ['filter' => 'active', 'format' => 'csv'])), 'رقم العضوية'));
    }

    public function test_receipts_and_payroll_papers_are_printed_only_with_the_print_permission(): void
    {
        $club = $this->club();
        $membership = $this->activeMembership($club, $this->member($club));
        $payment = $this->asTenant($club, fn () => Payment::where('invoice_id', $membership->invoice_id)->firstOrFail());
        $run = $this->payrollRun($club, 1);

        foreach ([
            [['invoices.view'], route('club.payments.receipt', $payment->id)],
            [['payroll.payslips', 'payroll.salaries'], route('club.papers.pdf', ['type' => 'payroll_sheet', 'id' => $run->id])],
            [['payroll.payslips', 'payroll.salaries'], route('club.payroll.print', $run->id)],
        ] as [$permissions, $url]) {
            $this->actingAs($this->roleWith($club, $permissions))->get($url)->assertForbidden();
            $this->actingAs($this->roleWith($club, [...$permissions, 'papers.print']))->get($url)->assertOk();
        }
    }

    public function test_a_long_wide_pdf_is_handed_to_the_engine_in_parts_it_accepts_and_is_made(): void
    {
        $wide = fn (int $n) => (function () use ($n) {
            for ($i = 1; $i <= $n; $i++) {
                yield ['S-'.$i, '2026-11-17 10:00', 'عضوة رقم '.$i, '96891234567', 'مياه × 2، بروتين × 1', 'نقطة البيع', 'موظفة', 'الفرع الرئيسي', 'نقدًا', '0.500', '12.345', 'مكتملة'];
            }
        })();
        $columns = [['الرقم', ReportSheet::TEXT], ['التاريخ', ReportSheet::TEXT], ['العميل', ReportSheet::TEXT], ['الهاتف', ReportSheet::TEXT], ['المنتجات', ReportSheet::TEXT], ['المصدر', ReportSheet::TEXT],
            ['الموظف', ReportSheet::TEXT], ['الفرع', ReportSheet::TEXT], ['طريقة الدفع', ReportSheet::TEXT], ['الخصم', ReportSheet::MONEY], ['الإجمالي', ReportSheet::MONEY], ['الحالة', ReportSheet::TEXT]];

        // At its limit (3000 rows, twelve columns) the page is over 1 MB of HTML, which the engine refuses in one piece.
        $pdf = new ReportPdf('عمليات البيع', [['الفترة', 'الشهر']], [new ReportSheet('عمليات البيع', $columns, $wide(ReportPdf::MAX_ROWS))], 'OMR', 'Club');
        [, $parts] = (new \ReflectionMethod($pdf, 'parts'))->invoke($pdf);
        $this->assertGreaterThan(1000000, array_sum(array_map('strlen', $parts)));
        $this->assertLessThan(1000000, max(array_map('strlen', $parts)), 'no part as long as the engine refuses');
        $this->assertSame(ReportPdf::MAX_ROWS, substr_count(implode('', $parts), '<tr><td>S-'));

        // A table of several blocks is made into one PDF.
        $bytes = (new ReportPdf('عمليات البيع', [['الفترة', 'الشهر']], [new ReportSheet('عمليات البيع', $columns, $wide(450))], 'OMR', 'Club'))->bytes();
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function test_a_pdf_too_long_for_one_file_says_so_before_its_table(): void
    {
        $rows = (function () {
            for ($i = 1; $i <= ReportPdf::MAX_ROWS + 5; $i++) {
                yield ['صف '.$i, '1.000'];
            }
        })();
        $html = (new ReportPdf('عمليات البيع', [['الفترة', 'الشهر']], [new ReportSheet('عمليات البيع', [['البند', ReportSheet::TEXT], ['المبلغ', ReportSheet::MONEY]], $rows)], 'OMR', 'Club'))->html();

        $note = mb_strpos($html, 'عُرض هنا أول '.ReportPdf::MAX_ROWS.' صف من '.(ReportPdf::MAX_ROWS + 5));
        $this->assertNotFalse($note, 'the PDF says how much of the table it holds');
        $this->assertLessThan(mb_strpos($html, '<table class="data">'), $note, 'and says it before the table, not after 3000 rows');
        $this->assertStringContainsString('CSV', $html);
        $this->assertStringContainsString('صف '.ReportPdf::MAX_ROWS.'<', $html);
        $this->assertStringNotContainsString('صف '.(ReportPdf::MAX_ROWS + 1).'<', $html);
    }
}
